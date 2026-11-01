<?php

namespace Tests\Feature;

use App\Models\SessionTokenModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            $table->string('uid')->unique();
            $table->string('name')->nullable();
        });
        (require database_path('migrations/2026_11_01_000001_add_mobile_profile_fields_to_user.php'))->up();
        (require database_path('migrations/2026_11_01_000002_add_gender_to_mobile_profile.php'))->up();
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('device_id');
            $table->string('access_token');
            $table->string('refresh_token');
            $table->dateTime('access_expires_at');
            $table->dateTime('refresh_expires_at');
            $table->timestamps();
        });
        foreach (['alice', 'bob'] as $uid) {
            DB::table('user')->insert(['uid' => $uid, 'name' => ucfirst($uid)]);
            SessionTokenModel::create([
                'user_id' => $uid, 'device_id' => $uid.'-phone',
                'access_token' => SessionTokenModel::digest($uid.'-token'),
                'refresh_token' => 'refresh-'.$uid,
                'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDay(),
            ]);
        }
    }

    public function test_authentication_is_required_and_client_identity_cannot_override_session_owner(): void
    {
        $this->getJson('/api/v4/account/mobile-profile')->assertUnauthorized();
        $this->withToken('alice-token')->postJson('/api/v4/account/mobile-profile', [
            ...$this->validProfile(), 'auth_user_id' => 'bob', 'user_id' => 'bob',
        ])->assertOk()->assertJsonPath('data.user_id', 'alice')->assertJsonPath('data.complete', true);
        $this->assertNull(DB::table('user')->where('uid', 'bob')->value('username'));
    }

    public function test_server_controls_completion_and_same_user_can_retry_after_reinstall(): void
    {
        $this->withToken('alice-token')->getJson('/api/v4/account/mobile-profile')
            ->assertOk()->assertJsonPath('data.complete', false);
        $this->postJson('/api/v4/account/mobile-profile', $this->validProfile())
            ->assertOk()->assertJsonPath('data.complete', true)->assertJsonPath('data.username', 'alice_01');
        $this->getJson('/api/v4/account/mobile-profile')->assertJsonPath('data.complete', true);
        $this->getJson('/api/v4/account/mobile-profile/username?username=ALICE_01')
            ->assertOk()->assertJsonPath('data.available', true);
        $this->postJson('/api/v4/account/mobile-profile', $this->validProfile())->assertOk();
        $this->assertSame('Alice', DB::table('user')->where('uid', 'alice')->value('name'));
        // A stale local completion flag cannot hide missing database fields.
        DB::table('user')->where('uid', 'alice')->update(['profile_village_code' => null]);
        $this->getJson('/api/v4/account/mobile-profile')->assertJsonPath('data.complete', false);
    }

    public function test_case_variants_are_unavailable_and_submit_rechecks_claimed_username(): void
    {
        $this->withToken('bob-token')->getJson('/api/v4/account/mobile-profile/username?username=alice_01')
            ->assertJsonPath('data.available', true);
        $this->withToken('alice-token')->postJson('/api/v4/account/mobile-profile', $this->validProfile())->assertOk();
        $this->withToken('bob-token')->getJson('/api/v4/account/mobile-profile/username?username=ALICE_01')
            ->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v4/account/mobile-profile', $this->validProfile())
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['username']]]);
        $this->assertNull(DB::table('user')->where('uid', 'bob')->value('username'));
    }

    public function test_database_unique_index_also_prevents_duplicate_claims(): void
    {
        DB::table('user')->where('uid', 'alice')->update(['username' => 'unique_name']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('user')->where('uid', 'bob')->update(['username' => 'unique_name']);
    }

    public function test_invalid_username_and_wrong_district_village_pair_are_rejected_atomically(): void
    {
        $this->withToken('alice-token');
        foreach (['ab', 'bad name', 'name@bad', str_repeat('a', 31)] as $username) {
            $this->getJson('/api/v4/account/mobile-profile/username?'.http_build_query(['username' => $username]))
                ->assertUnprocessable();
            $this->postJson('/api/v4/account/mobile-profile', [...$this->validProfile(), 'username' => $username])
                ->assertUnprocessable();
        }
        $this->postJson('/api/v4/account/mobile-profile', [...$this->validProfile(), 'district_code' => '261'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['village_code']]]);
        $this->assertNull(DB::table('user')->where('uid', 'alice')->value('username'));
    }

    public function test_directory_contains_all_eleven_districts_and_887_distinct_villages(): void
    {
        $districts = $this->withToken('alice-token')->getJson('/api/v4/account/mobile-profile/locations')
            ->assertOk()->json('data.districts');
        $this->assertCount(11, $districts);
        $villages = collect($districts)->flatMap(fn ($district) => $district['villages']);
        $this->assertCount(887, $villages);
        $this->assertCount(887, $villages->unique('lgd_code'));
    }

    private function validProfile(): array
    {
        return ['username' => '  ALICE_01  ', 'district_code' => '266', 'village_code' => '271013', 'gender' => 'male'];
    }

    public function test_username_is_immutable_while_gender_and_location_remain_editable(): void
    {
        $this->withToken('alice-token')->postJson('/api/v4/account/mobile-profile', $this->validProfile())->assertOk();
        $this->postJson('/api/v4/account/mobile-profile', [...$this->validProfile(), 'username' => 'different_name', 'gender' => 'female'])
            ->assertUnprocessable()->assertJsonPath('error.details.username.0', 'Your username cannot be changed.');
        $this->assertSame('male', DB::table('user')->where('uid', 'alice')->value('profile_gender'));
        $this->postJson('/api/v4/account/mobile-profile', [...$this->validProfile(), 'gender' => 'prefer_not_to_say', 'village_code' => '271014'])
            ->assertOk()->assertJsonPath('data.username', 'alice_01')->assertJsonPath('data.gender', 'prefer_not_to_say')
            ->assertJsonPath('data.village_code', '271014')->assertJsonPath('data.complete', true);
    }

    public function test_missing_or_invalid_gender_keeps_profile_incomplete(): void
    {
        foreach ([null, 'invalid'] as $gender) {
            $this->withToken('alice-token')->postJson('/api/v4/account/mobile-profile', [...$this->validProfile(), 'gender' => $gender])->assertUnprocessable();
        }
        $this->postJson('/api/v4/account/mobile-profile', $this->validProfile())->assertOk();
        DB::table('user')->where('uid', 'alice')->update(['profile_gender' => null]);
        $this->getJson('/api/v4/account/mobile-profile')->assertJsonPath('data.complete', false);
    }
}
