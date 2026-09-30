<?php

namespace Tests\Feature;

use App\Http\Controllers\New\SubscriptionController;
use App\Isp\Models\Branch;
use App\Isp\Models\Customer;
use App\Isp\Models\Package;
use App\Isp\Models\User;
use App\Isp\Services\ZoStreamSubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class IspWebIntegrationTest extends TestCase
{
    public function test_isp_entry_and_login_are_mounted_under_the_isp_prefix(): void
    {
        $this->get('/isp')->assertRedirect('/isp/dashboard');

        $this->get('/isp/login')
            ->assertOk()
            ->assertSee('ZoStream ISP')
            ->assertSee('/isp-assets/css/admin.css', false);
    }

    public function test_isp_dashboard_uses_its_own_authentication_guard(): void
    {
        $this->get('/isp/dashboard')->assertRedirect(route('isp.login'));
    }

    public function test_isp_login_authenticates_with_the_isolated_guard(): void
    {
        Schema::create('isp_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('admin');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        try {
            $user = User::create([
                'name' => 'ISP Admin',
                'email' => 'isp@example.test',
                'password' => Hash::make('secret-password'),
                'role' => 'admin',
                'is_active' => true,
            ]);

            $this->post('/isp/login', [
                'email' => 'isp@example.test',
                'password' => 'secret-password',
            ])->assertRedirect(route('isp.dashboard'));

            $this->assertAuthenticatedAs($user, 'isp');
        } finally {
            Schema::dropIfExists('isp_users');
        }
    }

    public function test_existing_zostream_home_route_is_unchanged(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_isp_checkout_uses_the_internal_zostream_subscription_flow(): void
    {
        config()->set('services.zostream_subscription.environment', 'SANDBOX');

        $package = new Package(['name' => 'Starter', 'price' => 499]);
        $branch = new Branch(['operator_percentage' => 20, 'ott_deduction' => 0]);
        $customer = new Customer(['name' => 'Test Customer', 'phone' => '9876543210']);
        $customer->setRelation('package', $package);
        $customer->setRelation('branch', $branch);

        $subscriptions = Mockery::mock(SubscriptionController::class);
        $subscriptions->shouldReceive('storeExternalHistory')
            ->once()
            ->with(Mockery::on(function (Request $request): bool {
                return $request->input('phone_number') === '9876543210'
                    && (float) $request->input('amount') === 399.2
                    && $request->header('X-RZ-Env') === 'SANDBOX';
            }))
            ->andReturn(response()->json([
                'status' => 'success',
                'razorpay_key_id' => 'rzp_test_key',
                'razorpay_order' => [
                    'id' => 'order_isp_test',
                    'amount' => 39920,
                    'currency' => 'INR',
                ],
            ], 201));

        $result = (new ZoStreamSubscriptionService($subscriptions))->createOrder($customer, $package);

        $this->assertSame('order_isp_test', $result['razorpay_order']['id']);
    }
}
