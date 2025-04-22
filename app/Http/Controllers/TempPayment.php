<?php

namespace App\Http\Controllers;

use App\Models\TempPaymentModel;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TempPayment extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function storeTempPayment(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $validated = $request->validate([
            'uid' => 'required|string',
            'user_mail' => 'nullable|string',
            'amount' => 'required|numeric',
            'transaction_id' => 'required|string',
            'device_type' => 'nullable|string|in:Mobile,Browser,TV',
            'payment_type' => 'nullable|string|in:Subscription,PPV',
            'subscription_period' => 'required|integer|min:1',
            'content_id' => 'nullable|string',
            'total_pay' => 'required|numeric',
            'plan' => 'required|string',
        ]);

        try {

            TempPaymentModel::insert([
                'user_id' => $validated['uid'],
                'user_mail' => $validated['user_mail'],
                'amount' => $validated['amount'],
                'transaction_id' => $validated['transaction_id'],
                'device_type' => $validated['device_type'] ?? 'Mobile',
                'payment_type' => $validated['payment_type'] ?? 'Subscription',
                'subscription_period' => $validated['subscription_period'],
                'created_at' => Carbon::now(),
                'content_id' => $validated['content_id'],
                'total_pay' => $validated['total_pay'],
                'plan' => $validated['plan']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Data inserted successfully'
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
}
