<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DesktopCheckoutController extends Controller
{
    public function qr(Request $request)
    {
        // Public transport page, just like hosted checkout. No secret or access
        // token is accepted here; only the owner-only verify API grants access.
        $data = $request->validate([
            'key_id' => ['required', 'string', 'regex:/^rzp_(test|live)_[A-Za-z0-9]+$/', 'max:128'],
            'order_id' => ['required', 'string', 'regex:/^order_[A-Za-z0-9]+$/', 'max:128'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'currency' => ['required', 'in:INR'],
        ]);
        $nonce = base64_encode(random_bytes(18));
        $options = [
            'key' => $data['key_id'], 'order_id' => $data['order_id'],
            'amount' => (int) $data['amount'], 'currency' => $data['currency'],
            'name' => 'Zo Stream', 'description' => 'Scan and pay with any UPI app',
            'config' => ['display' => [
                'blocks' => ['upi_qr' => [
                    'name' => 'Scan & pay with UPI',
                    'instruments' => [['method' => 'upi', 'flows' => ['qr']]],
                ]],
                'sequence' => ['block.upi_qr'],
                'preferences' => ['show_default_blocks' => false],
            ]],
        ];

        return response()->view('payments.desktop-upi-qr', [
            'options' => $options, 'nonce' => $nonce,
            'returnUrl' => url('/api/v4/billing/payments/razorpay/desktop-return'),
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}' https://checkout.razorpay.com; style-src 'unsafe-inline'; img-src data: https:; connect-src 'self' https://*.razorpay.com https://razorpay.com; frame-src https://*.razorpay.com; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    }

    // Transport only. This public page never verifies or activates a purchase.
    // The signed-in native client submits these fields to the owner-only verify API.
    public function result(Request $request)
    {
        $data = $request->validate([
            'razorpay_order_id' => ['nullable', 'string', 'max:255'],
            'razorpay_payment_id' => ['nullable', 'string', 'max:255'],
            'razorpay_signature' => ['nullable', 'string', 'max:255'],
        ]);
        $payload = $request->isMethod('get')
            ? ['status' => 'cancelled']
            : array_merge(['status' => count(array_filter($data)) === 3 ? 'returned' : 'failed'], $data);
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $nonce = base64_encode(random_bytes(18));

        return response('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Zo Stream payment</title><body><p>Return to Zo Stream to confirm your payment.</p>'
            .'<script nonce="'.$nonce.'">window.webkit?.messageHandlers?.ZoStreamPayment?.postMessage('.$json.');</script></body></html>')
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
    }
}
