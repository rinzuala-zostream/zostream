<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\TokenController;
use App\Models\New\Devices;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Kreait\Firebase\Factory;
use Log;

class QRSessionController extends Controller
{
    private $database;

    protected $razorpayController;
    protected $subscriptionController;
    private $tokenController;

    public function __construct(RazorpayController $razorpayController, SubscriptionController $subscriptionController, TokenController $tokenController, )
    {
        $this->razorpayController = $razorpayController;
        $this->subscriptionController = $subscriptionController;
        $this->tokenController = $tokenController;

        $databaseUrl = config('firebase.database_url');
        $credentials = config('firebase.credentials');

        if (empty($databaseUrl)) {
            throw new \Exception('FIREBASE_DATABASE_URL is missing in .env');
        }

        if (!file_exists($credentials)) {
            throw new \Exception('Firebase credentials file not found: ' . $credentials);
        }

        $firebase = (new Factory)
            ->withServiceAccount($credentials)
            ->withDatabaseUri($databaseUrl);

        $this->database = $firebase->createDatabase();
    }

    public function create(Request $request)
    {
        $token = Str::random(22);

        $data = [
            'device_id' => $request->device_id,
            'movie_id' => $request->movie_id,
            'device_name' => $request->device_name,
            'device_type' => $request->device_type,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'plan_id' => $request->plan_id,
            'app_payment_type' => $request->app_payment_type,
            'payment_method' => $request->payment_method,
            'payment_gateway' => $request->payment_gateway,
            'transaction_id' => $request->transaction_id,
            'note' => $request->note,
            'type' => $request->type ?? 'login',
            'status' => 'initialized',
            'user_id' => $request->user_id ?? '',
            'expires_at' => time() + 120,
        ];

        $this->database
            ->getReference('qr_sessions/' . $token)
            ->set($data);

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'qr_url' => url('/qr/' . $token),
            'expires_in' => 120,
        ]);
    }

    public function status($token)
    {
        $session = $this->database
            ->getReference('qr_sessions/' . $token)
            ->getValue();

        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session not found',
            ], 404);
        }

        if (isset($session['expires_at']) && time() > $session['expires_at']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session expired',
                'session_status' => 'expired',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $session,
        ]);
    }

    public function verify(Request $request)
    {
        try {

            $request->validate([
                'token' => 'required|string',
                'user_id' => 'required',
            ]);

            $userId = $request->user_id;

            $ref = $this->database->getReference('qr_sessions/' . $request->token);
            $session = $ref->getValue();

            $deviceName = $session['device_name'] ?? 'Unknown Device';
            $deviceId = $session['device_id'] ?? null;
            $deviceType = $session['device_type'] ?? 'mobile';

            if (!$session) {
                $this->updateFirebaseError($ref, 'Token generation failed');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Session not found',
                ], 404);
            }

            if (isset($session['expires_at']) && time() > $session['expires_at']) {
                $this->updateFirebaseError($ref, 'Session expired');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Session expired',
                ], 400);
            }

            $type = $session['type'] ?? 'login';

            //base on $ref type(login, payment) call controller to process login or payment approval
            switch ($type) {

                case 'login':
                    $user = UserModel::where('uid', $userId)->first();
                    if (!$user) {
                        return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
                    }

                    // 🔑 Generate token
                    try {
                        $tokens = $this->tokenController->generateTokens(
                            $userId,
                            $deviceName,
                            $deviceId
                        );
                        if (!$tokens || !isset($tokens['access_token'])) {
                            $this->updateFirebaseError($ref, 'Token generation failed');

                            return response()->json([
                                'status' => 'error',
                                'message' => 'Token generation failed',

                            ], 400);
                        }
                    } catch (\Exception $e) {
                        Log::error('Token generation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);


                        $this->updateFirebaseError($ref, 'Token generation failed');
                        return response()->json(['status' => 'error', 'message' => 'Failed to generate tokens'], 500);
                    }

                    $ref->update([
                        'status' => 'pending',
                        'user_id' => (string) $request->user_id,
                    ]);

                    try {

                        // 🔄 Check subscription and n_devices
                        $subscription = Subscription::where('user_id', $user->uid)
                            ->where('end_at', '>', now())
                            ->where('is_active', true)
                            ->whereHas('plan', function ($query) use ($deviceType) {
                                $query->where('device_type', $deviceType);
                            })
                            ->orderByDesc('id')
                            ->first();

                        $device = Devices::where('user_id', $user->uid)
                            ->where('device_type', $deviceType)
                            ->first();

                        if (!$device) {

                            $device = Devices::create([
                                'user_id' => $user->uid,
                                'subscription_id' => $subscription?->id ?? null,
                                'device_token' => $deviceId,
                                'device_name' => $deviceName,
                                'device_type' => $deviceType,
                                'status' => 'active',
                                'is_owner_device' => true,
                            ]);

                            $isOwnerDevice = true;
                            $message = ucfirst($deviceType) . ' owner device created';
                        }

                        if ($subscription && $deviceId) {

                            $device = Devices::where('user_id', $user->uid)
                                ->where('subscription_id', $subscription->id)
                                ->where('device_token', $deviceId)
                                ->first();

                            if (!$device) {

                                $device = Devices::create([
                                    'user_id' => $user->uid,
                                    'subscription_id' => $subscription->id,
                                    'device_token' => $deviceId,
                                    'device_name' => $deviceName,
                                    'device_type' => $deviceType,
                                    'status' => 'inactive',
                                    'is_owner_device' => false,
                                ]);

                                $isOwnerDevice = false;
                                $message = 'Device created and set as inactive';

                            } elseif ($device->status === 'blocked' && !$device->is_owner_device) {

                                $device->update(['status' => 'inactive']);

                                $isOwnerDevice = $device->is_owner_device;
                                $message = 'Blocked device reset to inactive';

                            } else {

                                $isOwnerDevice = $device->is_owner_device;
                                $message = 'Device already exists with status: ' . $device->status;
                            }

                        } else {

                            $device = Devices::where('user_id', $user->uid)
                                ->where('device_token', $deviceId)
                                ->first();

                            if (!$device) {

                                $device = Devices::create([
                                    'user_id' => $user->uid,
                                    'subscription_id' => $subscription->id ?? null,
                                    'device_token' => $deviceId,
                                    'device_name' => $deviceName,
                                    'device_type' => $deviceType,
                                    'status' => 'inactive',
                                    'is_owner_device' => false,
                                ]);

                                $isOwnerDevice = false;
                                $message = 'Device created and set as inactive without subscription';

                            } else {

                                $isOwnerDevice = $device->is_owner_device;
                                $message = 'Device already exists with status: ' . $device->status . ' without subscription';
                            }
                        }


                        $this->updateFirebaseSuccess($ref, array_merge([
                            'uid' => $userId,
                            'is_owner_device' => $isOwnerDevice ?? false,
                        ], $tokens));

                        return response()->json([
                            'status' => 'success',
                            'message' => $message ?? 'Login successful',
                            'data' => array_merge([
                                'uid' => $userId,
                                'is_owner_device' => $isOwnerDevice ?? false,
                            ], $tokens)
                        ]);

                    } catch (\Throwable $e) {

                        \Log::error('QR Login Device Error', [
                            'user_id' => $userId ?? null,
                            'device_id' => $deviceId ?? null,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        // Update Firebase session status
                        try {
                            $this->updateFirebaseError($ref, 'Token generation failed');
                        } catch (\Throwable $firebaseError) {
                        }

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Something went wrong while processing the device',
                            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
                        ], 500);
                    }

                    break;

                case 'payment':

                    if ($request->user_id !== $session['user_id']) {
                        $this->updateFirebaseError($ref, 'Only same user can approve the payment');
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Only same user can approve the payment',
                        ], 403);
                    }

                    $ref->update([
                        'status' => 'pending',
                        'user_id' => (string) $request->user_id,
                    ]);

                    // Create Razorpay order
                    $fakeRequest = new Request([
                        'amount' => $session['amount'] ?? 0,
                        'currency' => $session['currency'] ?? 'INR',
                        'receipt' => 'qr_' . $request->token,
                        'notes' => [
                            'token' => $request->token
                        ]
                    ]);

                    $razorpayResponse = $this->razorpayController->createOrder($fakeRequest);

                    $razorpayData = $razorpayResponse->getData(true);

                    if (!$razorpayData['ok']) {
                        $this->updateFirebaseError($ref, 'Failed to create Razorpay order');

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Failed to create Razorpay order',
                            'error' => $razorpayData
                        ], 400);
                    }

                    $order = $razorpayData['order'];

                    // Create subscription / PPV record
                    $fakeSubscriptionRequest = new Request([
                        'user_id' => $request->user_id,
                        'plan_id' => $session['plan_id'] ?? null,
                        'movie_id' => $session['movie_id'] ?? null,
                        'app_payment_type' => $session['app_payment_type'] ?? 'subscription',
                        'payment_method' => $session['payment_method'] ?? 'qr',
                        'payment_gateway' => $session['payment_gateway'] ?? 'razorpay',
                        'transaction_id' => $order['id'],
                        'amount' => $session['amount'] ?? 0,
                        'currency' => $session['currency'] ?? 'INR',
                    ]);

                    $response = $this->subscriptionController->store($fakeSubscriptionRequest);

                    // convert Laravel response to array
                    $data = $response->getData(true);

                    if (($data['status'] ?? '') === 'success') {


                        //Need payment approval from mobile
                        // update Firebase
                        $ref->update([
                            'status' => 'payment_started',
                            'order_id' => $order['id']
                        ]);

                    } else {

                        $this->updateFirebaseError($ref, 'Subscription creation failed');

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Subscription creation failed',
                            'error' => $data
                        ], 400);
                    }

                    return response()->json([
                        'status' => 'success',
                        'message' => 'payment initiated, waiting for user approval',
                    ]);

                    break;

                default:

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid QR type',
                    ], 400);
            }

        } catch (\Throwable $e) {

            Log::error('QR Verify Fatal Error', [
                'token' => $request->token ?? null,
                'user_id' => $request->user_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            try {
                if (isset($ref)) {
                    $this->updateFirebaseError($ref, 'Internal server error');
                }
            } catch (\Throwable $firebaseError) {
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function updateFirebaseSuccess($ref, $data)
    {
        try {

            $response = [
                'status' => 'success',
                'data' => $data
            ];

            $ref->update([
                'status' => 'completed',
                'response' => $response,
                'updated_at' => time()
            ]);

        } catch (\Throwable $e) {
            Log::error('Firebase update failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function updateFirebaseError($ref, $message)
    {
        try {

            $response = [
                'status' => 'error',
                'message' => $message
            ];

            $ref->update([
                'status' => 'failed',
                'response' => $response,
                'updated_at' => time()
            ]);

        } catch (\Throwable $e) {
            Log::error('Firebase update failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}