<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesktopCheckoutReturnTest extends TestCase
{
    private const PATH = '/api/v4/billing/payments/razorpay/desktop-return';

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
