<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Concerns\ResolvesLoginDevices;
use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\TokenController;
use App\Models\New\Devices;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Kreait\Firebase\Factory;
use Log;

class QRSessionController extends Controller
{
    use ResolvesLoginDevices;

    private $database;

    protected $razorpayController;
    protected $subscriptionController;
    private $tokenController;

    public function __construct(
        RazorpayController $razorpayController, 
        SubscriptionController $subscriptionController, 
        TokenController $tokenController, 
        )
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
        $type = $request->type ?? 'login';

        $data = [
            'device_id' => $request->device_id,
            'device_token' => $request->device_token,
            'movie_id' => $request->movie_id,
            'movie_name' => $request->movie_name,
            'device_name' => $request->device_name,
            'device_type' => $request->device_type,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'plan_id' => $request->plan_id,
            'subscription_id' => $request->subscription_id,
            'app_payment_type' => $request->app_payment_type,
            'payment_method' => $request->payment_method,
            'payment_gateway' => $request->payment_gateway,
            'transaction_id' => $request->transaction_id,
            'note' => $request->note,
            'type' => $type,
            'content_type' => $request->content_type,
            'status' => 'initialized',
            'user_id' => $request->user_id ?? '',
            'expires_at' => time() + 120,
        ];

        if ($type === 'admin_login') {
            $allowedUserIds = $this->getAdminQrAllowedUserIds();

            if (empty($allowedUserIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admin QR login is not configured',
                ], 500);
            }

            $data['admin_only'] = true;
            $data['allowed_user_ids'] = $allowedUserIds;
        }

        $this->database
            ->getReference('qr_sessions/' . $token)
            ->set($data);

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'qr_url' => url('/api/v3.0/qr/status/' . $token),
            'expires_in' => 120,
        ]);
    }

    public function createAdmin(Request $request)
    {
        $request->merge([
            'type' => 'admin_login',
            'device_type' => $request->device_type ?? 'browser',
        ]);

        return $this->create($request);
    }

    public function status($token)
    {
        $userId = request()->query('user_id');

        $ref = $this->database->getReference('qr_sessions/' . $token);
        $session = $ref->getValue();

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

        // ✅ SKIP check if type = login
        if (
            !in_array(($session['type'] ?? null), ['login', 'admin_login'], true) &&
            $userId &&
            isset($session['user_id']) &&
            $session['user_id'] !== $userId
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session belongs to another user',
                'session_status' => $session['status'] ?? 'unknown',
            ]);
        }

        // ✅ Update status
        $ref->update([
            'status' => 'pending',
        ]);

        $session['status'] = 'pending';

        return response()->json([
            'status' => 'success',
            'message' => 'Session found',
            'data' => $session,
        ]);
    }

    public function verify(Request $request)
    {
        try {

            $request->validate([
                'token' => 'required|string',
                'user_id' => 'required',
                'device_name' => 'nullable|string',
                'device_id' => 'nullable|string',
                'device_token' => 'nullable|string',
                'device_type' => 'nullable|string',
                'fcm_token' => 'nullable|string',
            ]);

            $userId = $request->user_id;

            $ref = $this->database->getReference('qr_sessions/' . $request->token);
            $session = $ref->getValue();

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

            $deviceName = $request->device_name ?? $session['device_name'] ?? 'Unknown Device';
            $deviceId = $request->device_id ?? $request->device_token ?? $session['device_id'] ?? $session['device_token'] ?? null;
            $deviceType = $this->normalizeLoginDeviceType($request->device_type ?? $session['device_type'] ?? 'mobile');
            $fcmToken = $request->fcm_token;
            $type = $session['type'] ?? 'login';

            //base on $ref type(login, payment) call controller to process login or payment approval
            switch ($type) {

                case 'admin_login':
                    if (!$this->isAdminQrAllowed($userId)) {
                        $this->updateFirebaseError($ref, 'Admin access denied');

                        return response()->json([
                            'status' => 'error',
                            'message' => 'This account is not allowed to login to admin',
                        ], 403);
                    }

                    // Admin login uses the same token/device flow after access is confirmed.
                case 'login':
                    $user = UserModel::where('uid', $userId)->first();
                    if (!$user) {
                        return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
                    }

                    $this->syncUserDeviceInfo($user, $deviceId, $deviceName, $fcmToken);

                    // 🔑 Generate token
                    try {
                        $tokens = $this->tokenController->generateTokens(
                            $userId,
                            $deviceName,
                            $deviceId
                        );
                        if (
                            !$tokens ||
                            empty($tokens['access_token']) ||
                            empty($tokens['refresh_token'])
                        ) {
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
                        'user_id' => (string) $request->user_id,
                    ]);

                    try {

                        $subscription = Subscription::where('user_id', $user->uid)
                            ->where('end_at', '>', now())
                            ->where('is_active', true)
                            ->whereHas('plan', function ($query) use ($deviceType) {
                                $query->where('device_type', $deviceType);
                            })
                            ->orderByDesc('id')
                            ->first();

                        $deviceResult = $this->resolveLoginDevice($user, $subscription, $deviceId, $deviceName, $deviceType);
                        $isOwnerDevice = $deviceResult['is_owner_device'];
                        $message = $deviceResult['message'];

                        if ($fcmToken) {
                            UserModel::where('uid', $user->uid)->update(['token' => $fcmToken]);
                        }


                        $this->updateFirebaseSuccess($ref, array_merge([
                            'uid' => $userId,
                            'is_owner_device' => $isOwnerDevice ?? false,
                        ], $tokens));

                        return response()->json([
                            'status' => 'success',
                            'message' => $message ?? 'Login successful',
                            'type' => $type,
                            'data' => array_merge([
                                'uid' => $userId,
                                'is_owner_device' => $isOwnerDevice ?? false,
                            ], $tokens)
                        ]);

                    } catch (\Throwable $e) {

                        Log::error('QR Login Device Error', [
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
                        'device_id' => $session['device_id'] ?? $session['device_token'] ?? null,
                        'device_token' => $session['device_token'] ?? $session['device_id'] ?? null,
                        'device_type' => $session['device_type'] ?? 'mobile',
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
                        'message' => $order['id'],
                        'type' => 'payment',
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

    public function inspect(Request $request, $token)
    {
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9]{22}$/', $token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid QR token',
            ], 400);
        }

        $userId = (string) $request->input('auth_user_id', '');
        if ($userId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user is required',
            ], 401);
        }

        $ref = $this->database->getReference('qr_sessions/' . $token);
        $session = $ref->getValue();
        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR session not found',
            ], 404);
        }

        if (
            ($session['type'] ?? '') === 'payment' &&
            (string) ($session['user_id'] ?? '') !== $userId
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'This QR belongs to another account',
            ], 403);
        }

        $paymentStillInProgress =
            ($session['status'] ?? '') === 'payment_started' &&
            isset($session['payment_expires_at']) &&
            time() <= (int) $session['payment_expires_at'];
        $sessionFinished = in_array(
            ($session['status'] ?? ''),
            ['completed', 'success'],
            true
        );

        if (
            isset($session['expires_at']) &&
            time() > (int) $session['expires_at'] &&
            !$paymentStillInProgress &&
            !$sessionFinished
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR session expired',
                'session_status' => 'expired',
            ], 400);
        }

        if (($session['status'] ?? 'initialized') === 'initialized') {
            $ref->update([
                'status' => 'pending',
                'updated_at' => time(),
            ]);
            $session['status'] = 'pending';
        }

        return response()->json([
            'status' => 'success',
            'message' => 'QR session found',
            'data' => $session,
        ]);
    }

    public function startSubscriptionPayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'token' => 'required|string|size:22',
                'plan_id' => 'required|integer|exists:n_plans,id',
                'currency' => 'nullable|string|size:3',
            ]);

            $userId = (string) $request->input('auth_user_id', '');
            if ($userId === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Authenticated user is required',
                ], 401);
            }

            $ref = $this->database->getReference('qr_sessions/' . $validated['token']);
            $session = $ref->getValue();
            $sessionError = $this->validateSubscriptionPaymentSession($session, $userId, true);
            if ($sessionError) {
                return $sessionError;
            }

            if (in_array(($session['status'] ?? ''), ['completed', 'success'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This QR payment is already completed',
                ], 409);
            }

            $plan = $this->resolveQrSubscriptionPlan($validated['plan_id'], $session);
            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected plan is not available for this QR device',
                ], 422);
            }

            if (!$this->resolveQrTargetDevice($userId, $plan, $session)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The device from this QR is not registered on your account',
                ], 422);
            }

            if (
                ($session['status'] ?? '') === 'payment_started' &&
                (int) ($session['plan_id'] ?? 0) === (int) $plan->id &&
                isset($session['payment_expires_at']) &&
                time() <= (int) $session['payment_expires_at'] &&
                !empty($session['checkout'])
            ) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Razorpay order is ready',
                    'data' => $session['checkout'],
                ]);
            }

            $orderRequest = new Request([
                'auth_user_id' => $userId,
                'plan_id' => $plan->id,
                'currency' => strtoupper($validated['currency'] ?? 'INR'),
            ]);
            $orderRequest->headers->set('X-RZ-Env', $request->header('X-RZ-Env', ''));
            $orderResponse = app(PaymentController::class)
                ->createRazorpaySubscriptionOrder($orderRequest);
            $orderData = $orderResponse->getData(true);

            if (!$orderResponse->isSuccessful() || ($orderData['status'] ?? '') !== 'success') {
                return $orderResponse;
            }

            $orderId = $orderData['data']['order']['id'] ?? null;
            if (!$orderId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Razorpay order was not returned',
                ], 500);
            }

            $ref->update([
                'status' => 'payment_started',
                'plan_id' => $plan->id,
                'amount' => (float) $plan->price,
                'currency' => strtoupper($validated['currency'] ?? 'INR'),
                'order_id' => $orderId,
                'checkout' => $orderData['data'],
                'payment_expires_at' => time() + 900,
                'updated_at' => time(),
            ]);

            return $orderResponse;
        } catch (\Throwable $e) {
            Log::error('QR subscription payment start failed', [
                'token' => $request->token ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to start subscription payment',
            ], 500);
        }
    }

    public function completeSubscriptionPayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'token' => 'required|string|size:22',
                'plan_id' => 'required|integer|exists:n_plans,id',
                'razorpay_order_id' => 'required|string|max:255',
                'razorpay_payment_id' => 'required|string|max:255',
                'razorpay_signature' => 'required|string|max:255',
                'currency' => 'nullable|string|size:3',
            ]);

            $userId = (string) $request->input('auth_user_id', '');
            if ($userId === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Authenticated user is required',
                ], 401);
            }

            $ref = $this->database->getReference('qr_sessions/' . $validated['token']);
            $session = $ref->getValue();
            $sessionError = $this->validateSubscriptionPaymentSession($session, $userId, false);
            if ($sessionError) {
                return $sessionError;
            }

            if (
                (string) ($session['order_id'] ?? '') !== $validated['razorpay_order_id'] ||
                (int) ($session['plan_id'] ?? 0) !== (int) $validated['plan_id']
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment does not match this QR session',
                ], 403);
            }

            if (isset($session['payment_expires_at']) && time() > (int) $session['payment_expires_at']) {
                $this->updateFirebaseError($ref, 'Payment session expired');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment session expired',
                ], 400);
            }

            $plan = $this->resolveQrSubscriptionPlan($validated['plan_id'], $session);
            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected plan is not available for this QR device',
                ], 422);
            }

            $verifyRequest = new Request(array_merge($validated, [
                'auth_user_id' => $userId,
                'target_device_token' => $session['device_id'] ?? $session['device_token'] ?? null,
            ]));
            $verifyRequest->headers->set('X-RZ-Env', $request->header('X-RZ-Env', ''));
            $verifyResponse = app(PaymentController::class)
                ->verifyRazorpaySubscriptionPayment($verifyRequest);
            $verifyData = $verifyResponse->getData(true);

            if (!$verifyResponse->isSuccessful() || ($verifyData['status'] ?? '') !== 'success') {
                return $verifyResponse;
            }

            $this->updateFirebaseSuccess($ref, [
                'uid' => $userId,
                'plan_id' => $plan->id,
                'order_id' => $validated['razorpay_order_id'],
                'payment_id' => $validated['razorpay_payment_id'],
                'subscription' => $verifyData['data'] ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified and subscription activated',
                'type' => 'payment',
                'data' => $verifyData['data'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('QR subscription payment completion failed', [
                'token' => $request->token ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to complete subscription payment',
            ], 500);
        }
    }

    private function validateSubscriptionPaymentSession($session, string $userId, bool $checkQrExpiry)
    {
        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR session not found',
            ], 404);
        }

        $paymentType = strtolower(trim((string) ($session['app_payment_type'] ?? 'subscription')));
        if (($session['type'] ?? '') !== 'payment' || $paymentType !== 'subscription') {
            return response()->json([
                'status' => 'error',
                'message' => 'This QR is not a subscription payment request',
            ], 422);
        }

        if ((string) ($session['user_id'] ?? '') !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'This QR belongs to another account',
            ], 403);
        }

        $paymentStillInProgress =
            ($session['status'] ?? '') === 'payment_started' &&
            isset($session['payment_expires_at']) &&
            time() <= (int) $session['payment_expires_at'];

        if (
            $checkQrExpiry &&
            isset($session['expires_at']) &&
            time() > (int) $session['expires_at'] &&
            !$paymentStillInProgress
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR session expired',
            ], 400);
        }

        return null;
    }

    private function resolveQrSubscriptionPlan($planId, array $session)
    {
        $fixedPlanId = (int) ($session['plan_id'] ?? 0);
        if ($fixedPlanId > 0 && $fixedPlanId !== (int) $planId) {
            return null;
        }

        $deviceType = $this->normalizeLoginDeviceType($session['device_type'] ?? 'browser');

        return Plan::where('id', $planId)
            ->where('is_active', true)
            ->where('device_type', $deviceType)
            ->first();
    }

    private function resolveQrTargetDevice(string $userId, Plan $plan, array $session)
    {
        $deviceToken = $session['device_id'] ?? $session['device_token'] ?? null;
        if (!$deviceToken) {
            return null;
        }

        return Devices::where('user_id', $userId)
            ->where('device_type', $plan->device_type)
            ->where('device_token', $deviceToken)
            ->first();
    }

    private function getAdminQrAllowedUserIds()
    {
        return config('services.admin_qr.allowed_uids', []);
    }

    private function isAdminQrAllowed($userId)
    {
        return in_array((string) $userId, $this->getAdminQrAllowedUserIds(), true);
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

    private function syncUserDeviceInfo(UserModel $user, ?string $deviceId, ?string $deviceName, ?string $fcmToken): void
    {
        $updates = [];

        if ($deviceId && $user->device_id !== $deviceId) {
            $updates['device_id'] = $deviceId;
        }

        if ($deviceName && $deviceName !== 'Unknown Device' && $user->device_name !== $deviceName) {
            $updates['device_name'] = $deviceName;
        }

        if ($fcmToken && $user->token !== $fcmToken) {
            $updates['token'] = $fcmToken;
        }

        if (!empty($updates)) {
            $user->update($updates);
        }
    }
}
