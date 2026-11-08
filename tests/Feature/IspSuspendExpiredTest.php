<?php

namespace Tests\Feature;

use App\Isp\Models\Customer;
use App\Isp\Services\RadiusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class IspSuspendExpiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->date('expires_at')->nullable();
            $table->date('expiry_suspended_for')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('customers');

        parent::tearDown();
    }

    public function test_it_processes_expired_access_without_changing_manual_status(): void
    {
        $expiredId = DB::table('customers')->insertGetId([
            'name' => 'Expired Customer',
            'status' => 'active',
            'expires_at' => today()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            [
                'name' => 'Valid Today',
                'status' => 'active',
                'expires_at' => today()->toDateString(),
                'expiry_suspended_for' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Already Processed',
                'status' => 'suspended',
                'expires_at' => today()->subDays(2)->toDateString(),
                'expiry_suspended_for' => today()->subDays(2)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $radius = Mockery::mock(RadiusService::class);
        $radius->shouldReceive('syncCustomer')
            ->once()
            ->with(Mockery::on(fn (Customer $customer): bool => $customer->id === $expiredId))
            ->andReturn(['active' => false, 'disconnected' => 1]);
        $this->app->instance(RadiusService::class, $radius);

        $this->artisan('isp:suspend-expired')
            ->expectsOutput("Processed expired customer #{$expiredId}; disconnected 1 session(s).")
            ->expectsOutput('ISP expiry processing complete: 1 processed, 0 failed, 0 skipped.')
            ->assertSuccessful();

        $expired = DB::table('customers')->find($expiredId);
        $this->assertSame('active', $expired->status);
        $this->assertSame(today()->subDay()->toDateString(), substr($expired->expiry_suspended_for, 0, 10));

        $this->assertDatabaseHas('customers', [
            'name' => 'Valid Today',
            'status' => 'active',
        ]);

        $this->artisan('isp:suspend-expired')
            ->expectsOutput('ISP expiry processing complete: 0 processed, 0 failed, 0 skipped.')
            ->assertSuccessful();
    }

    public function test_failed_radius_sync_is_left_unprocessed_for_a_later_retry(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Retry Customer',
            'status' => 'active',
            'expires_at' => today()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $radius = Mockery::mock(RadiusService::class);
        $radius->shouldReceive('syncCustomer')
            ->once()
            ->andThrow(new RuntimeException('Router unavailable'));
        $this->app->instance(RadiusService::class, $radius);

        $this->artisan('isp:suspend-expired')
            ->expectsOutput("Failed to process expired customer #{$customerId}: Router unavailable")
            ->expectsOutput('ISP expiry processing complete: 0 processed, 1 failed, 0 skipped.')
            ->assertFailed();

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'expiry_suspended_for' => null,
        ]);
    }
}
