<?php

namespace Tests\Feature;

use App\Isp\Http\Controllers\CustomerController;
use App\Isp\Models\Customer;
use App\Isp\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IspCustomerAadhaarRequirementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('routers', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
        });

        DB::table('routers')->insert(['id' => 1]);
        DB::table('packages')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('routers');
        parent::tearDown();
    }

    public function test_new_customer_requires_both_aadhaar_images(): void
    {
        $request = Request::create('/isp/customers', 'POST', [
            'router_id' => 1,
            'package_id' => 1,
            'name' => 'Test Customer',
            'phone' => '9876543210',
            'username' => 'test-customer',
            'password' => 'secret-password',
            'status' => 'active',
            'router_device_condition' => 'old',
        ]);
        $request->setUserResolver(fn (): User => new User([
            'role' => 'admin',
            'is_active' => true,
        ]));

        $validate = (function (Request $request): array {
            return $this->validated($request);
        })->bindTo(new CustomerController, CustomerController::class);

        try {
            $validate($request);
            $this->fail('Validation should reject a customer without Aadhaar images.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('aadhaar_front', $exception->errors());
            $this->assertArrayHasKey('aadhaar_back', $exception->errors());
        }
    }

    public function test_expired_administrative_option_sets_expiry_without_overloading_manual_status(): void
    {
        DB::table('customers')->insert(['id' => 1, 'username' => 'existing-customer']);
        $customer = new Customer([
            'router_id' => 1,
            'package_id' => 1,
            'name' => 'Existing Customer',
            'username' => 'existing-customer',
            'status' => 'active',
            'expires_at' => today()->addDays(10),
            'router_device_condition' => 'old',
            'aadhaar_front_path' => 'isp/customers/aadhaar/front.jpg',
            'aadhaar_back_path' => 'isp/customers/aadhaar/back.jpg',
        ]);
        $customer->setAttribute('id', 1);
        $customer->exists = true;
        $request = Request::create('/isp/customers/1', 'PUT', [
            'router_id' => 1,
            'package_id' => 1,
            'name' => 'Existing Customer',
            'phone' => '9876543210',
            'username' => 'existing-customer',
            'status' => 'expired',
            'expires_at' => today()->addDays(10)->toDateString(),
            'router_device_condition' => 'old',
        ]);
        $request->setUserResolver(fn (): User => new User([
            'role' => 'admin',
            'is_active' => true,
        ]));

        $data = (function (Request $request, Customer $customer): array {
            return $this->validated($request, $customer);
        })->call(new CustomerController, $request, $customer);

        $this->assertSame('active', $data['status']);
        $this->assertSame(today()->subDay()->toDateString(), $data['expires_at']);
    }
}
