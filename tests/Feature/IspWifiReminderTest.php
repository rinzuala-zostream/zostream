<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsAppController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspWifiReminderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('status')->default('active');
            $table->date('expires_at')->nullable();
            $table->date('wifi_reminder_sent_for')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('packages');

        parent::tearDown();
    }

    public function test_it_sends_tomorrow_wifi_reminder_once_with_the_full_package_price(): void
    {
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 50 Mbps',
            'price' => 599,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Test Customer',
            'phone' => '98765 43210',
            'status' => 'active',
            'expires_at' => today()->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            'package_id' => $packageId,
            'name' => 'Later Customer',
            'phone' => '9876543211',
            'status' => 'active',
            'expires_at' => today()->addDays(2)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $whatsApp = Mockery::mock(WhatsAppController::class);
        $whatsApp->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (Request $request): bool {
                return $request->input('to') === '919876543210'
                    && $request->input('template_name') === 'zostream_wifi_reminder'
                    && $request->input('language') === 'en'
                    && $request->input('template_params') === [
                        'Test Customer',
                        today()->addDay()->format('d M Y'),
                        '599.00',
                        'Home 50 Mbps',
                    ];
            }))
            ->andReturn(response()->json(['status' => 'success']));
        $this->app->instance(WhatsAppController::class, $whatsApp);

        $this->artisan('isp:send-wifi-reminders')
            ->expectsOutput("Reminder sent for customer #{$customerId}.")
            ->expectsOutput('ISP WiFi reminders complete: 1 sent, 0 failed, 0 skipped.')
            ->assertSuccessful();

        $this->assertSame(
            today()->addDay()->toDateString(),
            substr((string) DB::table('customers')->where('id', $customerId)->value('wifi_reminder_sent_for'), 0, 10),
        );

        $this->artisan('isp:send-wifi-reminders')
            ->expectsOutput('ISP WiFi reminders complete: 0 sent, 0 failed, 0 skipped.')
            ->assertSuccessful();
    }

    public function test_failed_reminder_is_not_marked_as_sent_so_it_can_be_retried(): void
    {
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 100 Mbps',
            'price' => 999,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Retry Customer',
            'phone' => '919876543212',
            'status' => 'active',
            'expires_at' => today()->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $whatsApp = Mockery::mock(WhatsAppController::class);
        $whatsApp->shouldReceive('send')
            ->once()
            ->andReturn(response()->json(['message' => 'WhatsApp rejected the reminder.'], 500));
        $this->app->instance(WhatsAppController::class, $whatsApp);

        $this->artisan('isp:send-wifi-reminders')
            ->expectsOutput("Reminder failed for customer #{$customerId}: WhatsApp rejected the reminder.")
            ->expectsOutput('ISP WiFi reminders complete: 0 sent, 1 failed, 0 skipped.')
            ->assertFailed();

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'wifi_reminder_sent_for' => null,
        ]);
    }
}
