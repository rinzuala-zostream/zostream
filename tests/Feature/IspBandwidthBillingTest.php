<?php

namespace Tests\Feature;

use App\Isp\Models\BandwidthInvoice;
use App\Isp\Models\Branch;
use App\Isp\Models\Customer;
use App\Isp\Models\Package;
use App\Isp\Models\User;
use App\Isp\Services\BandwidthBillingService;
use App\Isp\Services\MikroTikService;
use App\Isp\Services\RadiusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspBandwidthBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('router_id')->nullable();
            $table->decimal('operator_percentage')->nullable();
            $table->decimal('ott_deduction')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('isp_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('router_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('name');
            $table->string('username')->unique();
            $table->text('password');
            $table->string('status')->default('active');
            $table->date('expires_at')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('router_device_condition')->default('old');
            $table->string('aadhaar_front_path')->nullable();
            $table->string('aadhaar_back_path')->nullable();
            $table->string('mikrotik_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('routers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('price')->default(100);
            $table->string('rate_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('branch_package', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('package_id');
            $table->timestamps();
        });
        foreach (['radcheck', 'radreply'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('username');
                $table->string('attribute');
                $table->string('op');
                $table->string('value');
            });
        }
        (require database_path('migrations/2026_11_19_000001_add_bandwidth_billing.php'))->up();
        (require database_path('migrations/2026_11_19_000002_add_bandwidth_invoice_whatsapp_delivery.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function branch(array $attributes = []): Branch
    {
        return Branch::create($attributes + [
            'name' => 'Bandwidth branch', 'billing_type' => 'bandwidth_only', 'bandwidth_mbps' => 1200,
            'bandwidth_rate' => 70, 'bandwidth_gst' => 18, 'bandwidth_due_day' => 10, 'bandwidth_started_at' => '2026-10-05',
        ]);
    }

    private function customer(Branch $branch, array $attributes = []): Customer
    {
        return Customer::withoutEvents(fn () => Customer::create($attributes + [
            'branch_id' => $branch->id, 'name' => 'Subscriber', 'username' => 'subscriber-'.uniqid(),
            'password' => 'ppp-secret', 'status' => 'active', 'expires_at' => '2027-01-31',
            'aadhaar_front_path' => 'front.jpg', 'aadhaar_back_path' => 'back.jpg', 'router_device_condition' => 'old',
        ]));
    }

    private function user(?Branch $branch = null): User
    {
        return User::create([
            'name' => 'Panel user', 'email' => uniqid().'@example.test', 'password' => 'password123',
            'role' => $branch ? 'branch_operator' : 'admin', 'branch_id' => $branch?->id, 'is_active' => true,
        ]);
    }

    public function test_monthly_invoice_calculation_snapshot_and_idempotent_catchup(): void
    {
        $branch = $this->branch();
        $billing = app(BandwidthBillingService::class);
        $this->assertSame(1, $billing->generate($branch));
        $invoice = BandwidthInvoice::firstOrFail();
        $this->assertSame('2026-10-01', $invoice->billing_month->toDateString());
        $this->assertSame('2026-11-01', $invoice->issued_on->toDateString());
        $this->assertSame('2026-11-10', $invoice->due_date->toDateString());
        $this->assertSame('84000.00', $invoice->subtotal);
        $this->assertSame('15120.00', $invoice->gst_amount);
        $this->assertSame('99120.00', $invoice->total);
        $branch->update(['bandwidth_rate' => 90]);
        $this->assertSame(0, $billing->generate($branch));
        $this->assertSame('99120.00', $invoice->fresh()->total);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame(2, $billing->generate($branch));
        $this->assertSame(3, BandwidthInvoice::count());
    }

    public function test_first_invoice_starts_next_month_and_short_month_due_date_is_clamped(): void
    {
        $branch = $this->branch(['bandwidth_started_at' => '2027-01-20', 'bandwidth_due_day' => 31]);
        $billing = app(BandwidthBillingService::class);
        $this->travelTo(now()->setDate(2027, 1, 31));
        $this->assertSame(0, $billing->generate($branch));
        $this->travelTo(now()->setDate(2027, 2, 1));
        $this->assertSame(1, $billing->generate($branch));
        $this->assertSame('2027-02-28', BandwidthInvoice::firstOrFail()->due_date->toDateString());
    }

    public function test_due_date_blocks_every_customer_and_editing_or_renewing_cannot_bypass_it(): void
    {
        $branch = $this->branch();
        DB::table('routers')->insert(['id' => 1, 'name' => 'Router']);
        $customer = $this->customer($branch, ['router_id' => 1]);
        $other = $this->customer($branch, ['router_id' => 1, 'status' => 'suspended']);
        $mikrotik = Mockery::mock(MikroTikService::class);
        $mikrotik->shouldReceive('disconnectPppUsers')->once()->andReturnUsing(function ($router, $usernames) use ($customer, $other): int {
            $this->assertEqualsCanonicalizing([$customer->username, $other->username], $usernames);
            foreach ([$customer, $other] as $subscriber) {
                $this->assertDatabaseHas('radcheck', ['username' => $subscriber->username, 'attribute' => 'Auth-Type', 'value' => 'Reject']);
            }

            return 2;
        });
        $this->app->instance(MikroTikService::class, $mikrotik);
        $billing = app(BandwidthBillingService::class);
        $billing->generate($branch);
        $this->travelTo(now()->setDate(2026, 11, 10)->endOfDay());
        $this->assertFalse($branch->hasOverdueBandwidthInvoice());
        $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
        $this->artisan('isp:bandwidth-billing')->assertSuccessful();
        foreach ([$customer, $other] as $subscriber) {
            $this->assertDatabaseHas('radcheck', ['username' => $subscriber->username, 'attribute' => 'Auth-Type', 'value' => 'Reject']);
        }
        $this->assertSame('active', $customer->fresh()->status);
        $customer->update(['expires_at' => '2027-12-31']);
        $this->assertFalse(app(RadiusService::class)->syncCustomer($customer, false)['active']);
        $this->assertDatabaseMissing('radcheck', ['username' => $customer->username, 'attribute' => 'Cleartext-Password']);
        // A second scheduler run does not repeatedly disconnect successfully blocked customers.
        $this->artisan('isp:bandwidth-billing')->assertSuccessful();
    }

    public function test_paid_is_admin_only_idempotent_and_restores_only_eligible_customers(): void
    {
        $branch = $this->branch();
        $customer = $this->customer($branch);
        $suspended = $this->customer($branch, ['status' => 'suspended']);
        $expired = $this->customer($branch, ['expires_at' => '2026-10-01']);
        $billing = app(BandwidthBillingService::class);
        $billing->generate($branch);
        $this->travelTo(now()->setDate(2026, 11, 11));
        $billing->reconcile($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $operator = $this->user($branch);
        $this->actingAs($operator, 'isp')->post(route('isp.bandwidth-invoices.paid', $invoice))->assertForbidden();
        $admin = $this->user();
        $this->actingAs($admin, 'isp')->post(route('isp.bandwidth-invoices.paid', $invoice), ['payment_reference' => 'BANK-123'])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'attribute' => 'Cleartext-Password']);
        foreach ([$suspended, $expired] as $subscriber) {
            $this->assertDatabaseHas('radcheck', ['username' => $subscriber->username, 'attribute' => 'Auth-Type', 'value' => 'Reject']);
        }
        $paidAt = $invoice->fresh()->paid_at;
        $this->travel(1)->hours();
        $this->post(route('isp.bandwidth-invoices.paid', $invoice), ['payment_reference' => 'RETRY'])->assertRedirect();
        $this->assertTrue($paidAt->eq($invoice->fresh()->paid_at));
        $this->assertSame('BANK-123', $invoice->fresh()->payment_reference);
        $this->assertSame($admin->id, $invoice->fresh()->paid_by);
    }

    public function test_one_paid_invoice_does_not_unblock_another_overdue_invoice(): void
    {
        $branch = $this->branch(['bandwidth_started_at' => '2026-09-01']);
        $this->customer($branch);
        $billing = app(BandwidthBillingService::class);
        $billing->generate($branch);
        $this->travelTo(now()->setDate(2026, 11, 11));
        $billing->reconcile($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $this->actingAs($this->user(), 'isp')->post(route('isp.bandwidth-invoices.paid', $invoice))->assertRedirect();
        $this->assertTrue($branch->hasOverdueBandwidthInvoice());
        $this->assertSame(0, DB::table('radcheck')->where('attribute', 'Cleartext-Password')->count());
    }

    public function test_operators_can_only_read_their_own_invoices_and_cannot_change_due_dates(): void
    {
        $branch = $this->branch();
        app(BandwidthBillingService::class)->generate($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $this->actingAs($this->user($branch), 'isp')->get(route('isp.bandwidth-invoices.index'))->assertOk()->assertSee($invoice->number());
        $this->get(route('isp.bandwidth-invoices.show', $invoice))->assertOk()->assertDontSee('Mark as Paid');
        $this->put(route('isp.bandwidth-invoices.update', $invoice), ['due_date' => '2026-12-01'])->assertForbidden();
        $other = $this->branch(['name' => 'Other']);
        $this->actingAs($this->user($other), 'isp')->get(route('isp.bandwidth-invoices.show', $invoice))->assertForbidden();
        $this->get(route('isp.bandwidth-invoices.index'))->assertOk()->assertDontSee($invoice->number());
    }

    public function test_customer_expiry_and_activation_without_payment_are_bandwidth_only(): void
    {
        DB::table('routers')->insert(['id' => 1, 'name' => 'Router']);
        DB::table('packages')->insert(['id' => 1, 'name' => 'Plan']);
        foreach (['bandwidth_only', 'standard'] as $type) {
            $branch = $this->branch(['name' => $type, 'billing_type' => $type]);
            $customer = $this->customer($branch, ['router_id' => 1, 'package_id' => 1, 'status' => 'suspended', 'expires_at' => '2026-10-01']);
            $response = $this->actingAs($this->user($branch), 'isp')->put(route('isp.customers.update', $customer), [
                'name' => 'Renewed', 'branch_id' => $branch->id, 'package_id' => 1, 'username' => $customer->username,
                'status' => 'active', 'expires_at' => '2027-02-01', 'router_device_condition' => 'old',
            ])->assertRedirect();
            if ($type === 'bandwidth_only') {
                $response->assertSessionHasNoErrors();
            } else {
                $response->assertSessionHasErrors(['expires_at']);
            }
            $this->assertSame($type === 'bandwidth_only' ? '2027-02-01' : '2026-10-01', $customer->fresh()->expires_at->toDateString());
            if ($type === 'bandwidth_only') {
                $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'attribute' => 'Cleartext-Password']);
            }
        }
    }

    public function test_branch_form_and_creation_store_bandwidth_rules_without_share(): void
    {
        $this->actingAs($this->user(), 'isp')->get(route('isp.branches.create'))->assertOk()->assertSee('Bandwidth Only');
        $this->post(route('isp.branches.store'), [
            'name' => 'New branch', 'billing_type' => 'bandwidth_only', 'bandwidth_mbps' => 1200, 'bandwidth_rate' => 70,
            'bandwidth_gst' => 18, 'bandwidth_due_day' => 15, 'operator_percentage' => 80, 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $branch = Branch::firstOrFail();
        $this->assertNull($branch->operator_percentage);
        $this->assertSame('2026-11-01', $branch->bandwidth_started_at->toDateString());
        $this->get(route('isp.branches.index'))->assertOk();
        $this->get(route('isp.users.create'))->assertOk()->assertSee('Bandwidth Only');
    }

    public function test_failed_disconnect_is_retried_and_standard_branches_are_untouched(): void
    {
        $branch = $this->branch();
        DB::table('routers')->insert(['id' => 1, 'name' => 'Router']);
        $customer = $this->customer($branch, ['router_id' => 1]);
        $standard = $this->branch(['name' => 'Standard', 'billing_type' => 'standard']);
        $normalCustomer = $this->customer($standard);
        $this->travelTo(now()->setDate(2026, 11, 11));
        $mikrotik = Mockery::mock(MikroTikService::class);
        $mikrotik->shouldReceive('disconnectPppUsers')->once()->andThrow(new \RuntimeException('Router offline'));
        $this->app->instance(MikroTikService::class, $mikrotik);
        $this->artisan('isp:bandwidth-billing')->assertFailed();
        $this->assertNull($customer->fresh()->bandwidth_access_blocked);
        $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'value' => 'Reject']);
        $retry = Mockery::mock(MikroTikService::class);
        $retry->shouldReceive('disconnectPppUsers')->once()->andReturn(1);
        $this->app->instance(MikroTikService::class, $retry);
        $this->artisan('isp:bandwidth-billing')->assertSuccessful();
        $this->assertEquals(1, $customer->fresh()->bandwidth_access_blocked);
        $this->assertNull($normalCustomer->fresh()->bandwidth_access_blocked);
        $this->assertSame(0, $standard->bandwidthInvoices()->count());
    }

    public function test_bandwidth_customer_payments_have_no_operator_commission(): void
    {
        $branch = $this->branch(['operator_percentage' => 80, 'ott_deduction' => 50]);
        $customer = $this->customer($branch);
        $package = new Package(['price' => 1000]);
        $controller = app(\App\Isp\Http\Controllers\PaymentController::class);
        $method = new \ReflectionMethod($controller, 'paymentAmounts');
        $amounts = $method->invoke($controller, $customer, $package);
        $this->assertSame(0.0, $amounts['commission']);
        $this->assertEquals(0, $amounts['operator_percentage']);
        $this->assertEquals(1000, $amounts['payable']);
    }

    public function test_admin_user_form_saves_shared_branch_bandwidth_settings(): void
    {
        $branch = $this->branch(['billing_type' => 'standard', 'operator_percentage' => 20]);
        $this->actingAs($this->user(), 'isp')->post(route('isp.users.store'), [
            'name' => 'Bandwidth operator', 'email' => 'bandwidth@example.test', 'phone' => '+91 98765 43210', 'password' => 'password123',
            'password_confirmation' => 'password123', 'role' => 'bandwidth_only', 'branch_id' => $branch->id,
            'is_active' => 1, 'bandwidth_mbps' => 1200,
            'bandwidth_rate' => 70, 'bandwidth_gst' => 18, 'bandwidth_due_day' => 12,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($branch->fresh()->isBandwidthOnly());
        $this->assertNull($branch->fresh()->operator_percentage);
        $this->assertSame('70.00', $branch->fresh()->bandwidth_rate);
        $operator = User::where('email', 'bandwidth@example.test')->firstOrFail();
        $this->assertSame($branch->id, $operator->branch_id);
        $this->assertTrue($operator->isBranchOperator());
        $this->assertFalse($operator->isAdmin());
        $this->assertSame('bandwidth_only', $operator->panelRole());
        $this->get(route('isp.users.edit', $operator))->assertOk()->assertSee('1200.00')->assertDontSee('Operator type')->assertSee('value="bandwidth_only" selected', false);
        $this->get(route('isp.users.index'))->assertOk()->assertSee('Bandwidth Only');
        $this->put(route('isp.users.update', $operator), [
            'name' => 'Updated bandwidth operator', 'email' => $operator->email, 'phone' => '9876543210',
            'role' => 'bandwidth_only', 'branch_id' => $branch->id, 'is_active' => 1,
            'bandwidth_mbps' => 1200, 'bandwidth_rate' => 70, 'bandwidth_gst' => 18, 'bandwidth_due_day' => 12,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated bandwidth operator', $operator->fresh()->name);
        $this->assertSame('919876543210', $operator->fresh()->phone);
        $this->assertSame('bandwidth_only', $operator->fresh()->panelRole());
        $this->assertSame('2026-11-01', $branch->fresh()->bandwidth_started_at->toDateString());
    }

    public function test_extending_due_date_restores_access_and_shortening_it_blocks_again(): void
    {
        $branch = $this->branch();
        $customer = $this->customer($branch);
        $billing = app(BandwidthBillingService::class);
        $billing->generate($branch);
        $this->travelTo(now()->setDate(2026, 11, 11));
        $billing->reconcile($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $this->actingAs($this->user(), 'isp')->put(route('isp.bandwidth-invoices.update', $invoice), ['due_date' => '2026-11-20'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'attribute' => 'Cleartext-Password']);
        $this->put(route('isp.bandwidth-invoices.update', $invoice), ['due_date' => '2026-11-10'])->assertRedirect();
        $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'value' => 'Reject']);
        $this->post(route('isp.bandwidth-invoices.paid', $invoice))->assertRedirect();
        $this->put(route('isp.bandwidth-invoices.update', $invoice), ['due_date' => '2026-11-20'])->assertStatus(422);
    }

    public function test_payment_restores_a_customer_blocked_before_scheduler_ran(): void
    {
        $branch = $this->branch();
        $customer = $this->customer($branch);
        $billing = app(BandwidthBillingService::class);
        $billing->generate($branch);
        $billing->reconcile($branch);
        $this->assertEquals(0, $customer->fresh()->bandwidth_access_blocked);
        $this->travelTo(now()->setDate(2026, 11, 11));
        app(RadiusService::class)->syncCustomer($customer, false);
        $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'value' => 'Reject']);
        $this->actingAs($this->user(), 'isp')->post(route('isp.bandwidth-invoices.paid', BandwidthInvoice::firstOrFail()))->assertRedirect();
        $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'attribute' => 'Cleartext-Password']);
    }

    public function test_unpaid_invoice_prevents_type_change_and_invalid_bandwidth_settings_are_rejected(): void
    {
        $branch = $this->branch();
        app(BandwidthBillingService::class)->generate($branch);
        $this->actingAs($this->user(), 'isp')->put(route('isp.branches.update', $branch), [
            'name' => $branch->name, 'billing_type' => 'standard', 'is_active' => 1,
        ])->assertSessionHasErrors(['billing_type']);
        $this->assertTrue($branch->fresh()->isBandwidthOnly());
        $this->post(route('isp.branches.store'), [
            'name' => 'Invalid', 'billing_type' => 'bandwidth_only', 'bandwidth_mbps' => -1,
            'bandwidth_rate' => 70, 'bandwidth_gst' => 101, 'bandwidth_due_day' => 32,
        ])->assertSessionHasErrors(['bandwidth_mbps', 'bandwidth_gst', 'bandwidth_due_day']);
        $this->assertSame(1, Branch::count());
    }

    public function test_bandwidth_role_requires_a_branch_and_billing_fields_despite_a_forged_type(): void
    {
        $this->actingAs($this->user(), 'isp')->post(route('isp.users.store'), [
            'name' => 'Invalid operator', 'email' => 'invalid@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'bandwidth_only', 'billing_type' => 'standard', 'is_active' => 1,
        ])->assertSessionHasErrors(['phone', 'branch_id', 'bandwidth_mbps', 'bandwidth_rate', 'bandwidth_gst', 'bandwidth_due_day']);
        $this->assertFalse(User::where('email', 'invalid@example.test')->exists());
    }

    private function configureWhatsApp(): void
    {
        config(['app.whatsapp_phone_id' => 'test-phone-id', 'app.whatsapp_token' => 'test-token', 'app.whatsapp_invoice_button_parameter' => 'path']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    public function test_whatsapp_uses_wifi_document_flow_with_bandwidth_links_and_sends_once_per_phone(): void
    {
        $this->configureWhatsApp();
        \Illuminate\Support\Facades\Http::fake(['https://graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.test-1']]], 200)]);
        $branch = $this->branch();
        $operator = $this->user($branch);
        $operator->update(['phone' => '9876543210']);
        $duplicate = $this->user($branch);
        $duplicate->update(['phone' => '+91 9876543210']);
        $inactive = $this->user($branch);
        $inactive->update(['phone' => '9999999999', 'is_active' => false]);
        $admin = $this->user();
        $admin->update(['phone' => '8888888888', 'branch_id' => $branch->id]);
        $billing = app(BandwidthBillingService::class);
        $billing->generate($branch);
        $service = app(\App\Isp\Services\BandwidthInvoiceWhatsAppService::class);
        $invoice = BandwidthInvoice::firstOrFail();
        $this->assertSame(0, $service->sendPending($branch));
        $this->assertSame(0, $service->sendPending($branch));
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($invoice): bool {
            $components = collect($request['template']['components']);
            $body = $components->firstWhere('type', 'body');
            $header = $components->firstWhere('type', 'header');
            $button = $components->firstWhere('type', 'button');
            $this->assertSame('99120.00', $body['parameters'][3]['text']);
            $this->assertSame($invoice->number(), $body['parameters'][1]['text']);
            $this->assertSame('Oct 01, 2026 - Oct 31, 2026', $body['parameters'][2]['text']);
            $this->assertSame('Nov 10, 2026', $body['parameters'][4]['text']);
            $this->assertStringContainsString('/invoices/bandwidth/'.$invoice->id.'/pdf?signature=', $header['parameters'][0]['document']['link']);
            $this->assertStringStartsWith('invoices/bandwidth/'.$invoice->id.'?signature=', $button['parameters'][0]['text']);

            return $request['to'] === '919876543210' && $request['template']['name'] === 'zostream_wifi_invoice_v3';
        });
        $this->assertSame(1, $invoice->deliveries()->count());
        $delivery = $invoice->deliveries()->firstOrFail();
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame('wamid.test-1', $delivery->provider_message_id);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_whatsapp_failure_waits_for_retry_then_recovers_without_duplicate_invoices(): void
    {
        $this->configureWhatsApp();
        \Illuminate\Support\Facades\Http::fake(['https://graph.facebook.com/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['error' => ['message' => 'Provider temporarily unavailable']], 503)
            ->push(['messages' => [['id' => 'wamid.retry']]], 200)]);
        $branch = $this->branch();
        $operator = $this->user($branch);
        $operator->update(['phone' => '9876543210']);
        $this->artisan('isp:bandwidth-billing')->assertFailed();
        $invoice = BandwidthInvoice::firstOrFail();
        $delivery = $invoice->deliveries()->firstOrFail();
        $this->assertNull($delivery->sent_at);
        $this->assertNull($delivery->processing_at);
        $this->assertNotNull($delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
        $this->artisan('isp:bandwidth-billing')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->travel(16)->minutes();
        $this->artisan('isp:bandwidth-billing')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertSentCount(2);
        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(1, BandwidthInvoice::count());
    }

    public function test_in_progress_delivery_is_not_duplicated_and_stale_claim_can_retry(): void
    {
        $this->configureWhatsApp();
        \Illuminate\Support\Facades\Http::fake(['https://graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.stale']]], 200)]);
        $branch = $this->branch();
        $operator = $this->user($branch);
        $operator->update(['phone' => '9876543210']);
        app(BandwidthBillingService::class)->generate($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $invoice->deliveries()->create(['phone' => '919876543210', 'recipient_name' => $operator->name, 'processing_at' => now()]);
        $service = app(\App\Isp\Services\BandwidthInvoiceWhatsAppService::class);
        $service->sendInvoice($invoice);
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->travel(16)->minutes();
        $service->sendInvoice($invoice);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_public_bandwidth_invoice_and_pdf_require_signatures_and_have_separate_ui(): void
    {
        $branch = $this->branch();
        app(BandwidthBillingService::class)->generate($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $links = app(\App\Isp\Services\BandwidthInvoiceWhatsAppService::class);
        $this->get(route('invoice.bandwidth.show', $invoice))->assertForbidden();
        $this->get(route('invoice.bandwidth.pdf', $invoice))->assertForbidden();
        $this->get($links->viewUrl($invoice))->assertOk()->assertViewIs('invoices.bandwidth_show')
            ->assertSee('Business Bandwidth')->assertSee('99,120.00')->assertSee('GST')->assertSee('Please settle this invoice by')
            ->assertDontSee('Mark as Paid')->assertDontSee('Zo Stream WIFI');
        $this->get($links->viewUrl($invoice).'&extra=tampered')->assertForbidden();
        $this->view('invoices.bandwidth_pdf', ['invoice' => $invoice])->assertSee('Please settle by');
        $pdf = $this->get($links->pdfUrl($invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertGreaterThan(5000, strlen($pdf->getContent()));
        $invoice->update(['paid_at' => now()]);
        $this->get($links->viewUrl($invoice))->assertOk()->assertSee('Payment recorded');
    }

    public function test_manual_whatsapp_attempt_is_admin_only_and_standard_branches_do_not_send(): void
    {
        $this->configureWhatsApp();
        \Illuminate\Support\Facades\Http::fake(['https://graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.manual']]], 200)]);
        $branch = $this->branch();
        $operator = $this->user($branch);
        $operator->update(['phone' => '9876543210']);
        app(BandwidthBillingService::class)->generate($branch);
        $invoice = BandwidthInvoice::firstOrFail();
        $this->actingAs($operator, 'isp')->post(route('isp.bandwidth-invoices.whatsapp', $invoice))->assertForbidden();
        $this->actingAs($this->user(), 'isp')->post(route('isp.bandwidth-invoices.whatsapp', $invoice))->assertRedirect()->assertSessionHas('success');
        $this->post(route('isp.bandwidth-invoices.whatsapp', $invoice))->assertRedirect();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $branch->update(['billing_type' => 'standard']);
        $this->assertSame(0, app(\App\Isp\Services\BandwidthInvoiceWhatsAppService::class)->sendPending($branch));
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_whatsapp_failure_does_not_prevent_other_branches_being_blocked(): void
    {
        $this->configureWhatsApp();
        \Illuminate\Support\Facades\Http::fake(['https://graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['error' => ['message' => 'Unavailable']], 503)]);
        $first = $this->branch();
        $operator = $this->user($first);
        $operator->update(['phone' => '9876543210']);
        $firstCustomer = $this->customer($first);
        $second = $this->branch(['name' => 'Second bandwidth branch']);
        $secondCustomer = $this->customer($second);
        $this->travelTo(now()->setDate(2026, 11, 11));
        $this->artisan('isp:bandwidth-billing')->assertFailed();
        foreach ([$firstCustomer, $secondCustomer] as $customer) {
            $this->assertDatabaseHas('radcheck', ['username' => $customer->username, 'attribute' => 'Auth-Type', 'value' => 'Reject']);
        }
        $this->assertSame(2, BandwidthInvoice::count());
    }

    public function test_phone_is_optional_for_standard_roles_and_invalid_phone_is_rejected(): void
    {
        $this->actingAs($this->user(), 'isp');
        $data = ['name' => 'New admin', 'email' => 'new-admin@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin', 'is_active' => 1];
        $this->postJson(route('isp.users.store'), $data + ['phone' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->post(route('isp.users.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(User::where('email', 'new-admin@example.test')->firstOrFail()->phone);
    }
}
