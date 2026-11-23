<?php

namespace Tests\Feature;

use App\Http\Controllers\New\DeviceController;
use App\Http\Controllers\New\UserController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUsernameSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            foreach (['uid', 'name', 'username', 'mail', 'call', 'auth_phone', 'device_id', 'device_name'] as $column) {
                $table->string($column)->nullable();
            }
        });
        DB::table('user')->insert([
            ['uid' => 'uid-one', 'name' => 'First viewer', 'username' => 'zoviewer', 'mail' => 'one@example.com', 'auth_phone' => '9876543210'],
            ['uid' => 'uid-two', 'name' => 'Second viewer', 'username' => 'zoviewer_extra', 'mail' => 'two@example.com', 'auth_phone' => '9876543211'],
            ['uid' => 'uid-three', 'name' => 'Unrelated', 'username' => 'other', 'mail' => 'three@example.com', 'auth_phone' => '9876543212'],
        ]);
    }

    public function test_username_search_accepts_plain_and_at_prefixed_terms_and_ranks_exact_match_first(): void
    {
        foreach (['zoviewer', '@zoviewer'] as $term) {
            $response = app(UserController::class)->search(Request::create('/users-search', 'GET', ['q' => $term]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['uid-one', 'uid-two'], array_column($response->getData(true)['data']['data'], 'uid'));
        }
    }

    public function test_explicit_username_and_existing_identity_searches_work(): void
    {
        foreach ([['username' => '@other'], ['q' => 'uid-three'], ['q' => 'three@example.com'], ['q' => 'Unrelated']] as $query) {
            $response = app(UserController::class)->search(Request::create('/users-search', 'GET', $query));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('uid-three', $response->getData(true)['data']['data'][0]['uid']);
        }
    }

    public function test_device_search_matches_username_without_returning_other_users_devices(): void
    {
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->timestamps();
        });
        DB::table('n_devices')->insert([
            ['user_id' => 'uid-one'], ['user_id' => 'uid-three'],
        ]);
        $response = app(DeviceController::class)->search(Request::create('/devices/search', 'GET', ['q' => '@zoviewer']));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['uid-one'], array_column($response->getData(true)['data']['data'], 'user_id'));
    }
    public function test_subscribers_can_be_found_by_username_and_device_filters_still_apply(): void
    {
        Schema::create('n_plans', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('device_type');
        });
        Schema::create('n_subscriptions', function (Blueprint $table) {
            $table->id(); $table->string('user_id'); $table->unsignedInteger('plan_id'); $table->timestamps();
        });
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('subscription_id')->nullable();
        });
        DB::table('n_plans')->insert([
            ['id' => 1, 'name' => 'Monthly', 'device_type' => 'mobile'],
            ['id' => 2, 'name' => 'Yearly', 'device_type' => 'desktop'],
        ]);
        DB::table('n_subscriptions')->insert([
            ['user_id' => 'uid-one', 'plan_id' => 1],
            ['user_id' => 'uid-two', 'plan_id' => 2],
            ['user_id' => 'uid-three', 'plan_id' => 1],
        ]);
        $controller = new \App\Http\Controllers\New\SubscriptionController(
            \Mockery::mock(\App\Http\Controllers\RazorpayController::class),
            \Mockery::mock(\App\Http\Controllers\NewStreamController::class)
        );
        $response = $controller->searchSubscribers(Request::create('/subscriptions/search', 'GET', [
            'q' => '@zoviewer', 'device_type' => 'mobile',
        ]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['uid-one'], array_column($response->getData(true)['data']['data'], 'user_id'));
        $this->assertSame('zoviewer', $response->getData(true)['data']['data'][0]['user']['username']);
    }

}
