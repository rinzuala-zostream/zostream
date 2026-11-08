<?php

namespace Tests\Feature;

use App\Isp\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IspUserDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
        Schema::create('payment_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('isp_users')->nullOnDelete();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payment_checkouts');
        Schema::dropIfExists('isp_users');
        parent::tearDown();
    }

    public function test_admin_can_delete_another_admin_without_losing_checkout_history(): void
    {
        $current = $this->admin('current@example.test');
        $target = $this->admin('admin@example.com');
        DB::table('payment_checkouts')->insert(['user_id' => $target->id]);

        $this->actingAs($current, 'isp')
            ->from('/isp/users')
            ->delete('/isp/users/'.$target->id)
            ->assertRedirect('/isp/users')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('isp_users', ['id' => $target->id]);
        $this->assertDatabaseHas('payment_checkouts', ['id' => 1, 'user_id' => null]);
    }

    public function test_current_account_cannot_delete_itself(): void
    {
        $current = $this->admin('current@example.test');

        $this->actingAs($current, 'isp')
            ->from('/isp/users')
            ->delete('/isp/users/'.$current->id)
            ->assertRedirect('/isp/users')
            ->assertSessionHas('error', 'You cannot delete your own account.');

        $this->assertDatabaseHas('isp_users', ['id' => $current->id]);
    }

    private function admin(string $email): User
    {
        return User::create([
            'name' => 'ISP Administrator',
            'email' => $email,
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
