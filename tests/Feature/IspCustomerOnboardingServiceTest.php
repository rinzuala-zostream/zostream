<?php

namespace Tests\Feature;

use App\Http\Controllers\CashFreeController;
use App\Isp\Models\CustomerOnboarding;
use App\Isp\Services\CustomerOnboardingService;
use App\Isp\Services\RadiusService;
use App\Isp\Services\ZoStreamSubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspCustomerOnboardingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('router_device_condition')->nullable();
            $table->string('aadhaar_front_path')->nullable();
            $table->string('aadhaar_back_path')->nullable();
            $table->timestamp('aadhaar_qr_verified_at')->nullable();
            $table->string('username');
            $table->text('password');
            $table->string('status');
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_router_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('router_condition');
            $table->decimal('amount', 12, 2);
            $table->string('status');
            $table->string('method')->nullable();
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->longText('customer_payload');
            $table->string('username')->unique();
            $table->string('aadhaar_front_path')->nullable();
            $table->string('aadhaar_back_path')->nullable();
            $table->timestamp('aadhaar_qr_verified_at')->nullable();
            $table->decimal('router_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->string('cashfree_order_id')->nullable()->unique();
            $table->text('payment_session_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->text('activation_error')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('customer_onboardings');
        Schema::dropIfExists('customer_router_payments');
        Schema::dropIfExists('customers');

        parent::tearDown();
    }

    public function test_pay_later_creates_customer_and_an_unpaid_router_ledger(): void
    {
        $radius = Mockery::mock(RadiusService::class);
        $radius->shouldReceive('syncCustomer')->once()->andReturn(['active' => true, 'disconnected' => 0]);
        $subscriptions = Mockery::mock(ZoStreamSubscriptionService::class);
        $subscriptions->shouldReceive('activateComplimentaryAccess')->once()->andReturn([]);
        $service = new CustomerOnboardingService(Mockery::mock(CashFreeController::class), $radius, $subscriptions);

        $result = $service->createWithoutPayment(
            $this->customerPayload('pay-later-user'),
            'new',
            2500,
            'Customer will pay next week.',
            7,
        );

        $this->assertNull($result['sync_error']);
        $this->assertNull($result['activation_error']);
        $this->assertSame(today()->addDays(30)->toDateString(), $result['customer']->expires_at->toDateString());
        $this->assertDatabaseHas('customers', [
            'id' => $result['customer']->id,
            'router_device_condition' => 'new',
        ]);
        $this->assertDatabaseHas('customer_router_payments', [
            'customer_id' => $result['customer']->id,
            'amount' => 2500,
            'status' => 'unpaid',
            'notes' => 'Customer will pay next week.',
        ]);
    }

    public function test_verified_pay_now_completion_is_idempotent(): void
    {
        $cashfree = Mockery::mock(CashFreeController::class);
        $cashfree->shouldReceive('checkPayment')->twice()->with(Mockery::type(Request::class))->andReturn(
            response()->json([
                'success' => true,
                'data' => [
                    'order' => ['order_id' => 'isp_router_1_test', 'order_amount' => 3000, 'order_currency' => 'INR'],
                    'payments' => [['cf_payment_id' => 'cf-router-payment', 'payment_status' => 'SUCCESS']],
                ],
            ])
        );
        $radius = Mockery::mock(RadiusService::class);
        $radius->shouldReceive('syncCustomer')->once()->andReturn(['active' => true, 'disconnected' => 0]);
        $subscriptions = Mockery::mock(ZoStreamSubscriptionService::class);
        $subscriptions->shouldReceive('activateComplimentaryAccess')->once()->andReturn([]);
        $service = new CustomerOnboardingService($cashfree, $radius, $subscriptions);

        $onboarding = CustomerOnboarding::create([
            'operator_id' => 7,
            'customer_payload' => array_merge($this->customerPayload('pay-now-user'), ['expires_at' => today()->addDays(12)->toDateString()]),
            'username' => 'pay-now-user',
            'router_amount' => 3000,
            'cashfree_order_id' => 'isp_router_1_test',
            'payment_session_id' => 'session-test',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $first = $service->completePaidOnboarding($onboarding);
        $second = $service->completePaidOnboarding($onboarding->fresh());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['customer']->id, $second['customer']->id);
        $this->assertSame(today()->addDays(12)->toDateString(), $first['customer']->expires_at->toDateString());
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customer_router_payments', [
            'customer_id' => $first['customer']->id,
            'status' => 'paid',
            'method' => 'cashfree',
            'gateway_payment_id' => 'cf-router-payment',
        ]);
    }

    public function test_custom_expiry_is_preserved_without_payment_or_aadhaar(): void
    {
        $radius = Mockery::mock(RadiusService::class);
        $radius->shouldReceive('syncCustomer')->once()->andReturn(['active' => true, 'disconnected' => 0]);
        $subscriptions = Mockery::mock(ZoStreamSubscriptionService::class);
        $subscriptions->shouldReceive('activateComplimentaryAccess')->once()->andReturn([]);
        $service = new CustomerOnboardingService(Mockery::mock(CashFreeController::class), $radius, $subscriptions);
        $payload = $this->customerPayload('custom-expiry');
        $payload['expires_at'] = today()->addDays(10)->toDateString();
        $result = $service->createWithoutPayment($payload, 'old', 0, null, 7);
        $this->assertSame($payload['expires_at'], $result['customer']->expires_at->toDateString());
        $this->assertNull($result['customer']->aadhaar_qr_verified_at);
        $this->assertNull($result['customer']->aadhaar_front_path);
    }

    private function customerPayload(string $username): array
    {
        return [
            'router_id' => 1,
            'package_id' => 2,
            'branch_id' => null,
            'name' => 'New Customer',
            'phone' => '9876543210',
            'address' => 'Test address',
            'router_device_condition' => 'new',
            'aadhaar_qr_verified_at' => null,
            'username' => $username,
            'password' => 'secret-password',
            'status' => 'active',
            'expires_at' => null,
        ];
    }
}
