<?php

namespace Tests\Feature;

use App\Isp\Models\Customer;
use App\Isp\Models\User;
use App\Isp\Services\RadiusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspPaymentCollectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('billing_type')->default('standard');
            $table->decimal('operator_percentage', 5, 2)->nullable();
            $table->decimal('ott_deduction', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('routers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('isp_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('mikrotik_profile')->nullable();
            $table->string('rate_limit')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('branch_package', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('package_id');
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('router_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('username');
            $table->string('status')->default('active');
            $table->date('expires_at')->nullable();
            $table->date('expiry_suspended_for')->nullable();
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
            $table->string('method')->default('cashfree');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['payments', 'customers', 'branch_package', 'packages', 'isp_users', 'routers', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_collections_are_filtered_by_month_compared_and_scoped_to_the_operator_branch(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $branchId = DB::table('branches')->insertGetId(['name' => 'Aizawl', 'created_at' => now(), 'updated_at' => now()]);
        $otherBranchId = DB::table('branches')->insertGetId(['name' => 'Lunglei', 'created_at' => now(), 'updated_at' => now()]);
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 30', 'price' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $operator = User::create([
            'name' => 'Branch Operator',
            'email' => 'operator@example.test',
            'password' => 'secret-password',
            'role' => 'branch_operator',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'branch_id' => $branchId,
            'name' => 'Current Branch Customer',
            'username' => 'current-branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCustomerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'branch_id' => $otherBranchId,
            'name' => 'Other Branch Customer',
            'username' => 'other-branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertPayment($customerId, $packageId, $operator->id, '2026-09-18 10:00:00', 80, 100, 20);
        $this->insertPayment($customerId, $packageId, $operator->id, '2026-10-05 10:00:00', 100, 125, 25);
        $this->insertPayment($customerId, $packageId, $operator->id, '2026-10-12 10:00:00', 100, 125, 25);
        $this->insertPayment($otherCustomerId, $packageId, $operator->id, '2026-10-08 10:00:00', 900, 1000, 100);

        $this->actingAs($operator, 'isp')
            ->get(route('isp.payments.index', ['view' => 'collections', 'month' => '2026-10']))
            ->assertOk()
            ->assertSee('October 2026 transactions')
            ->assertSee('Current Branch Customer')
            ->assertDontSee('Other Branch Customer')
            ->assertViewHas('collectionSummary', fn (object $summary): bool => (int) $summary->payment_count === 2
                && (float) $summary->revenue === 200.0
                && (float) $summary->package_total === 250.0
                && (float) $summary->operator_commission === 50.0
            )
            ->assertViewHas('revenueDifference', 120.0)
            ->assertViewHas('revenuePercentage', 150.0);
    }

    public function test_overview_monthly_revenue_matches_collections_and_respects_branch_scope(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $branchId = DB::table('branches')->insertGetId(['name' => 'Aizawl']);
        $otherBranchId = DB::table('branches')->insertGetId(['name' => 'Lunglei']);
        $operator = User::create([
            'name' => 'Operator', 'email' => 'overview-operator@example.test',
            'password' => 'secret-password', 'role' => 'branch_operator',
            'branch_id' => $branchId, 'is_active' => true,
        ]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'overview-admin@example.test',
            'password' => 'secret-password', 'role' => 'admin', 'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Local', 'username' => 'local', 'branch_id' => $branchId,
        ]);
        $otherCustomerId = DB::table('customers')->insertGetId([
            'name' => 'Other', 'username' => 'other', 'branch_id' => $otherBranchId,
        ]);
        foreach ([
            [$customerId, '2026-09-30 23:59:59', 800],
            [$customerId, '2026-10-01 00:00:00', 100],
            [$customerId, '2026-10-31 23:59:59', 200],
            [$customerId, '2026-11-01 00:00:00', 900],
            [$otherCustomerId, '2026-10-10 12:00:00', 700],
        ] as [$id, $paidAt, $amount]) {
            DB::table('payments')->insert([
                'customer_id' => $id, 'paid_at' => $paidAt, 'amount' => $amount,
            ]);
        }

        foreach ([[$operator, 300.0], [$admin, 1000.0]] as [$user, $expected]) {
            $this->actingAs($user, 'isp')->get(route('isp.dashboard'))
                ->assertOk()
                ->assertViewHas('stats', fn (array $stats): bool => (float) $stats['revenue'] === $expected)
                ->assertSee(route('isp.payments.index', ['view' => 'collections', 'month' => '2026-10']));
            $this->get(route('isp.payments.index', ['month' => '2026-10']))
                ->assertOk()
                ->assertViewHas('collectionSummary', fn (object $summary): bool => (float) $summary->revenue === $expected);
        }
    }

    public function test_collect_payment_is_a_separate_view_and_customer_links_open_it(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $user = User::create([
            'name' => 'ISP Admin',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'isp')
            ->get(route('isp.payments.index'))
            ->assertOk()
            ->assertViewHas('activeView', 'collections')
            ->assertSee('Payment Collections')
            ->assertSee('No previous-month baseline')
            ->assertDontSee('Customer & payment', false);

        $this->actingAs($user, 'isp')
            ->get(route('isp.payments.index', ['customer' => 99]))
            ->assertOk()
            ->assertViewHas('activeView', 'collect')
            ->assertSee('Customer & payment', false);
    }

    public function test_administrator_can_filter_collection_totals_and_history_by_router(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $routerId = DB::table('routers')->insertGetId([
            'name' => 'Aizawl Core', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherRouterId = DB::table('routers')->insertGetId([
            'name' => 'Lunglei Core', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 30', 'price' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::create([
            'name' => 'ISP Admin',
            'email' => 'router-filter-admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'router_id' => $routerId,
            'package_id' => $packageId,
            'name' => 'Aizawl Customer',
            'username' => 'aizawl-customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCustomerId = DB::table('customers')->insertGetId([
            'router_id' => $otherRouterId,
            'package_id' => $packageId,
            'name' => 'Lunglei Customer',
            'username' => 'lunglei-customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertPayment($customerId, $packageId, $admin->id, '2026-09-18 10:00:00', 50, 100, 20);
        $this->insertPayment($customerId, $packageId, $admin->id, '2026-10-05 10:00:00', 100, 125, 25);
        $this->insertPayment($otherCustomerId, $packageId, $admin->id, '2026-10-08 10:00:00', 900, 1000, 100);

        $this->actingAs($admin, 'isp')
            ->get(route('isp.payments.index', [
                'view' => 'collections',
                'month' => '2026-10',
                'router_id' => $routerId,
            ]))
            ->assertOk()
            ->assertSee('Aizawl Core')
            ->assertSee('Aizawl Customer')
            ->assertDontSee('Lunglei Customer')
            ->assertViewHas('selectedRouterId', $routerId)
            ->assertViewHas('collectionSummary', fn (object $summary): bool => (int) $summary->payment_count === 1
                && (float) $summary->revenue === 100.0
                && (float) $summary->package_total === 125.0
                && (float) $summary->operator_commission === 25.0
            )
            ->assertViewHas('revenueDifference', 50.0)
            ->assertViewHas('revenuePercentage', 100.0);
    }

    public function test_branch_operator_cannot_replace_role_scope_with_router_filter(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $branchId = DB::table('branches')->insertGetId(['name' => 'Aizawl', 'created_at' => now(), 'updated_at' => now()]);
        $otherBranchId = DB::table('branches')->insertGetId(['name' => 'Lunglei', 'created_at' => now(), 'updated_at' => now()]);
        $routerId = DB::table('routers')->insertGetId(['name' => 'Aizawl Core', 'created_at' => now(), 'updated_at' => now()]);
        $otherRouterId = DB::table('routers')->insertGetId(['name' => 'Lunglei Core', 'created_at' => now(), 'updated_at' => now()]);
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 30', 'price' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $operator = User::create([
            'name' => 'Branch Operator',
            'email' => 'router-filter-operator@example.test',
            'password' => 'secret-password',
            'role' => 'branch_operator',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'router_id' => $routerId, 'package_id' => $packageId, 'branch_id' => $branchId,
            'name' => 'Current Branch Customer', 'username' => 'current-router-customer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherCustomerId = DB::table('customers')->insertGetId([
            'router_id' => $otherRouterId, 'package_id' => $packageId, 'branch_id' => $otherBranchId,
            'name' => 'Other Branch Customer', 'username' => 'other-router-customer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->insertPayment($customerId, $packageId, $operator->id, '2026-10-05 10:00:00', 100, 125, 25);
        $this->insertPayment($otherCustomerId, $packageId, $operator->id, '2026-10-08 10:00:00', 900, 1000, 100);

        $this->actingAs($operator, 'isp')
            ->get(route('isp.payments.index', [
                'view' => 'collections',
                'month' => '2026-10',
                'router_id' => $otherRouterId,
            ]))
            ->assertOk()
            ->assertSee('Current Branch Customer')
            ->assertDontSee('Other Branch Customer')
            ->assertDontSee('All routers')
            ->assertViewHas('selectedRouterId', null)
            ->assertViewHas('collectionSummary', fn (object $summary): bool => (int) $summary->payment_count === 1
                && (float) $summary->revenue === 100.0
            );
    }

    public function test_renewal_does_not_clear_a_manual_suspension(): void
    {
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 30',
            'price' => 500,
            'validity_days' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = User::create([
            'name' => 'ISP Admin',
            'email' => 'renewal-admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Manually Suspended',
            'username' => 'manual-suspension',
            'status' => 'suspended',
            'expires_at' => today()->addDays(5)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $radius = Mockery::mock(RadiusService::class);
        $radius->shouldReceive('syncCustomer')->once()
            ->with(Mockery::on(fn (Customer $customer): bool => $customer->id === $customerId
                && $customer->status === 'suspended'), false)
            ->andReturn(['active' => false, 'disconnected' => 0]);
        $radius->shouldReceive('syncCustomer')->once()
            ->with(Mockery::on(fn (Customer $customer): bool => $customer->id === $customerId
                && $customer->status === 'suspended'))
            ->andReturn(['active' => false, 'disconnected' => 0]);
        $this->app->instance(RadiusService::class, $radius);

        $this->actingAs($admin, 'isp')->post(route('isp.payments.store'), [
            'customer_id' => $customerId,
            'package_id' => $packageId,
            'method' => 'cash',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'status' => 'suspended',
        ]);
        $this->assertSame(
            today()->addDays(35)->toDateString(),
            Customer::findOrFail($customerId)->expires_at->toDateString(),
        );
    }

    public function test_bandwidth_customers_are_excluded_from_collections_picker_and_dashboard_without_deleting_history(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $normalBranch = DB::table('branches')->insertGetId(['name' => 'Standard branch']);
        $bandwidthBranch = DB::table('branches')->insertGetId(['name' => 'Bandwidth branch', 'billing_type' => 'bandwidth_only']);
        $admin = User::create(['name' => 'Admin', 'email' => 'separation@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $package = DB::table('packages')->insertGetId(['name' => 'Plan', 'price' => 500]);
        $normalCustomer = DB::table('customers')->insertGetId(['name' => 'Standard subscriber', 'username' => 'standard-sub', 'branch_id' => $normalBranch, 'package_id' => $package]);
        $bandwidthCustomer = DB::table('customers')->insertGetId(['name' => 'Bandwidth subscriber', 'username' => 'bandwidth-sub', 'branch_id' => $bandwidthBranch, 'package_id' => $package]);
        foreach ([[$normalCustomer, '2026-10-10', 100], [$bandwidthCustomer, '2026-10-10', 900], [$normalCustomer, '2026-09-10', 80], [$bandwidthCustomer, '2026-09-10', 800]] as [$customer, $paidAt, $amount]) {
            $this->insertPayment($customer, $package, $admin->id, $paidAt, $amount, $amount, 0);
        }
        $this->actingAs($admin, 'isp')->get(route('isp.payments.index', ['month' => '2026-10']))
            ->assertOk()->assertSee('Standard subscriber')->assertDontSee('Bandwidth subscriber')
            ->assertViewHas('collectionSummary', fn ($summary) => (float) $summary->revenue === 100.0 && (int) $summary->payment_count === 1)
            ->assertViewHas('revenueDifference', 20.0);
        $this->get(route('isp.payments.index', ['view' => 'collect']))
            ->assertOk()->assertSee('Standard subscriber')->assertDontSee('Bandwidth subscriber')
            ->assertViewHas('customers', fn ($customers) => $customers->pluck('id')->all() === [$normalCustomer]);
        $this->get(route('isp.dashboard'))->assertOk()
            ->assertViewHas('stats', fn ($stats) => (float) $stats['revenue'] === 100.0);
        $historicalPayment = DB::table('payments')->where('customer_id', $bandwidthCustomer)->value('id');
        $this->get(route('isp.payments.invoice', $historicalPayment))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->delete(route('isp.payments.destroy', $historicalPayment))->assertForbidden();
        $this->assertSame(4, DB::table('payments')->count());
        $this->assertSame(2, DB::table('payments')->where('customer_id', $bandwidthCustomer)->count());

        $operator = User::create(['name' => 'Bandwidth operator', 'email' => 'bandwidth-panel@example.test', 'password' => 'password123', 'role' => 'branch_operator', 'branch_id' => $bandwidthBranch, 'is_active' => true]);
        $this->actingAs($operator, 'isp')->get(route('isp.payments.index'))->assertRedirect(route('isp.bandwidth-invoices.index'));
        $this->get(route('isp.dashboard'))->assertOk()
            ->assertSee(route('isp.bandwidth-invoices.index'))
            ->assertDontSee('title="Payments"', false)->assertDontSee('Payments collected');
    }

    public function test_bandwidth_customer_collection_and_checkout_are_rejected_for_admin_and_operator(): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'Bandwidth', 'billing_type' => 'bandwidth_only']);
        $package = DB::table('packages')->insertGetId(['name' => 'Plan', 'price' => 500]);
        $customer = DB::table('customers')->insertGetId(['name' => 'No collection', 'username' => 'no-collection', 'branch_id' => $branch, 'package_id' => $package, 'expires_at' => '2026-12-01']);
        foreach (['admin', 'branch_operator'] as $role) {
            $user = User::create(['name' => $role, 'email' => $role.'@blocked-payments.test', 'password' => 'password123', 'role' => $role, 'branch_id' => $role === 'branch_operator' ? $branch : null, 'is_active' => true]);
            $this->actingAs($user, 'isp')->post(route('isp.payments.store'), ['customer_id' => $customer, 'method' => 'cash'])->assertForbidden();
            $this->postJson(route('isp.payments.checkout'), ['customer_id' => $customer])->assertForbidden();
        }
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame('2026-12-01', Customer::findOrFail($customer)->expires_at->toDateString());
    }

    public function test_customer_cards_offer_expiry_edit_for_bandwidth_and_payment_for_standard(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'cards@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'isp');
        foreach (['bandwidth_only', 'standard'] as $type) {
            $branch = \App\Isp\Models\Branch::create(['name' => $type, 'billing_type' => $type]);
            $customer = new Customer(['name' => 'Card subscriber', 'username' => 'card-sub', 'branch_id' => $branch->id, 'status' => 'active', 'expires_at' => today()->subDay()]);
            $customer->id = 123;
            $customer->setRelation('branch', $branch)->setRelation('routerPayment', null)->setRelation('router', null)->setRelation('package', null);
            $customer->setAttribute('usage_download_bytes', 0)->setAttribute('usage_upload_bytes', 0);
            $view = $this->view('isp.customers.index', [
                'customers' => new \Illuminate\Pagination\LengthAwarePaginator(collect([$customer]), 1, 15),
                'errors' => new \Illuminate\Support\ViewErrorBag,
                'customerSummary' => ['active' => 0, 'needs_attention' => 1], 'routers' => collect(), 'branches' => collect([$branch]),
            ]);
            if ($type === 'bandwidth_only') {
                $view->assertSee('Activate / Set expiry')->assertDontSee('Collect payment')->assertDontSee(route('isp.payments.index', ['customer' => $customer]));
            } else {
                $view->assertSee('Collect payment')->assertSee('Collect payment to restore access')->assertDontSee('Activate / Set expiry');
            }
        }
    }

    private function insertPayment(
        int $customerId,
        int $packageId,
        int $operatorId,
        string $paidAt,
        float $amount,
        float $packageAmount,
        float $commission,
    ): void {
        DB::table('payments')->insert([
            'customer_id' => $customerId,
            'package_id' => $packageId,
            'operator_id' => $operatorId,
            'package_amount' => $packageAmount,
            'operator_commission' => $commission,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    }
}
