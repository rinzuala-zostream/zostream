<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DesktopCheckoutController extends Controller
{
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
