<?php

namespace Tests\Feature;

use App\Isp\Http\Controllers\CustomerController;
use App\Isp\Models\Customer;
use App\Isp\Models\Router;
use App\Isp\Models\User;
use App\Isp\Services\MikroTikService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspCustomerAttentionTest extends TestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status');
            $table->date('expires_at')->nullable();
            $table->string('router_device_condition')->nullable();
            $table->string('aadhaar_front_path')->nullable();
            $table->string('aadhaar_back_path')->nullable();
        });
        Schema::create('customer_router_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->string('status');
        });

        $this->ids['healthy'] = $this->customer('active', today()->addDay(), 'old');
        $this->ids['suspended'] = $this->customer('suspended', today()->addDay(), 'old');
        $this->ids['expired'] = $this->customer('active', today()->subDay(), 'old');
        $this->ids['unknown_device'] = $this->customer('active', today()->addDay(), null);
        $this->ids['unpaid_device'] = $this->customer('active', today()->addDay(), 'new');
        $this->ids['paid_device'] = $this->customer('active', today()->addDay(), 'new');
        $this->ids['other_branch_unknown'] = $this->customer('active', today()->addDay(), null, 2);
        $this->ids['missing_aadhaar'] = $this->customer('active', today()->addDay(), 'old', 1, false);

        DB::table('customer_router_payments')->insert([
            ['customer_id' => $this->ids['unpaid_device'], 'status' => 'unpaid'],
            ['customer_id' => $this->ids['paid_device'], 'status' => 'paid'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('customer_router_payments');
        Schema::dropIfExists('customers');
        parent::tearDown();
    }

    public function test_summary_counts_all_accessible_customers_instead_of_the_current_page_or_filters(): void
    {
        $summary = $this->summary($this->request('admin', null, [
            'status' => 'suspended',
        ]));

        $this->assertSame(6, $summary['active']);
        $this->assertSame(6, $summary['needs_attention']);
    }

    public function test_attention_filter_includes_device_issues_and_respects_operator_branch(): void
    {
        $request = $this->request('branch_operator', 1, ['status' => 'attention']);

        $this->assertSame(5, $this->summary($request)['needs_attention']);
        $this->assertSame([
            $this->ids['suspended'],
            $this->ids['expired'],
            $this->ids['unknown_device'],
            $this->ids['unpaid_device'],
            $this->ids['missing_aadhaar'],
        ], $this->attentionIds($request));
    }

    public function test_expired_and_suspended_filters_are_mutually_exclusive(): void
    {
        $expiredSuspended = $this->customer('suspended', today()->subDay(), 'old');

        $this->assertNotContains(
            $expiredSuspended,
            $this->filteredIds($this->request('admin', null, ['status' => 'suspended'])),
        );
        $this->assertContains(
            $expiredSuspended,
            $this->filteredIds($this->request('admin', null, ['status' => 'expired'])),
        );
    }

    public function test_live_status_marks_disconnected_active_customers_as_offline(): void
    {
        $router = new Router(['is_active' => true]);
        $router->setAttribute('id', 987654);
        $online = $this->liveCustomer('online-user', $router, today()->addDay());
        $offline = $this->liveCustomer('offline-user', $router, today()->addDay());
        $expired = $this->liveCustomer('expired-user', $router, today()->subDay());
        Cache::forget('dashboard.router.987654.ppp-active');

        $mikrotik = Mockery::mock(MikroTikService::class);
        $mikrotik->shouldReceive('activePppUsers')->once()->with($router)->andReturn([
            ['name' => 'online-user'],
        ]);

        (function ($customers, MikroTikService $mikrotik): void {
            $this->attachLiveStatuses($customers, $mikrotik);
        })->call(new CustomerController, collect([$online, $offline, $expired]), $mikrotik);

        $this->assertSame('online', $online->live_connection_status);
        $this->assertSame('offline', $offline->live_connection_status);
        $this->assertNull($expired->live_connection_status);
    }

    private function customer(
        string $status,
        mixed $expiresAt,
        ?string $condition,
        int $branchId = 1,
        bool $hasAadhaar = true,
    ): int
    {
        return DB::table('customers')->insertGetId([
            'branch_id' => $branchId,
            'status' => $status,
            'expires_at' => $expiresAt->toDateString(),
            'router_device_condition' => $condition,
            'aadhaar_front_path' => $hasAadhaar ? 'isp/customers/aadhaar/front.jpg' : null,
            'aadhaar_back_path' => $hasAadhaar ? 'isp/customers/aadhaar/back.jpg' : null,
        ]);
    }

    private function request(string $role, ?int $branchId, array $query = []): Request
    {
        $request = Request::create('/isp/customers', 'GET', $query);
        $request->setUserResolver(fn (): User => new User([
            'role' => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]));

        return $request;
    }

    private function liveCustomer(string $username, Router $router, mixed $expiresAt): Customer
    {
        $customer = new Customer([
            'router_id' => $router->id,
            'username' => $username,
            'status' => 'active',
            'expires_at' => $expiresAt,
        ]);
        $customer->setRelation('router', $router);

        return $customer;
    }

    private function summary(Request $request): array
    {
        return (function (Request $request): array {
            return $this->customerSummary($request);
        })->call(new CustomerController, $request);
    }

    private function attentionIds(Request $request): array
    {
        return $this->filteredIds($request);
    }

    private function filteredIds(Request $request): array
    {
        return (function (Request $request): array {
            return $this->filteredQuery($request)->orderBy('id')->pluck('id')->all();
        })->call(new CustomerController, $request);
    }
}
