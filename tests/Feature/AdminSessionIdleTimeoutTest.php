<?php

namespace Tests\Feature;

use App\Http\Controllers\TokenController;
use App\Http\Middleware\AdminTokenMiddleware;
use App\Models\SessionTokenModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSessionIdleTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('admin_uid');
        });
        DB::table('admin_users')->insert(['admin_uid' => 'admin-1']);

        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            $table->string('uid');
            $table->string('auth_phone')->nullable();
        });

        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('access_token');
            $table->string('refresh_token');
            $table->dateTime('access_expires_at');
            $table->dateTime('refresh_expires_at');
            $table->timestamps();
        });
    }

    public function test_active_admin_requests_extend_the_idle_deadline(): void
    {
        $session = $this->createSessionRecord(lastActivity: now()->subDays(6));
        $request = Request::create('/api/v4/admin/users', 'GET');
        $request->merge(['auth_user_id' => 'admin-1']);
        $request->attributes->set('auth_session_token_id', $session->id);

        $response = app(AdminTokenMiddleware::class)->handle(
            $request,
            fn () => response('', 204)
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertTrue($session->fresh()->updated_at->isAfter(now()->subMinute()));
    }

    public function test_admin_session_is_revoked_after_seven_days_of_inactivity(): void
    {
        $session = $this->createSessionRecord(lastActivity: now()->subDays(7));
        $request = Request::create('/api/v4/admin/users', 'GET');
        $request->merge(['auth_user_id' => 'admin-1']);
        $request->attributes->set('auth_session_token_id', $session->id);

        $response = app(AdminTokenMiddleware::class)->handle(
            $request,
            fn () => response('', 204)
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertDatabaseMissing('session_tokens', ['id' => $session->id]);
    }

    public function test_idle_admin_cannot_keep_session_alive_by_refreshing(): void
    {
        $session = $this->createSessionRecord(
            lastActivity: now()->subDays(7)->subSecond(),
            refreshToken: 'idle-refresh-token'
        );

        $response = app(TokenController::class)->refresh(Request::create(
            '/api/v4/auth/tokens/refresh',
            'POST',
            ['refresh_token' => 'idle-refresh-token']
        ));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertDatabaseMissing('session_tokens', ['id' => $session->id]);
    }

    public function test_active_admin_refresh_keeps_the_rolling_thirty_day_lifetime(): void
    {
        $session = $this->createSessionRecord(
            refreshToken: 'active-refresh-token',
            refreshExpiresAt: now()->addDay()
        );

        $response = app(TokenController::class)->refresh(Request::create(
            '/api/v4/auth/tokens/refresh',
            'POST',
            ['refresh_token' => 'active-refresh-token']
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(
            now()->addDays(29)->isBefore(
                \Illuminate\Support\Carbon::parse($response->getData(true)['refresh_expires_at'])
            )
        );
        $this->assertTrue(
            now()->addDays(29)->isBefore(
                \Illuminate\Support\Carbon::parse(
                    DB::table('session_tokens')->where('id', $session->id)->value('refresh_expires_at')
                )
            )
        );
    }

    public function test_seven_day_idle_timeout_does_not_change_customer_sessions(): void
    {
        $this->createSessionRecord(
            lastActivity: now()->subDays(7),
            refreshToken: 'customer-refresh-token',
            userId: 'customer-1'
        );

        $response = app(TokenController::class)->refresh(Request::create(
            '/api/v4/auth/tokens/refresh',
            'POST',
            ['refresh_token' => 'customer-refresh-token']
        ));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function createSessionRecord(
        ?\Illuminate\Support\Carbon $lastActivity = null,
        string $refreshToken = 'refresh-token',
        string $userId = 'admin-1',
        ?\Illuminate\Support\Carbon $refreshExpiresAt = null
    ): SessionTokenModel {
        $lastActivity ??= now();

        $session = SessionTokenModel::create([
            'user_id' => $userId,
            'access_token' => SessionTokenModel::digest('access-token'),
            'refresh_token' => SessionTokenModel::digest($refreshToken),
            'access_expires_at' => now()->addHour(),
            'refresh_expires_at' => $refreshExpiresAt ?? now()->addDays(30),
        ]);

        DB::table('session_tokens')->where('id', $session->id)->update([
            'created_at' => $lastActivity,
            'updated_at' => $lastActivity,
        ]);

        return $session->fresh();
    }
}
