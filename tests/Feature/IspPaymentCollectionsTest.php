<?php

namespace Tests\Feature;

use App\Isp\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IspPaymentCollectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('operator_percentage', 5, 2)->nullable();
            $table->decimal('ott_deduction', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('isp_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('mikrotik_profile')->nullable();
            $table->string('rate_limit')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('branch_package', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('package_id');
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('username');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->decimal('package_amount', 12, 2)->nullable();
            $table->decimal('ott_deduction', 12, 2)->default(0);
            $table->decimal('distributable_amount', 12, 2)->nullable();
            $table->decimal('operator_percentage', 5, 2)->default(0);
            $table->decimal('operator_commission', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('cashfree');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['payments', 'customers', 'branch_package', 'packages', 'isp_users', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_collections_are_filtered_by_month_compared_and_scoped_to_the_operator_branch(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $branchId = DB::table('branches')->insertGetId(['name' => 'Aizawl', 'created_at' => now(), 'updated_at' => now()]);
        $otherBranchId = DB::table('branches')->insertGetId(['name' => 'Lunglei', 'created_at' => now(), 'updated_at' => now()]);
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Home 30', 'price' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $operator = User::create([
            'name' => 'Branch Operator',
            'email' => 'operator@example.test',
            'password' => 'secret-password',
            'role' => 'branch_operator',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'branch_id' => $branchId,
            'name' => 'Current Branch Customer',
            'username' => 'current-branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCustomerId = DB::table('customers')->insertGetId([
            'package_id' => $packageId,
            'branch_id' => $otherBranchId,
            'name' => 'Other Branch Customer',
            'username' => 'other-branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertPayment($customerId, $packageId, $operator->id, '2026-09-18 10:00:00', 80, 100, 20);
        $this->insertPayment($customerId, $packageId, $operator->id, '2026-10-05 10:00:00', 100, 125, 25);
        $this->insertPayment($customerId, $packageId, $operator->id, '2026-10-12 10:00:00', 100, 125, 25);
        $this->insertPayment($otherCustomerId, $packageId, $operator->id, '2026-10-08 10:00:00', 900, 1000, 100);

        $this->actingAs($operator, 'isp')
            ->get(route('isp.payments.index', ['view' => 'collections', 'month' => '2026-10']))
            ->assertOk()
            ->assertSee('October 2026 transactions')
            ->assertSee('Current Branch Customer')
            ->assertDontSee('Other Branch Customer')
            ->assertViewHas('collectionSummary', fn (object $summary): bool => (int) $summary->payment_count === 2
                && (float) $summary->revenue === 200.0
                && (float) $summary->package_total === 250.0
                && (float) $summary->operator_commission === 50.0
            )
            ->assertViewHas('revenueDifference', 120.0)
            ->assertViewHas('revenuePercentage', 150.0);
    }

    public function test_collect_payment_is_a_separate_view_and_customer_links_open_it(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        $user = User::create([
            'name' => 'ISP Admin',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'isp')
            ->get(route('isp.payments.index'))
            ->assertOk()
            ->assertViewHas('activeView', 'collections')
            ->assertSee('Payment Collections')
            ->assertSee('No previous-month baseline')
            ->assertDontSee('Customer & payment', false);

        $this->actingAs($user, 'isp')
            ->get(route('isp.payments.index', ['customer' => 99]))
            ->assertOk()
            ->assertViewHas('activeView', 'collect')
            ->assertSee('Customer & payment', false);
    }

    private function insertPayment(
        int $customerId,
        int $packageId,
        int $operatorId,
        string $paidAt,
        float $amount,
        float $packageAmount,
        float $commission,
    ): void {
        DB::table('payments')->insert([
            'customer_id' => $customerId,
            'package_id' => $packageId,
            'operator_id' => $operatorId,
            'package_amount' => $packageAmount,
            'operator_commission' => $commission,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    }
}
