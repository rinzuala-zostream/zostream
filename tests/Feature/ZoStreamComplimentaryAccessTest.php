<?php

namespace Tests\Feature;

use App\Http\Controllers\New\SubscriptionController;
use App\Isp\Models\Customer;
use App\Isp\Services\ZoStreamSubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ZoStreamComplimentaryAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('user', function (Blueprint $table): void {
            $table->id('num');
            $table->string('uid')->unique();
            $table->string('auth_phone')->nullable();
            $table->string('name')->nullable();
            $table->string('created_date')->nullable();
            $table->string('device_name')->nullable();
            $table->boolean('isACActive')->default(false);
            $table->boolean('isAccountComplete')->default(false);
            $table->boolean('is_auth_phone_active')->default(false);
        });
        Schema::create('n_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('device_type');
            $table->integer('duration_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('n_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('is_active');
            $table->string('renewed_by')->nullable();
            $table->timestamps();
        });

        DB::table('n_plans')->insert([
            ['id' => 22, 'name' => 'Mobile', 'device_type' => 'mobile', 'duration_days' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 24, 'name' => 'TV', 'device_type' => 'tv', 'duration_days' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('n_subscriptions');
        Schema::dropIfExists('n_plans');
        Schema::dropIfExists('user');

        parent::tearDown();
    }

    public function test_it_creates_an_account_and_idempotent_mobile_and_tv_access(): void
    {
        $customer = new Customer([
            'name' => 'ISP Customer',
            'phone' => '+91 98765 43210',
            'expires_at' => today()->addDays(29),
        ]);
        $service = new ZoStreamSubscriptionService(Mockery::mock(SubscriptionController::class));

        $first = $service->activateComplimentaryAccess($customer);
        $second = $service->activateComplimentaryAccess($customer);

        $this->assertTrue($first['user_created']);
        $this->assertFalse($second['user_created']);
        $this->assertDatabaseHas('user', ['auth_phone' => '9876543210', 'name' => 'ISP Customer']);
        $this->assertDatabaseCount('n_subscriptions', 2);
        $this->assertEqualsCanonicalizing([22, 24], DB::table('n_subscriptions')->pluck('plan_id')->all());
        $this->assertSame(2, collect($second['subscriptions'])->count());
    }
}
