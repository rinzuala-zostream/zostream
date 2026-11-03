<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesktopCheckoutReturnTest extends TestCase
{
    private const PATH = '/api/v4/billing/payments/razorpay/desktop-return';

    public function test_qr_checkout_transports_only_public_order_fields_and_configures_qr_only(): void
    {
        $response = $this->post('/api/v4/billing/payments/razorpay/desktop-qr', [
            'key_id' => 'rzp_test_fixture', 'order_id' => 'order_fixture',
            'amount' => 14900, 'currency' => 'INR', 'access_token' => 'must-not-reflect',
            'callback_url' => 'https://evil.invalid/return',
        ]);
        $response->assertOk()->assertViewIs('payments.desktop-upi-qr')
            ->assertViewHas('options', function ($options) {
                $display = $options['config']['display'];
                return $options['order_id'] === 'order_fixture' && $options['amount'] === 14900
                    && $display['blocks']['upi_qr']['instruments'] === [['method' => 'upi', 'flows' => ['qr']]]
                    && $display['preferences']['show_default_blocks'] === false
                    && $display['sequence'] === ['block.upi_qr'];
            })
            ->assertViewHas('returnUrl', url(self::PATH))
            ->assertSee('https://checkout.razorpay.com/v1/checkout.js', false)
            ->assertDontSee('must-not-reflect')->assertDontSee('evil.invalid')
            ->assertDontSee('Payment successful');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_qr_checkout_rejects_invalid_order_metadata(): void
    {
        $valid = ['key_id' => 'rzp_live_fixture', 'order_id' => 'order_fixture', 'amount' => 14900, 'currency' => 'INR'];
        foreach (['key_id' => '</script>', 'order_id' => 'invalid', 'amount' => 0, 'currency' => 'USD'] as $field => $value) {
            $this->postJson('/api/v4/billing/payments/razorpay/desktop-qr', array_replace($valid, [$field => $value]))
                ->assertUnprocessable();
        }
    }

    public function test_public_return_transports_result_without_claiming_payment_success(): void
    {
        $response = $this->post(self::PATH, [
            'razorpay_order_id' => 'order_fixture', 'razorpay_payment_id' => 'pay_fixture',
            'razorpay_signature' => str_repeat('a', 64), 'access_token' => 'must-not-reflect',
        ]);
        $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('"status":"returned"', false)->assertSee('ZoStreamPayment', false);
        $response->assertDontSee('must-not-reflect')->assertDontSee('Payment successful');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_cancel_and_failure_return_distinct_states(): void
    {
        $this->get(self::PATH)->assertOk()->assertSee('"status":"cancelled"', false);
        $this->post(self::PATH, ['error' => ['description' => 'Rejected']])
            ->assertOk()->assertSee('"status":"failed"', false);
    }

    public function test_untrusted_fields_cannot_break_out_of_the_script(): void
    {
        $this->post(self::PATH, ['razorpay_order_id' => '</script><script>alert(1)</script>',
            'razorpay_payment_id' => 'pay_fixture', 'razorpay_signature' => str_repeat('a', 64)])
            ->assertOk()->assertDontSee('</script><script>alert(1)</script>', false)
            ->assertSee('\\u003C', false);
    }

    public function test_return_rejects_arrays_and_oversized_fields(): void
    {
        $this->postJson(self::PATH, ['razorpay_signature' => ['unexpected']])->assertUnprocessable();
        $this->postJson(self::PATH, ['razorpay_order_id' => str_repeat('x', 256)])->assertUnprocessable();
    }
}
