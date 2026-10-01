<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsAppController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SubscriptionMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Kolkata'));

        Schema::create('n_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('device_type');
            $table->unsignedInteger('duration_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('n_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('renewed_by')->nullable();
            $table->date('whatsapp_reminder_sent_for')->nullable();
            $table->unsignedTinyInteger('whatsapp_reminder_days_left')->nullable();
            $table->timestamps();
        });
        Schema::create('user', function (Blueprint $table): void {
            $table->id('num');
            $table->string('uid')->unique();
            $table->string('name')->nullable();
            $table->string('country_code')->nullable();
            $table->string('auth_phone')->nullable();
        });

        DB::table('n_plans')->insert([
            'id' => 1,
            'name' => 'Kar 1',
            'device_type' => 'mobile',
            'duration_days' => 7,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('n_subscriptions');
        Schema::dropIfExists('n_plans');
        Schema::dropIfExists('user');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_only_two_day_and_expiry_day_reminders_once(): void
    {
        $twoDayId = $this->createSubscription('two-days', 2);
        $expiryDayId = $this->createSubscription('expiry-day', 0);
        $this->createSubscription('one-day', 1);
        $this->createSubscription('three-days', 3);

        $sentDays = [];
        $whatsApp = Mockery::mock(WhatsAppController::class);
        $whatsApp->shouldReceive('send')
            ->twice()
            ->with(Mockery::on(function (Request $request) use (&$sentDays): bool {
                $sentDays[] = $request->input('template_params.3');

                return $request->input('template_name') === 'zostream_sub_reminder';
            }))
            ->andReturn(response()->json(['status' => 'success']));
        $this->app->instance(WhatsAppController::class, $whatsApp);

        $this->artisan('app:subscription-maintenance', [
            '--deactivate' => '0',
            '--reminder-days' => '2',
            '--send-reminders' => '1',
        ])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['2 day(s)', 'today'], $sentDays);
        $this->assertSame(
            today()->addDays(2)->toDateString(),
            substr((string) DB::table('n_subscriptions')->where('id', $twoDayId)->value('whatsapp_reminder_sent_for'), 0, 10),
        );
        $this->assertDatabaseHas('n_subscriptions', [
            'id' => $twoDayId,
            'whatsapp_reminder_days_left' => 2,
        ]);
        $this->assertSame(
            today()->toDateString(),
            substr((string) DB::table('n_subscriptions')->where('id', $expiryDayId)->value('whatsapp_reminder_sent_for'), 0, 10),
        );
        $this->assertDatabaseHas('n_subscriptions', [
            'id' => $expiryDayId,
            'whatsapp_reminder_days_left' => 0,
        ]);

        $this->artisan('app:subscription-maintenance', [
            '--deactivate' => '0',
            '--reminder-days' => '2',
            '--send-reminders' => '1',
        ])->assertSuccessful();
    }

    public function test_failed_reminder_is_left_unmarked_for_retry(): void
    {
        $subscriptionId = $this->createSubscription('retry-user', 2);

        $whatsApp = Mockery::mock(WhatsAppController::class);
        $whatsApp->shouldReceive('send')
            ->once()
            ->andReturn(response()->json(['message' => 'WhatsApp rejected the reminder.'], 500));
        $this->app->instance(WhatsAppController::class, $whatsApp);

        $this->artisan('app:subscription-maintenance', [
            '--deactivate' => '0',
            '--reminder-days' => '2',
            '--send-reminders' => '1',
        ])->assertSuccessful();

        $this->assertDatabaseHas('n_subscriptions', [
            'id' => $subscriptionId,
            'whatsapp_reminder_sent_for' => null,
            'whatsapp_reminder_days_left' => null,
        ]);
    }

    public function test_deactivation_happens_after_the_inclusive_expiry_day(): void
    {
        $expiredId = $this->createSubscription('expired-user', -1);
        $expiresTodayId = $this->createSubscription('expires-today', 0);

        $whatsApp = Mockery::mock(WhatsAppController::class);
        $whatsApp->shouldNotReceive('send');
        $this->app->instance(WhatsAppController::class, $whatsApp);

        $this->artisan('app:subscription-maintenance', [
            '--deactivate' => '1',
            '--send-reminders' => '0',
        ])->assertSuccessful();

        $this->assertDatabaseHas('n_subscriptions', ['id' => $expiredId, 'is_active' => false]);
        $this->assertDatabaseHas('n_subscriptions', ['id' => $expiresTodayId, 'is_active' => true]);
    }

    private function createSubscription(string $userId, int $daysLeft): int
    {
        DB::table('user')->insert([
            'uid' => $userId,
            'name' => $userId,
            'country_code' => '91',
            'auth_phone' => '9876543210',
        ]);

        return DB::table('n_subscriptions')->insertGetId([
            'user_id' => $userId,
            'plan_id' => 1,
            'start_at' => today()->subDays(4),
            'end_at' => today()->addDays($daysLeft)->endOfDay(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
