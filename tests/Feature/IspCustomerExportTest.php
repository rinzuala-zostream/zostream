<?php

namespace Tests\Feature;

use App\Isp\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IspCustomerExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('username');
            $table->string('password');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status');
            $table->date('expires_at')->nullable();
        });
        foreach ([[1, 'OWN-EXPIRED', today()->subDay()], [2, 'OTHER-EXPIRED', today()->subDay()], [1, 'OWN-CURRENT', today()]] as [$branch, $username, $expiry]) {
            DB::table('customers')->insert([
                'name' => $username, 'phone' => '1234567890', 'username' => $username,
                'password' => 'PRIVATE-PASSWORD', 'branch_id' => $branch,
                'status' => 'active', 'expires_at' => $expiry->toDateString(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('customers');
        parent::tearDown();
    }

    private function loginAs(string $role, ?int $branch = 1): void
    {
        $this->actingAs(new User(['role' => $role, 'branch_id' => $branch, 'is_active' => true]), 'isp');
    }

    public function test_operator_export_is_scoped_even_with_forged_branch_and_omits_credentials(): void
    {
        $this->loginAs('branch_operator');
        $response = $this->get('/isp/customers/export?status=expired&branch_id=2');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('OWN-EXPIRED', $csv);
        $this->assertStringNotContainsString('OTHER-EXPIRED', $csv);
        $this->assertStringNotContainsString('OWN-CURRENT', $csv);
        $this->assertStringNotContainsString('password', strtolower($csv));
    }

    public function test_operator_cannot_request_full_export_or_bypass_status_filter(): void
    {
        $this->loginAs('branch_operator');
        $this->get('/isp/customers/export?mode=full&status=expired')->assertForbidden();
        $this->getJson('/isp/customers/export?search=%25')->assertUnprocessable();
        $this->getJson('/isp/customers/export?status=all')->assertUnprocessable();
    }

    public function test_unassigned_and_unknown_roles_cannot_export(): void
    {
        $this->loginAs('branch_operator', null);
        $this->get('/isp/customers/export?status=expired')->assertForbidden();
        $this->loginAs('unknown');
        $this->get('/isp/customers/export?status=expired')->assertForbidden();
    }

    public function test_admin_filtered_export_obeys_branch_and_inclusive_expiry(): void
    {
        $this->loginAs('admin', null);
        $response = $this->get('/isp/customers/export?status=active&branch_id=1');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('OWN-CURRENT', $csv);
        $this->assertStringNotContainsString('OWN-EXPIRED', $csv);
        $this->assertStringNotContainsString('OTHER-EXPIRED', $csv);
    }
}
