<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\RazorpayController;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Kreait\Firebase\Factory;

class QRSessionController extends Controller
{
    private $database;

    protected RazorpayController $razorpayController;
    protected SubscriptionController $subscriptionController;

    public function __construct(RazorpayController $razorpayController, SubscriptionController $subscriptionController)
    {
        $this->razorpayController = $razorpayController;
        $this->subscriptionController = $subscriptionController;

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
            'currency' => $request->currency ?? 'INR',
            'plan_id' => $request->plan_id,
            'app_payment_type' => $request->app_payment_type ?? 'subscription',
            'payment_method' => $request->payment_method,
            'payment_gateway' => $request->payment_gateway ?? 'razorpay',
            'transaction_id' => $request->transaction_id,
            'note' => $request->note ?? 'Kar 1 subscription | Biakdali PPV',
            'type' => $request->type ?? 'login',
            'status' => 'initialized',
            'user_id' => '',
            'expires_at' => time() + 120,
        ];

        $this->database
            ->getReference('qr_sessions/' . $token)
            ->set($data);

        return response()->json([
            'status' => true,
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
                'status' => false,
                'message' => 'Session not found',
            ], 404);
        }

        if (isset($session['expires_at']) && time() > $session['expires_at']) {
            return response()->json([
                'status' => false,
                'message' => 'Session expired',
                'session_status' => 'expired',
            ]);
        }

        return response()->json([
            'status' => true,
            'data' => $session,
        ]);
    }

    public function approve(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'user_id' => 'required',
        ]);

        $ref = $this->database->getReference('qr_sessions/' . $request->token);
        $session = $ref->getValue();

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Session not found',
            ], 404);
        }

        if (isset($session['expires_at']) && time() > $session['expires_at']) {
            return response()->json([
                'status' => false,
                'message' => 'Session expired',
            ], 400);
        }

        $ref->update([
            'status' => 'pending',
            'user_id' => (string) $request->user_id,
        ]);

        $type = $session['type'] ?? 'login';

        //base on $ref type(login, payment) call controller to process login or payment approval
        switch ($type) {

            case 'login':

                break;

            case 'payment':

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
                    $ref->update([
                        'status' => 'failed'
                    ]);
                    
                    return response()->json([
                        'status' => false,
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

                    // update Firebase
                    $ref->update([
                        'status' => 'payment_created',
                        'order_id' => $order['id']
                    ]);

                } else {

                    $ref->update([
                        'status' => 'failed'
                    ]);

                    return response()->json([
                        'status' => false,
                        'message' => 'Subscription creation failed',
                        'error' => $data
                    ], 400);
                }

                break;

            default:

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid QR type',
                ], 400);
        }

        return response()->json([
            'status' => true,
            'message' => 'QR session approved successfully',
        ]);
    }
}