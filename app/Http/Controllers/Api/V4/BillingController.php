<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\New\PaymentController;
use App\Http\Controllers\New\SubscriptionController;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionController $subscriptions,
        private readonly PaymentController $payments,
    ) {}

    public function createSubscription(Request $request)
    {
        $this->forceUser($request);

        return $this->subscriptions->store($request);
    }

    public function createSubscriptionWithPayment(Request $request)
    {
        $this->forceUser($request);

        return $this->subscriptions->createSubscriptionWithPayment($request);
    }

    public function processPayments(Request $request)
    {
        $request->query->set('user_id', $this->userId($request));
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->payments->processUserPayments($request);
    }

    public function createRazorpayOrder(Request $request)
    {
        return $this->payments->createRazorpaySubscriptionOrder($request);
    }

    public function verifyRazorpayPayment(Request $request)
    {
        return $this->payments->verifyRazorpaySubscriptionPayment($request);
    }

    public function processAppleSubscription(Request $request)
    {
        $this->forceUser($request);

        return $this->payments->processAppleIapSubscription($request);
    }

    private function forceUser(Request $request): void
    {
        $request->merge(['user_id' => $this->userId($request)]);
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }
}
