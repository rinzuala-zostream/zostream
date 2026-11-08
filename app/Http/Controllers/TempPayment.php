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
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid API key'
            ], 401);
        }

        try {
            $validated = $request->validate([
                'uid' => 'required|string',
                'user_mail' => 'nullable|string',
                'amount' => 'required|numeric',
                'transaction_id' => 'required|string',
                'device_type' => 'required|string',
                'payment_type' => 'nullable|string|in:Subscription,PPV',
                'subscription_period' => 'required|integer|min:1',
                'pg' => 'required|string',
                'content_id' => 'nullable|string',
                'total_pay' => 'required|numeric',
                'plan' => 'required|string',
            ]);

            TempPaymentModel::insert([
                'user_id' => $validated['uid'],
                'user_mail' => $validated['user_mail'] ?? null,
                'amount' => $validated['amount'],
                'transaction_id' => $validated['transaction_id'],
                'device_type' => $validated['device_type'],
                'payment_type' => $validated['payment_type'] ?? 'Subscription',
                'subscription_period' => $validated['subscription_period'],
                'pg' => $validated['pg'],
                'created_at' => now(),
                'content_id' => $validated['content_id'] ?? null,
                'total_pay' => $validated['total_pay'],
                'plan' => $validated['plan'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Data inserted successfully'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database error occurred',
                'error_detail' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unexpected error occurred',
                'error_detail' => $e->getMessage()
            ], 500);
        }
    }
}
