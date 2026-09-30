<?php

namespace Tests\Feature;

use App\Http\Controllers\CashFreeController;
use App\Http\Controllers\New\PaymentController as ZoStreamPaymentController;
use App\Isp\Models\PaymentCheckout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IspCashfreeWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cashfree.env', 'SANDBOX');
        config()->set('cashfree.sandbox_client_secret', 'cashfree-webhook-secret');

        Schema::create('isp_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role')->default('admin');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('routers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('operator_percentage', 5, 2)->default(20);
            $table->decimal('ott_deduction', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('username');
            $table->text('password');
            $table->string('status')->default('active');
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->decimal('package_amount', 12, 2)->nullable();
            $table->decimal('ott_deduction', 12, 2)->default(0);
            $table->decimal('distributable_amount', 12, 2)->nullable();
            $table->decimal('operator_percentage', 5, 2)->default(0);
            $table->decimal('operator_commission', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->string('method');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('external_order_id')->unique();
            $table->string('gateway')->default('cashfree');
            $table->string('razorpay_key_id')->nullable();
            $table->text('payment_session_id')->nullable();
            $table->decimal('package_amount', 12, 2)->nullable();
            $table->decimal('ott_deduction', 12, 2)->default(0);
            $table->decimal('distributable_amount', 12, 2)->nullable();
            $table->decimal('operator_percentage', 5, 2)->default(0);
            $table->decimal('operator_commission', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('pending');
            $table->string('razorpay_payment_id')->nullable();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->text('razorpay_signature')->nullable();
            $table->boolean('renew')->default(false);
            $table->text('notes')->nullable();
            $table->json('external_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        DB::table('isp_users')->insert(['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret']);
        DB::table('routers')->insert(['id' => 1, 'name' => 'Router']);
        DB::table('branches')->insert(['id' => 1, 'name' => 'Branch']);
        DB::table('packages')->insert(['id' => 1, 'name' => 'Starter', 'price' => 499]);
        DB::table('customers')->insert([
            'id' => 1,
            'router_id' => 1,
            'package_id' => 1,
            'branch_id' => 1,
            'name' => 'Customer',
            'phone' => '9876543210',
            'username' => 'customer-1',
            'password' => 'secret',
        ]);
        PaymentCheckout::create([
            'user_id' => 1,
            'customer_id' => 1,
            'package_id' => 1,
            'external_order_id' => 'isp_cashfree_webhook_1',
            'gateway' => 'cashfree',
            'payment_session_id' => 'session_1',
            'package_amount' => 499,
            'ott_deduction' => 0,
            'distributable_amount' => 499,
            'operator_percentage' => 20,
            'operator_commission' => 99.8,
            'amount' => 399.2,
            'currency' => 'INR',
            'renew' => false,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['payment_checkouts', 'payments', 'customers', 'packages', 'branches', 'routers', 'isp_users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_signed_success_webhook_is_idempotent(): void
    {
        $cashfree = $this->mock(CashFreeController::class);
        $cashfree->shouldReceive('checkPayment')->twice()->andReturn(response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'order_id' => 'isp_cashfree_webhook_1',
                    'order_amount' => 399.2,
                    'order_currency' => 'INR',
                ],
                'payments' => [[
                    'cf_payment_id' => '123456789',
                    'payment_status' => 'SUCCESS',
                ]],
            ],
        ]));
        $zostream = $this->mock(ZoStreamPaymentController::class);
        $zostream->shouldReceive('processExternalOrderPayments')
            ->twice()
            ->with('isp_cashfree_webhook_1', 'cashfree')
            ->andReturn([]);

        $first = $this->sendSignedWebhook();
        $first->assertOk()->assertJsonPath('already_processed', false);
        $this->sendSignedWebhook()->assertOk()->assertJsonPath('already_processed', true);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payment_checkouts', [
            'external_order_id' => 'isp_cashfree_webhook_1',
            'status' => 'paid',
            'gateway_payment_id' => '123456789',
        ]);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $payload = $this->successPayload();

        $this->call('POST', '/api/isp/payments/cashfree/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => '1700000000',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid',
        ], $payload)->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_signed_failed_webhook_records_the_failed_attempt(): void
    {
        $payload = json_encode([
            'type' => 'PAYMENT_FAILED_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'isp_cashfree_webhook_1'],
                'payment' => ['cf_payment_id' => 'failed-123', 'payment_status' => 'FAILED'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->sendSignedPayload($payload)
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertDatabaseHas('payment_checkouts', [
            'external_order_id' => 'isp_cashfree_webhook_1',
            'status' => 'failed',
            'gateway_payment_id' => 'failed-123',
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_signed_webhook_for_a_non_isp_order_is_ignored(): void
    {
        $payload = json_encode([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'cashfree-dashboard-test-order'],
                'payment' => ['cf_payment_id' => 'test-123', 'payment_status' => 'SUCCESS'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->sendSignedPayload($payload)
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertDatabaseCount('payments', 0);
    }

    private function sendSignedWebhook()
    {
        return $this->sendSignedPayload($this->successPayload());
    }

    private function sendSignedPayload(string $payload)
    {
        $timestamp = '1700000000';
        $signature = base64_encode(hash_hmac(
            'sha256',
            $timestamp.$payload,
            'cashfree-webhook-secret',
            true,
        ));

        return $this->call('POST', '/api/isp/payments/cashfree/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $payload);
    }

    private function successPayload(): string
    {
        return json_encode([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'isp_cashfree_webhook_1'],
                'payment' => ['cf_payment_id' => '123456789', 'payment_status' => 'SUCCESS'],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
