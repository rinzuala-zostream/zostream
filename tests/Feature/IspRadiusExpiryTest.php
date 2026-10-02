<?php

namespace Tests\Feature;

use App\Isp\Models\Customer;
use App\Isp\Models\Package;
use App\Isp\Models\Router;
use App\Isp\Services\MikroTikService;
use App\Isp\Services\RadiusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspRadiusExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('router_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('username')->unique();
            $table->text('password');
            $table->string('status');
            $table->date('expires_at')->nullable();
            $table->string('mikrotik_id')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('radcheck', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op');
            $table->string('value');
        });
        Schema::create('radreply', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op');
            $table->string('value');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
        Schema::dropIfExists('customers');
        parent::tearDown();
    }

    public function test_manual_expiry_rejects_radius_and_renewal_restores_credentials(): void
    {
        $router = new Router(['is_active' => true]);
        $router->setAttribute('id', 10);
        $package = new Package(['rate_limit' => '10M/10M']);
        $package->setAttribute('id', 20);
        $customer = Customer::withoutEvents(fn (): Customer => Customer::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'username' => 'manual-expiry-user',
            'password' => 'radius-secret',
            'status' => 'active',
            'expires_at' => today()->subDay(),
        ]));
        $customer->setRelation('router', $router);
        $customer->setRelation('package', $package);

        $mikrotik = Mockery::mock(MikroTikService::class);
        $mikrotik->shouldReceive('disconnectPppUser')->once()
            ->with($router, 'manual-expiry-user')->andReturn(1);
        $radius = new RadiusService($mikrotik);

        $expired = $radius->syncCustomer($customer);

        $this->assertFalse($expired['active']);
        $this->assertSame(1, $expired['disconnected']);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'manual-expiry-user',
            'attribute' => 'Auth-Type',
            'op' => ':=',
            'value' => 'Reject',
        ]);
        $this->assertDatabaseMissing('radcheck', [
            'username' => 'manual-expiry-user',
            'attribute' => 'Cleartext-Password',
        ]);

        $customer->forceFill(['expires_at' => today()->addDays(30)])->saveQuietly();
        $active = $radius->syncCustomer($customer);

        $this->assertTrue($active['active']);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'manual-expiry-user',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'radius-secret',
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'manual-expiry-user',
            'attribute' => 'Expiration',
        ]);
        $this->assertDatabaseHas('radreply', [
            'username' => 'manual-expiry-user',
            'attribute' => 'Mikrotik-Rate-Limit',
            'value' => '10M/10M',
        ]);
        $this->assertSame(2, DB::table('radcheck')->where('username', 'manual-expiry-user')->count());
    }
}
