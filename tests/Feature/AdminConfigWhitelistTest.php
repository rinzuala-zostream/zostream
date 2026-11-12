<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminConfigWhitelistMiddleware;
use App\Support\AdminAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminConfigWhitelistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin_qr.allowed_uids' => ['allowed-uid'],
            'services.admin_whatsapp.allowed_numbers' => ['9378156399']]);
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            $table->string('uid');
            $table->string('auth_phone')->nullable();
        });
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->string('user_id');
            $table->string('access_token');
            $table->dateTime('access_expires_at');
        });
        Schema::create('admin_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('admin_uid');
        });
        DB::table('user')->insert([
            ['uid' => 'phone-admin', 'auth_phone' => '+91 93781 56399'],
            ['uid' => 'ordinary-user', 'auth_phone' => '9999999999'],
        ]);
    }

    private function authorize(string $uid, bool $expired = false): int
    {
        DB::table('session_tokens')->insert([
            'user_id' => $uid,
            'access_token' => 'test-token',
            'access_expires_at' => $expired ? now()->subMinute() : now()->addHour(),
        ]);
        $request = Request::create('/config', 'PUT', [
            'auth_user_id' => 'allowed-uid', 'phone' => '9378156399',
        ]);
        $request->headers->set('Authorization', 'Bearer test-token');

        return app(AdminConfigWhitelistMiddleware::class)->handle(
            $request, fn () => response('', 204)
        )->getStatusCode();
    }

    public function test_uid_whitelist_allows_configuration_writes(): void
    {
        $this->assertSame(204, $this->authorize('allowed-uid'));
    }

    public function test_registered_phone_whitelist_allows_configuration_writes(): void
    {
        $this->assertSame(204, $this->authorize('phone-admin'));
    }

    public function test_body_identity_cannot_grant_access_to_an_ordinary_user(): void
    {
        $this->assertSame(403, $this->authorize('ordinary-user'));
    }

    public function test_admin_table_membership_alone_cannot_grant_configuration_write_access(): void
    {
        DB::table('admin_users')->insert(['admin_uid' => 'ordinary-user']);
        $this->assertTrue(app(AdminAccess::class)->isAdmin('ordinary-user'));
        $this->assertSame(403, $this->authorize('ordinary-user'));
    }

    public function test_expired_whitelisted_session_is_denied(): void
    {
        $this->assertSame(403, $this->authorize('allowed-uid', expired: true));
    }

    public function test_missing_session_is_denied_even_with_a_whitelisted_body_uid(): void
    {
        $request = Request::create('/config', 'PUT', ['auth_user_id' => 'allowed-uid']);
        $this->assertSame(403, app(AdminConfigWhitelistMiddleware::class)->handle(
            $request, fn () => response('', 204)
        )->getStatusCode());
    }

    public function test_config_write_routes_require_authentication_and_the_whitelist(): void
    {
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $writesConfig = str_contains($action, 'AdminRealtimeConfigController@')
                || str_ends_with($action, 'AdminAnalyticsController@updateConfig')
                || str_ends_with($action, 'AppUpdateController@update')
                || str_ends_with($action, 'DesktopReleaseUploadController');
            if (! $writesConfig || ! array_intersect(['PUT', 'POST', 'DELETE'], $route->methods())) {
                continue;
            }
            $count++;
            $this->assertContains('auth.token', $route->gatherMiddleware(), $route->uri());
            $this->assertContains('admin.config-whitelist', $route->gatherMiddleware(), $route->uri());
        }
        $this->assertSame(12, $count);
    }
}
