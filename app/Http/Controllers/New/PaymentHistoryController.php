<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\New\PaymentHistory;
use App\Models\New\Subscription;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class PaymentHistoryController extends Controller
{
    /**
     * Store payment & update subscription safely
     */
    public function store(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|exists:n_subscriptions,id',
            'user_id' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string',
            'transaction_id' => 'nullable|string',
            'status' => 'required|in:pending,success,failed,refunded',
            'payment_type' => 'nullable|in:new,renew,upgrade,downgrade'
        ]);

        DB::beginTransaction();

        try {

            $subscription = Subscription::findOrFail($request->subscription_id);

            $expiryDate = null;

            if ($request->status === 'success') {

                $planDuration = $subscription->duration_days;

                // Extend from current expiry if still active
                $currentExpiry = $subscription->expiry_date;

                if ($currentExpiry && Carbon::parse($currentExpiry)->isFuture()) {
                    $expiryDate = Carbon::parse($currentExpiry)
                        ->addDays($planDuration);
                } else {
                    $expiryDate = Carbon::now()
                        ->addDays($planDuration);
                }

                // Update subscription expiry
                $subscription->expiry_date = $expiryDate;
                $subscription->status = 'active';
                $subscription->save();
            }

            $payment = PaymentHistory::create([
                'subscription_id' => $request->subscription_id,
                'user_id' => $request->user_id,
                'amount' => $request->amount,
                'currency' => 'INR',
                'payment_method' => $request->payment_method,
                'transaction_id' => $request->transaction_id,
                'status' => $request->status,
                'payment_type' => $request->payment_type ?? 'renew',
                'payment_date' => now(),
                'expiry_date' => $expiryDate,
                'meta' => $request->meta ?? null,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded successfully.',
                'data' => $payment
            ]);

        } catch (Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Payment processing failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get payment history by user
     */
    public function getByUser($userId)
    {
        $payments = PaymentHistory::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $payments
        ]);
    }
}