<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\AuthTokenMiddleware;
use App\Models\AppThemeVersion;
use App\Services\Themes\ThemeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppThemeTest extends TestCase
{
    private $migration;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01T00:00:00+05:30'));
        \Carbon\Carbon::setTestNow(CarbonImmutable::now());
        $this->migration = require database_path('migrations/2026_11_11_000001_create_app_theme_tables.php');
        $this->migration->up();
        $this->withoutMiddleware([AuthTokenMiddleware::class, AdminTokenMiddleware::class]);
        Storage::fake('r2');
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function document(array $changes = []): array
    {
        return array_replace(app(ThemeSchema::class)->christmas(), [
            'schedules' => [['start_at' => '2026-12-01T00:00:00+05:30', 'end_at' => '2027-01-01T00:00:00+05:30']],
        ], $changes);
    }

    private function createTheme(string $key = 'christmas_2026', array $changes = []): array
    {
        return $this->postJson('/api/v4/admin/themes', ['key' => $key, 'name' => $key, 'document' => $this->document($changes), 'auth_user_id' => 'test-admin'])
            ->assertCreated()->json('data');
    }

    private function publish(array $theme): array
    {
        $preview = $this->postJson("/api/v4/admin/themes/{$theme['id']}/preview", ['revision' => $theme['revision'], 'platform' => 'web'])->assertOk()->json('data');

        return $this->postJson("/api/v4/admin/themes/{$theme['id']}/publish", [
            'revision' => $theme['revision'], 'preview_token' => $preview['preview_token'], 'approved' => true, 'reason' => 'Test publication', 'auth_user_id' => 'test-admin',
        ])->assertOk()->json('data');
    }

    private function themeAt(string $date, string $platform = 'web')
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($date));
        \Carbon\Carbon::setTestNow(CarbonImmutable::now());

        return $this->getJson('/api/v4/app/theme', ['X-Platform' => $platform]);
    }

    public function test_default_and_draft_isolation(): void
    {
        $this->createTheme();
        $this->themeAt('2026-12-02T00:00:00+05:30')->assertOk()->assertJsonPath('data.theme_key', 'default')->assertJsonPath('data.fallback.theme_key', 'default');
    }

    public function test_exact_ist_boundaries_are_resolved_from_utc_storage(): void
    {
        $theme = $this->publish($this->createTheme());
        $this->assertDatabaseHas('app_theme_schedules', ['start_at' => '2026-11-30 18:30:00', 'end_at' => '2026-12-31 18:30:00']);
        $this->themeAt('2026-11-30T23:59:59+05:30')->assertJsonPath('data.theme_key', 'default');
        $this->themeAt('2026-12-01T00:00:00+05:30')->assertJsonPath('data.theme_key', $theme['key'])
            ->assertJsonPath('data.valid_until', '2026-12-31T18:30:00+00:00');
        $this->themeAt('2027-01-01T00:00:00+05:30')->assertJsonPath('data.theme_key', 'default');
    }

    public function test_overlap_priority_and_transition_refresh(): void
    {
        $this->publish($this->createTheme());
        $this->publish($this->createTheme('new_year', ['priority' => 85, 'schedules' => [['start_at' => '2026-12-31T00:00:00+05:30', 'end_at' => '2027-01-08T00:00:00+05:30']]]));
        $this->themeAt('2026-12-30T23:59:59+05:30')->assertJsonPath('data.theme_key', 'christmas_2026')->assertJsonPath('data.next_refresh_at', '2026-12-30T18:30:00+00:00');
        $this->themeAt('2026-12-31T00:00:00+05:30')->assertJsonPath('data.theme_key', 'new_year');
    }

    public function test_ties_are_deterministic_and_platforms_are_filtered(): void
    {
        $this->publish($this->createTheme('first'));
        $this->publish($this->createTheme('second', ['platforms' => ['web']]));
        $this->themeAt('2026-12-02T00:00:00+05:30')->assertJsonPath('data.theme_key', 'second');
        $this->themeAt('2026-12-02T00:00:00+05:30', 'ios')->assertJsonPath('data.theme_key', 'first');
        $this->themeAt('2026-12-02T00:00:00+05:30', 'unknown')->assertJsonPath('data.theme_key', 'default');
    }

    public function test_save_does_not_change_publication_and_stale_changes_are_rejected(): void
    {
        $theme = $this->publish($this->createTheme());
        $document = $theme['draft'];
        $document['tokens']['primary'] = '#112233';
        $input = ['revision' => $theme['revision'], 'name' => 'Updated', 'document' => $document];
        $this->patchJson("/api/v4/admin/themes/{$theme['id']}", $input)->assertOk();
        $this->patchJson("/api/v4/admin/themes/{$theme['id']}", $input)->assertStatus(409);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertJsonPath('data.tokens.primary', '#B91C1C');
    }

    public function test_preview_approval_must_match_the_current_saved_revision(): void
    {
        $theme = $this->createTheme();
        $token = $this->postJson("/api/v4/admin/themes/{$theme['id']}/preview", ['revision' => 1, 'platform' => 'web'])->assertOk()->json('data.preview_token');
        $this->patchJson("/api/v4/admin/themes/{$theme['id']}", ['revision' => 1, 'name' => 'Changed', 'document' => $theme['draft']])->assertOk();
        $this->postJson("/api/v4/admin/themes/{$theme['id']}/publish", ['revision' => 2, 'preview_token' => $token, 'approved' => true, 'reason' => 'Changed'])->assertStatus(409);
        $this->postJson("/api/v4/admin/themes/{$theme['id']}/publish", ['revision' => 2, 'preview_token' => $token, 'approved' => false, 'reason' => 'Changed'])->assertUnprocessable();
    }

    public function test_emergency_default_expires_and_manual_override_can_be_cleared(): void
    {
        $theme = $this->publish($this->createTheme());
        $this->postJson('/api/v4/admin/theme-overrides', ['theme_id' => null, 'expires_at' => '2026-12-01T01:00:00+05:30', 'reason' => 'Emergency'])->assertCreated();
        $this->themeAt('2026-12-01T00:30:00+05:30')->assertJsonPath('data.theme_key', 'default');
        $this->themeAt('2026-12-01T01:00:00+05:30')->assertJsonPath('data.theme_key', 'christmas_2026');
        $this->postJson('/api/v4/admin/theme-overrides', ['theme_id' => $theme['id'], 'expires_at' => '2026-12-01T02:00:00+05:30', 'reason' => 'Manual'])->assertCreated();
        $this->deleteJson('/api/v4/admin/theme-overrides', ['reason' => 'Resume'])->assertOk();
        $this->assertDatabaseHas('app_theme_audit_logs', ['action' => 'resume_schedule']);
    }

    public function test_override_runs_outside_schedule_and_pins_a_published_version(): void
    {
        $theme = $this->publish($this->createTheme('future', ['schedules' => []]));
        $this->postJson('/api/v4/admin/theme-overrides', ['theme_id' => $theme['id'], 'expires_at' => '2026-12-01T01:00:00+05:30', 'reason' => 'Manual'])->assertCreated();
        $this->themeAt('2026-12-01T00:30:00+05:30')->assertJsonPath('data.theme_key', 'future');
        $this->postJson("/api/v4/admin/themes/{$theme['id']}/unpublish", ['revision' => $theme['revision'], 'reason' => 'Stop'])->assertOk();
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertJsonPath('data.theme_key', 'default');
    }

    public function test_rollback_keeps_immutable_history_and_creates_a_new_version(): void
    {
        $theme = $this->publish($this->createTheme());
        $firstId = $theme['published_version_id'];
        $document = $theme['draft'];
        $document['tokens']['primary'] = '#112233';
        $theme = $this->patchJson("/api/v4/admin/themes/{$theme['id']}", ['revision' => $theme['revision'], 'name' => 'New', 'document' => $document])->assertOk()->json('data');
        $theme = $this->publish($theme);
        $this->postJson("/api/v4/admin/themes/{$theme['id']}/rollback", ['revision' => $theme['revision'], 'version_id' => $firstId, 'approved' => true, 'reason' => 'Restore'])->assertOk();
        $this->assertSame(3, AppThemeVersion::count());
        $this->assertSame('#B91C1C', AppThemeVersion::find($firstId)->document['tokens']['primary']);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertJsonPath('data.version', 3)->assertJsonPath('data.tokens.primary', '#B91C1C');
    }

    public function test_public_etag_revalidates_and_changes_after_override(): void
    {
        $this->publish($this->createTheme());
        $etag = $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertOk()->headers->get('ETag');
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web', 'If-None-Match' => $etag])->assertStatus(304);
        $this->postJson('/api/v4/admin/theme-overrides', ['theme_id' => null, 'expires_at' => '2026-12-01T01:00:00+05:30', 'reason' => 'Emergency'])->assertCreated();
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web', 'If-None-Match' => $etag])->assertOk()->assertJsonPath('data.theme_key', 'default');
    }

    public function test_tv_has_static_effects_and_mobile_has_capped_particles(): void
    {
        $doc = $this->document();
        $doc['effects']['snowfall']['max_particles'] = 35;
        $theme = $this->createTheme('snow', $doc);
        $this->publish($theme);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'android-tv'])->assertJsonPath('data.effects.snowfall.enabled', false)->assertJsonPath('data.effects.animation.enabled', false);
        $this->getJson('/api/v4/app/theme', ['X-Client-Platform' => 'ios'])->assertJsonPath('data.effects.snowfall.max_particles', 25);
    }

    public function test_bad_tokens_arbitrary_fields_assets_and_ambiguous_dates_are_rejected(): void
    {
        foreach ([
            ['tokens' => ['background' => 'url(javascript:x)', 'primary' => '#112233', 'secondary' => '#112233', 'accent' => '#112233']],
            ['arbitrary_html' => '<script>alert(1)</script>'],
            ['assets' => ['header' => 'https://evil.test/image.svg']],
            ['schedules' => [['start_at' => '2026-12-01T00:00:00', 'end_at' => '2026-12-02T00:00:00']]],
            ['schedules' => [['start_at' => '2026-12-02T00:00:00Z', 'end_at' => '2026-12-01T00:00:00Z']]],
        ] as $changes) {
            $this->postJson('/api/v4/admin/themes', ['key' => 'invalid', 'name' => 'Invalid', 'document' => $this->document($changes)])->assertUnprocessable();
        }
    }

    public function test_uploaded_assets_are_scoped_and_missing_assets_block_publication(): void
    {
        $theme = $this->createTheme();
        $asset = $this->postJson('/api/v4/admin/themes/assets', ['theme_id' => $theme['id'], 'asset_key' => 'header', 'image' => UploadedFile::fake()->image('header.png', 50, 20)])->assertCreated()->json('data');
        $document = $theme['draft'];
        $document['assets'] = ['header' => $asset['id']];
        $theme = $this->patchJson("/api/v4/admin/themes/{$theme['id']}", ['revision' => 1, 'name' => 'Christmas', 'document' => $document])->assertOk()->json('data');
        $preview = $this->postJson("/api/v4/admin/themes/{$theme['id']}/preview", ['revision' => $theme['revision'], 'platform' => 'web'])->assertOk()->json('data');
        Storage::disk('r2')->delete($asset['path']);
        $this->postJson("/api/v4/admin/themes/{$theme['id']}/publish", ['revision' => $theme['revision'], 'preview_token' => $preview['preview_token'], 'approved' => true, 'reason' => 'Publish'])->assertUnprocessable();
        $other = $this->createTheme('other');
        $this->patchJson("/api/v4/admin/themes/{$other['id']}", ['revision' => 1, 'name' => 'Other', 'document' => $document])->assertUnprocessable();
        $this->postJson('/api/v4/admin/themes/assets', ['theme_id' => $theme['id'], 'asset_key' => 'header', 'image' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertUnprocessable();
    }

    public function test_publishing_invalidates_an_already_cached_default(): void
    {
        $theme = $this->createTheme();
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertJsonPath('data.theme_key', 'default');
        $theme = $this->publish($theme);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertJsonPath('data.theme_key', $theme['key']);
    }

    public function test_invalid_stored_configuration_falls_back_without_breaking_app_startup(): void
    {
        $theme = $this->publish($this->createTheme());
        $version = AppThemeVersion::findOrFail($theme['published_version_id']);
        $document = $version->document;
        $document['tokens']['primary'] = 'invalid';
        $version->update(['document' => $document]);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertOk()->assertJsonPath('data.theme_key', 'default');
    }

    public function test_overrides_reject_unpublished_themes_and_unbounded_or_ambiguous_expiry(): void
    {
        $theme = $this->createTheme();
        foreach (['2026-12-01T01:00:00+05:30', '2027-02-01T00:00:00Z', '2026-12-01T01:00:00', '2026-11-01T00:00:00Z'] as $expiry) {
            $this->postJson('/api/v4/admin/theme-overrides', ['theme_id' => $theme['id'], 'expires_at' => $expiry, 'reason' => 'Test'])->assertUnprocessable();
        }
    }

    public function test_published_uploaded_asset_has_integrity_metadata_and_utc_audit_time(): void
    {
        $theme = $this->createTheme();
        $asset = $this->postJson('/api/v4/admin/themes/assets', ['theme_id' => $theme['id'], 'asset_key' => 'header', 'image' => UploadedFile::fake()->image('header.png', 50, 20)])->assertCreated()->json('data');
        $document = $theme['draft'];
        $document['assets'] = ['header' => $asset['id']];
        $theme = $this->patchJson("/api/v4/admin/themes/{$theme['id']}", ['revision' => 1, 'name' => 'Christmas', 'document' => $document])->assertOk()->json('data');
        $this->publish($theme);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'web'])->assertJsonPath('data.assets.header.checksum', $asset['checksum'])->assertJsonPath('data.assets.header.width', 50);
        $this->getJson('/api/v4/admin/themes/audit')->assertOk()->assertJsonPath('data.data.0.created_at', '2026-11-30T18:30:00.000000Z');
    }

    public function test_catalog_provides_complete_presets_without_creating_or_publishing_themes(): void
    {
        $catalog = $this->getJson('/api/v4/admin/themes')->assertOk()->json('data.theme_presets');
        $this->assertCount(12, $catalog);
        $this->assertSame('snow', $catalog['christmas']['document']['cards']['preset']);
        $this->assertSame('woven', $catalog['chapchar_kut']['document']['cards']['preset']);
        $this->assertTrue($catalog['chapchar_kut']['requires_dates']);
        $this->assertSame([], $catalog['chapchar_kut']['document']['schedules']);
        $this->assertDatabaseCount('app_themes', 0);
        foreach ($catalog as $preset) {
            app(ThemeSchema::class)->validate($preset['document'], 0);
        }
    }

    public function test_preset_creates_an_editable_draft_with_no_manual_name_or_key(): void
    {
        $theme = $this->postJson('/api/v4/admin/themes', ['preset_key' => 'christmas', 'auth_user_id' => 'test-admin'])
            ->assertCreated()->assertJsonPath('data.name', 'Christmas')->assertJsonPath('data.draft.cards.preset', 'snow')->json('data');
        $this->assertStringStartsWith('christmas_2026_', $theme['key']);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'macos'])->assertJsonPath('data.theme_key', 'default');
        $document = $theme['draft'];
        $document['cards']['preset'] = 'stars';
        $document['cards']['episode_cards'] = false;
        $document['tokens']['accent'] = '#112233';
        $this->patchJson("/api/v4/admin/themes/{$theme['id']}", ['revision' => 1, 'name' => 'My Christmas', 'document' => $document])->assertOk()->assertJsonPath('data.draft.cards.preset', 'stars');
        $catalog = $this->getJson('/api/v4/admin/themes')->assertOk();
        $catalog->assertJsonPath('data.theme_presets.christmas.document.cards.preset', 'snow')->assertJsonPath('data.theme_presets.christmas.document.tokens.accent', '#D4AF37');
        $this->postJson('/api/v4/admin/themes', ['preset_key' => 'unknown'])->assertUnprocessable();
    }

    public function test_card_config_is_validated_and_reaches_preview_and_publication(): void
    {
        $theme = $this->createTheme();
        $document = $theme['draft'];
        $document['cards'] = ['enabled' => true, 'preset' => 'hearts', 'opacity' => 0.65, 'movie_cards' => false, 'episode_cards' => true];
        $theme = $this->patchJson("/api/v4/admin/themes/{$theme['id']}", ['revision' => 1, 'name' => 'Cards', 'document' => $document])->assertOk()->json('data');
        $this->postJson("/api/v4/admin/themes/{$theme['id']}/preview", ['revision' => $theme['revision'], 'platform' => 'macos'])->assertOk()->assertJsonPath('data.preview.cards.preset', 'hearts');
        $this->publish($theme);
        $this->getJson('/api/v4/app/theme', ['X-Platform' => 'macos'])->assertJsonPath('data.cards.preset', 'hearts')->assertJsonPath('data.cards.movie_cards', false)->assertJsonPath('data.cards.episode_cards', true);
        foreach ([['preset' => '<script>'], ['opacity' => 2], ['arbitrary_html' => 'bad']] as $invalid) {
            $document['cards'] = array_replace(['enabled' => true, 'preset' => 'hearts', 'opacity' => 0.65, 'movie_cards' => false, 'episode_cards' => true], $invalid);
            $this->postJson('/api/v4/admin/themes', ['key' => 'bad_card', 'name' => 'Bad', 'document' => $document])->assertUnprocessable();
        }
    }

    public function test_old_documents_keep_undecorated_cards_and_cross_year_presets_include_current_window(): void
    {
        $document = $this->document();
        unset($document['cards']);
        $normalized = app(ThemeSchema::class)->validate($document, 0);
        $this->assertFalse($normalized['cards']['enabled']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01T00:00:00+05:30'));
        $catalog = app(\App\Services\Themes\ThemePresetCatalog::class)->all();
        $window = $catalog['new_year']['document']['schedules'][0];
        $this->assertSame('2026-12-30T18:30:00+00:00', $window['start_at']);
        $this->assertSame('2027-01-07T18:30:00+00:00', $window['end_at']);
    }

    public function test_admin_routes_require_trusted_authentication(): void
    {
        $this->withMiddleware([AuthTokenMiddleware::class, AdminTokenMiddleware::class]);
        $this->postJson('/api/v4/admin/themes', ['auth_user_id' => 'admin'])->assertUnauthorized();
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('access_token');
            $table->dateTime('access_expires_at');
        });
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('admin_uid');
        });
        Schema::create('user', function (Blueprint $table) {
            $table->id();
            $table->string('uid');
            $table->string('auth_phone')->nullable();
        });
        DB::table('session_tokens')->insert(['user_id' => 'ordinary', 'access_token' => 'ordinary-token', 'access_expires_at' => now()->addHour()]);
        $this->postJson('/api/v4/admin/themes', ['auth_user_id' => 'admin'], ['Authorization' => 'Bearer ordinary-token'])->assertForbidden();
        DB::table('admin_users')->insert(['admin_uid' => 'ordinary']);
        $this->postJson('/api/v4/admin/themes', ['key' => 'authorized', 'name' => 'Authorized', 'document' => $this->document(), 'auth_user_id' => 'spoofed'], ['Authorization' => 'Bearer ordinary-token'])->assertCreated();
        $this->assertDatabaseHas('app_theme_audit_logs', ['actor_id' => 'ordinary', 'action' => 'create']);
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->getActionName(), 'AdminThemeController@')) {
                $this->assertContains('auth.token', $route->gatherMiddleware());
                $this->assertContains('admin.token', $route->gatherMiddleware());
            }
        }
        foreach (['session_tokens', 'admin_users', 'user'] as $table) {
            Schema::drop($table);
        }
    }
}
