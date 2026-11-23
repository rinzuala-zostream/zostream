<?php

namespace Tests\Feature;

use App\Models\SessionTokenModel;
use App\Models\UserModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUserEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num'); $table->string('uid')->unique(); $table->string('username')->nullable()->unique();
            foreach (['name', 'mail', 'call', 'country_code', 'auth_phone', 'img', 'dob', 'khua', 'veng', 'device_id', 'device_name', 'token', 'profile_district_code', 'profile_village_code', 'profile_gender', 'created_date', 'edit_date', 'lastLogin'] as $field) $table->string($field)->nullable();
            foreach (['isACActive', 'isAccountComplete', 'is_auth_phone_active'] as $field) $table->boolean($field)->nullable();
        });
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id(); $table->string('user_id'); $table->string('access_token'); $table->string('refresh_token')->nullable();
            $table->dateTime('access_expires_at'); $table->dateTime('refresh_expires_at')->nullable(); $table->string('device_id')->nullable(); $table->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $table) { $table->id(); $table->string('admin_uid'); $table->timestamps(); });
        DB::table('admin_users')->insert(['admin_uid' => 'admin']);
        foreach (['admin', 'customer'] as $uid) DB::table('session_tokens')->insert(['user_id' => $uid, 'access_token' => SessionTokenModel::digest($uid.'-token'), 'access_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user')->insert([
            ['uid' => 'viewer', 'username' => 'old_username', 'name' => 'Viewer', 'profile_gender' => 'female', 'profile_district_code' => '261', 'profile_village_code' => 'capital-261', 'token' => 'original-fcm', 'lastLogin' => '2026-01-01 12:13:14', 'isACActive' => true],
            ['uid' => 'other', 'username' => 'taken_username', 'name' => 'Other', 'profile_gender' => null, 'profile_district_code' => null, 'profile_village_code' => null, 'token' => null, 'lastLogin' => null, 'isACActive' => true],
        ]);
    }

    public function test_only_admin_can_read_full_profile_and_location_options(): void
    {
        $this->getJson('/api/v4/admin/users/viewer')->assertUnauthorized();
        $this->withToken('customer-token')->getJson('/api/v4/admin/users/viewer')->assertForbidden();
        $this->withToken('admin-token')->getJson('/api/v4/admin/users/viewer')->assertOk()->assertJsonPath('data.profile_gender', 'female')->assertJsonPath('data.profile_district_code', '261');
        $this->getJson('/api/v4/admin/users/profile-locations')->assertOk()->assertJsonStructure(['data' => ['districts']]);
        $this->assertArrayNotHasKey('profile_gender', UserModel::where('uid', 'viewer')->first()->toArray());
    }

    public function test_admin_can_save_profile_device_flags_and_dates_and_preserve_identifiers(): void
    {
        $fields = [
            'name' => 'Updated Viewer', 'username' => '@NEW_USERNAME', 'mail' => 'updated@example.com', 'call' => '12345', 'country_code' => '+91', 'auth_phone' => '9876543210',
            'img' => 'https://example.com/avatar.png', 'dob' => '2000-01-01', 'khua' => 'Aizawl', 'veng' => 'Locality',
            'profile_gender' => 'other', 'profile_district_code' => '262', 'profile_village_code' => 'capital-262',
            'device_id' => 'device-new', 'device_name' => 'New device', 'token' => 'fcm-new',
            'isACActive' => false, 'isAccountComplete' => true, 'is_auth_phone_active' => true,
            'created_date' => '2025-01-01 10:11:12', 'edit_date' => '2026-02-02 11:12:13', 'lastLogin' => '2026-03-03 12:13:14',
        ];
        $this->withToken('admin-token')->patchJson('/api/v4/admin/users/viewer', $fields)->assertOk()->assertJsonPath('data.username', 'new_username')->assertJsonPath('data.profile_gender', 'other')->assertJsonPath('data.isACActive', false);
        $saved = DB::table('user')->where('uid', 'viewer')->first();
        foreach ($fields as $name => $value) $this->assertEquals($name === 'username' ? 'new_username' : $value, $saved->$name, $name);
        $this->assertSame('viewer', $saved->uid);
    }

    public function test_patch_does_not_clear_untouched_fields_and_rejects_identity_changes(): void
    {
        $this->withToken('admin-token')->patchJson('/api/v4/admin/users/viewer', ['name' => 'Changed only'])->assertOk();
        $this->assertDatabaseHas('user', ['uid' => 'viewer', 'token' => 'original-fcm', 'profile_gender' => 'female', 'lastLogin' => '2026-01-01 12:13:14']);
        $this->patchJson('/api/v4/admin/users/viewer', ['uid' => 'replacement', 'name' => 'Wrong'])->assertUnprocessable();
        $this->assertDatabaseMissing('user', ['uid' => 'replacement']);
        $this->assertDatabaseHas('user', ['uid' => 'viewer', 'name' => 'Changed only']);
    }

    public function test_invalid_profile_or_duplicate_username_cannot_partially_save(): void
    {
        $this->withToken('admin-token');
        foreach ([['username' => 'taken_username'], ['profile_gender' => 'invalid'], ['profile_district_code' => '262', 'profile_village_code' => 'capital-261']] as $invalid) {
            $this->patchJson('/api/v4/admin/users/viewer', ['name' => 'Should not save', ...$invalid])->assertUnprocessable();
            $this->assertDatabaseHas('user', ['uid' => 'viewer', 'name' => 'Viewer']);
        }
        $this->patchJson('/api/v4/admin/users/viewer', ['username' => 'old_username', 'profile_gender' => null, 'profile_district_code' => null, 'profile_village_code' => null])->assertOk()->assertJsonPath('data.profile_gender', null);
    }
    public function test_datetime_local_values_are_normalized_before_database_write(): void
    {
        $this->withToken('admin-token')->patchJson('/api/v4/admin/users/viewer', ['lastLogin' => '2026-02-02T13:14:15'])
            ->assertOk()->assertJsonPath('data.lastLogin', '2026-02-02 13:14:15');
    }

}
