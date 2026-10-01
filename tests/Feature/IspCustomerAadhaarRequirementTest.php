<?php

namespace Tests\Feature;

use App\Isp\Http\Controllers\CustomerController;
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
}
