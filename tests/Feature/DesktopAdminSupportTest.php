<?php

namespace Tests\Feature;

use App\Http\Controllers\New\DeviceController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DesktopAdminSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'desktop_admin_testing', 'database.connections.desktop_admin_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        Schema::create('user', function (Blueprint $table) {
            $table->id('num'); $table->string('uid'); $table->string('name')->nullable();
            $table->string('mail')->nullable(); $table->string('call')->nullable(); $table->string('auth_phone')->nullable();
        });
        DB::table('user')->insert([['uid' => 'user-a', 'name' => 'Alice'], ['uid' => 'user-b', 'name' => 'Bob']]);
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id(); $table->string('user_id'); $table->integer('subscription_id')->nullable();
            $table->string('device_name'); $table->string('device_type'); $table->string('platform')->nullable();
            $table->string('device_token'); $table->boolean('is_owner_device')->default(false);
            $table->string('status')->default('active'); $table->dateTime('last_activity')->nullable(); $table->timestamps();
        });
        Schema::create('n_active_streams', function (Blueprint $table) {
            $table->id(); $table->integer('device_id'); $table->string('status'); $table->dateTime('last_ping')->nullable();
        });
        foreach ([['mobile', null], ['tv', null], ['browser', null], ['desktop', 'macos'], ['desktop', 'windows']] as [$type, $platform]) {
            $id = DB::table('n_devices')->insertGetId(['user_id' => 'user-a', 'device_name' => $platform ?? $type,
                'device_type' => $type, 'platform' => $platform, 'device_token' => $platform ?? $type, 'status' => 'active']);
            DB::table('n_active_streams')->insert(['device_id' => $id, 'status' => 'active']);
        }
        DB::table('n_devices')->insert(['user_id' => 'user-b', 'device_name' => 'Other Mac', 'device_type' => 'desktop',
            'platform' => 'macos', 'device_token' => 'other-mac', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        DB::purge('desktop_admin_testing');
        parent::tearDown();
    }

    public function test_admin_lists_and_search_filter_desktop_and_platform_on_the_server(): void
    {
        $controller = new DeviceController();
        $desktop = $controller->index(Request::create('/', 'GET', ['device_type' => 'desktop']))->getData(true)['data'];
        $this->assertSame(3, $desktop['total']);
        $this->assertSame(['desktop'], array_values(array_unique(array_column($desktop['data'], 'device_type'))));
        $windows = $controller->index(Request::create('/', 'GET', ['platform' => 'windows']))->getData(true)['data'];
        $this->assertSame(1, $windows['total']);
        $this->assertSame('windows', $windows['data'][0]['platform']);
        $search = $controller->search(Request::create('/', 'GET', ['q' => 'Alice', 'platform' => 'macos']))->getData(true)['data'];
        $this->assertSame(1, $search['total']);
        $this->assertSame('user-a', $search['data'][0]['user_id']);
    }

    public function test_account_device_list_keeps_user_scope_while_filtering_desktop(): void
    {
        $data = (new DeviceController())->getByUser(Request::create('/', 'GET', ['device_type' => 'desktop']), 'user-a')->getData(true)['data'];
        $this->assertSame(2, $data['total']);
        $this->assertEqualsCanonicalizing(['macos', 'windows'], array_column($data['data'], 'platform'));
        $this->assertSame(['user-a'], array_values(array_unique(array_column($data['data'], 'user_id'))));
    }

    public function test_clearing_a_mac_stops_only_its_stream_and_keeps_windows_and_legacy_devices(): void
    {
        $mac = DB::table('n_devices')->where('device_token', 'macos')->first();
        $response = (new DeviceController())->clear(Request::create('/', 'POST', [
            'user_id' => 'user-a', 'device_type' => 'desktop', 'platform' => 'macos',
        ]));
        $this->assertSame(1, $response->getData(true)['deleted_count']);
        $this->assertDatabaseHas('n_active_streams', ['device_id' => $mac->id, 'status' => 'stopped']);
        $this->assertDatabaseMissing('n_devices', ['id' => $mac->id]);
        $this->assertSame(4, DB::table('n_active_streams')->where('status', 'active')->count());
        $this->assertDatabaseHas('n_devices', ['device_token' => 'other-mac']);
        $this->assertDatabaseHas('n_devices', ['device_token' => 'windows']);
        foreach (['mobile', 'tv', 'browser'] as $type) {
            $this->assertDatabaseHas('n_devices', ['device_type' => $type]);
        }
    }

    public function test_mac_and_windows_are_platforms_not_additional_billing_device_types(): void
    {
        $this->expectException(ValidationException::class);
        (new DeviceController())->clear(Request::create('/', 'POST', ['user_id' => 'user-a', 'device_type' => 'macos']));
    }

    public function test_editing_a_device_preserves_its_reported_platform_and_retyping_clears_it(): void
    {
        $id = DB::table('n_devices')->where('device_token', 'macos')->value('id');
        $controller = new DeviceController();
        $controller->update(Request::create('/', 'PUT', ['device_name' => 'Studio Mac', 'device_type' => 'desktop']), $id);
        $this->assertDatabaseHas('n_devices', ['id' => $id, 'device_name' => 'Studio Mac', 'platform' => 'macos']);
        $controller->update(Request::create('/', 'PUT', ['device_type' => 'browser']), $id);
        $this->assertDatabaseHas('n_devices', ['id' => $id, 'platform' => null]);
    }

    public function test_platform_cannot_be_assigned_to_a_mobile_device(): void
    {
        $id = DB::table('n_devices')->where('device_type', 'mobile')->value('id');
        $this->expectException(ValidationException::class);
        (new DeviceController())->update(Request::create('/', 'PUT', ['platform' => 'macos']), $id);
    }
}
