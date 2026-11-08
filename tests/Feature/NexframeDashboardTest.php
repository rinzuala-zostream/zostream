<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NexframeDashboardTest extends TestCase
{
    public function test_session_exposes_partnership_date_and_earlier_ranges_are_rejected(): void
    {
        config()->set('services.nexframe_dashboard.partnership_started_at', '2026-10-14');
        $this->get('/analytics/nexframe')->assertOk()
            ->assertSee('<meta name="nexframe-partnership-start" content="2026-10-14"', false);
        $this->getJson('/analytics/nexframe/session')->assertOk()
            ->assertJsonPath('partnership_started_at', '2026-10-14')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->withSession(['nexframe_dashboard_authenticated' => true])
            ->getJson('/analytics/nexframe/data?from=2026-10-13&to=2026-10-16')
            ->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_dashboard_is_standalone_and_data_requires_its_own_session(): void
    {
        config()->set('services.nexframe_dashboard.email');
        config()->set('services.nexframe_dashboard.password_hash');

        $this->get('/analytics/nexframe')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->getJson('/analytics/nexframe/session')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'configured' => false,
            ]);

        $this->getJson('/analytics/nexframe/data')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'NextFrame dashboard authentication is required.');

    }

    public function test_dashboard_uses_credentials_separate_from_admin_authentication(): void
    {
        config()->set('services.nexframe_dashboard.email', 'partner@example.com');
        config()->set('services.nexframe_dashboard.password_hash', Hash::make('secret-passphrase'));

        $this->postJson('/analytics/nexframe/login', [
            'email' => 'partner@example.com',
            'password' => 'secret-passphrase',
        ])->assertOk()->assertJson(['authenticated' => true]);

        $this->getJson('/analytics/nexframe/session')
            ->assertOk()
            ->assertJson(['authenticated' => true, 'configured' => true]);

        $this->postJson('/analytics/nexframe/logout')
            ->assertOk()
            ->assertJson(['authenticated' => false]);
    }
}
