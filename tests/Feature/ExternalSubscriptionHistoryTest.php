<?php

namespace Tests\Feature;

use App\Http\Controllers\RazorpayController;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalSubscriptionHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_key' => 'external-test-key']);

        Schema::create('user', function (Blueprint $table) {
            $table->id('num');
            $table->string('uid');
            $table->string('auth_phone')->nullable();
            $table->string('created_date')->nullable();
            $table->string('device_name')->nullable();
            $table->boolean('isACActive')->nullable();
            $table->boolean('isAccountComplete')->nullable();
            $table->boolean('is_auth_phone_active')->nullable();
        });

        Schema::create('n_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('device_type');
            $table->unsignedInteger('duration_days');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::create('n_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('renewed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('n_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('device_type')->nullable();
            $table->string('app_payment_type')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_gateway')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('status');
            $table->string('payment_type')->nullable();
            $table->dateTime('payment_date')->nullable();
            $table->dateTime('expiry_date')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function test_api_key_is_required(): void
    {
        $this->postJson('/api/v3.0/external/subscription-history', [])
            ->assertUnauthorized()
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid API key',
            ]);
    }

    public function test_mobile_and_tv_histories_use_razorpay_order_ids(): void
    {
        DB::table('user')->insert([
            'uid' => 'user-123',
            'auth_phone' => '9876543210',
        ]);

        DB::table('n_plans')->insert([
            [
                'id' => 22,
                'name' => 'Mobile plan',
                'device_type' => 'mobile',
                'duration_days' => 30,
                'price' => 199,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 24,
                'name' => 'TV plan',
                'device_type' => 'tv',
                'duration_days' => 30,
                'price' => 299,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $razorpay = $this->mock(RazorpayController::class);
        $razorpay->shouldReceive('createOrder')
            ->once()
            ->andReturn(response()->json([
                'ok' => true,
                'key_id' => 'rzp_test_key',
                'order' => ['id' => 'order_bundle_1001'],
            ], 201));

        $payload = [
            'phone_number' => '+91 98765-43210',
            'amount' => 499,
            'currency' => 'inr',
            'meta' => ['order_source' => 'partner'],
        ];

        $this->withHeader('X-Api-Key', 'external-test-key')
            ->postJson('/api/v3.0/external/subscription-history', $payload)
            ->assertCreated()
            ->assertJsonPath('user_id', 'user-123')
            ->assertJsonPath('user_created', false)
            ->assertJsonPath('razorpay_key_id', 'rzp_test_key')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.plan_id', 22)
            ->assertJsonPath('data.0.device_type', 'mobile')
            ->assertJsonPath('data.0.amount', 499)
            ->assertJsonPath('data.0.transaction_id', 'order_bundle_1001')
            ->assertJsonPath('data.1.plan_id', 24)
            ->assertJsonPath('data.1.device_type', 'tv')
            ->assertJsonPath('data.1.amount', 499)
            ->assertJsonPath('data.1.transaction_id', 'order_bundle_1001');

        $this->assertDatabaseCount('n_payment_histories', 2);
        $this->assertDatabaseHas('n_payment_histories', [
            'user_id' => 'user-123',
            'plan_id' => 22,
            'device_type' => 'mobile',
            'app_payment_type' => 'subscription',
            'payment_method' => 'razorpay',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('n_payment_histories', [
            'user_id' => 'user-123',
            'plan_id' => 24,
            'device_type' => 'tv',
            'app_payment_type' => 'subscription',
            'payment_method' => 'razorpay',
            'status' => 'pending',
        ]);

        $expiryDates = DB::table('n_payment_histories')->pluck('expiry_date');
        foreach ($expiryDates as $expiryDate) {
            $this->assertEqualsWithDelta(
                now()->addDays(30)->timestamp,
                strtotime($expiryDate),
                5
            );
        }
    }

    public function test_invalid_external_history_returns_validation_errors(): void
    {
        $this->withHeader('X-Api-Key', 'external-test-key')
            ->postJson('/api/v3.0/external/subscription-history', [
                'currency' => 'INVALID',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number', 'amount', 'currency']);
    }

    public function test_user_is_created_when_auth_phone_does_not_exist(): void
    {
        DB::table('user')->insert([
            'uid' => 'partial-match-user',
            'auth_phone' => '919876543210',
        ]);

        DB::table('n_plans')->insert([
            [
                'id' => 22,
                'name' => 'Mobile plan',
                'device_type' => 'mobile',
                'duration_days' => 30,
                'price' => 199,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 24,
                'name' => 'TV plan',
                'device_type' => 'tv',
                'duration_days' => 30,
                'price' => 299,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $razorpay = $this->mock(RazorpayController::class);
        $razorpay->shouldReceive('createOrder')
            ->once()
            ->andReturn(response()->json([
                'ok' => true,
                'key_id' => 'rzp_test_key',
                'order' => ['id' => 'order_new_user_bundle'],
            ], 201));

        $this->withHeader('X-Api-Key', 'external-test-key')
            ->postJson('/api/v3.0/external/subscription-history', [
                'phone_number' => '9876543210',
                'amount' => 499,
            ])
            ->assertCreated()
            ->assertJsonPath('user_created', true);

        $createdUser = DB::table('user')
            ->where('auth_phone', '9876543210')
            ->first();

        $this->assertNotNull($createdUser);
        $this->assertNotEmpty($createdUser->uid);
        $this->assertDatabaseCount('n_payment_histories', 2);
        $this->assertDatabaseHas('n_payment_histories', [
            'user_id' => $createdUser->uid,
            'plan_id' => 22,
        ]);
        $this->assertDatabaseHas('n_payment_histories', [
            'user_id' => $createdUser->uid,
            'plan_id' => 24,
        ]);
    }

    public function test_latest_user_is_selected_when_auth_phone_is_duplicated(): void
    {
        DB::table('user')->insert([
            [
                'uid' => 'older-user',
                'auth_phone' => '9876543210',
            ],
            [
                'uid' => 'latest-user',
                'auth_phone' => '9876543210',
            ],
        ]);

        DB::table('n_plans')->insert([
            [
                'id' => 22,
                'name' => 'Mobile plan',
                'device_type' => 'mobile',
                'duration_days' => 30,
                'price' => 199,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 24,
                'name' => 'TV plan',
                'device_type' => 'tv',
                'duration_days' => 30,
                'price' => 299,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $razorpay = $this->mock(RazorpayController::class);
        $razorpay->shouldReceive('createOrder')
            ->once()
            ->andReturn(response()->json([
                'ok' => true,
                'key_id' => 'rzp_test_key',
                'order' => ['id' => 'order_latest_bundle'],
            ], 201));

        $this->withHeader('X-Api-Key', 'external-test-key')
            ->postJson('/api/v3.0/external/subscription-history', [
                'phone_number' => '9876543210',
                'amount' => 499,
            ])
            ->assertCreated()
            ->assertJsonPath('user_id', 'latest-user')
            ->assertJsonPath('user_created', false);

        $this->assertDatabaseCount('n_payment_histories', 2);
        $this->assertDatabaseMissing('n_payment_histories', [
            'user_id' => 'older-user',
        ]);
        $this->assertDatabaseHas('n_payment_histories', [
            'user_id' => 'latest-user',
            'plan_id' => 22,
        ]);
        $this->assertDatabaseHas('n_payment_histories', [
            'user_id' => 'latest-user',
            'plan_id' => 24,
        ]);
    }
}
