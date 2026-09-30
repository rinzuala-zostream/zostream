<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('router_device_condition', 10)->nullable()->after('address')->index();
            $table->string('aadhaar_front_path')->nullable()->after('router_device_condition');
            $table->string('aadhaar_back_path')->nullable()->after('aadhaar_front_path');
        });

        Schema::create('customer_router_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('isp_users')->nullOnDelete();
            $table->string('router_condition', 10);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 20)->index();
            $table->string('method', 30)->nullable();
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained('isp_users')->nullOnDelete();
            $table->longText('customer_payload');
            $table->string('username')->index();
            $table->string('aadhaar_front_path')->nullable();
            $table->string('aadhaar_back_path')->nullable();
            $table->decimal('router_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->string('cashfree_order_id')->nullable()->unique();
            $table->text('payment_session_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->text('activation_error')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_onboardings');
        Schema::dropIfExists('customer_router_payments');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'router_device_condition',
                'aadhaar_front_path',
                'aadhaar_back_path',
            ]);
        });
    }
};
