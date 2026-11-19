<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('billing_type')->default('standard');
            $table->decimal('bandwidth_mbps', 12, 2)->nullable();
            $table->decimal('bandwidth_rate', 12, 2)->nullable();
            $table->decimal('bandwidth_gst', 5, 2)->nullable();
            $table->unsignedTinyInteger('bandwidth_due_day')->nullable();
            $table->date('bandwidth_started_at')->nullable();
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('bandwidth_access_blocked')->nullable();
        });
        Schema::create('bandwidth_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('branch_name');
            $table->date('billing_month');
            $table->date('issued_on');
            $table->date('due_date')->index();
            $table->decimal('bandwidth_mbps', 12, 2);
            $table->decimal('rate', 12, 2);
            $table->decimal('gst_percentage', 5, 2);
            $table->decimal('subtotal', 16, 2);
            $table->decimal('gst_amount', 16, 2);
            $table->decimal('total', 16, 2);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('isp_users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'billing_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidth_invoices');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('bandwidth_access_blocked'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn([
            'billing_type', 'bandwidth_mbps', 'bandwidth_rate', 'bandwidth_gst', 'bandwidth_due_day', 'bandwidth_started_at',
        ]));
    }
};
