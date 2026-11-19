<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable();
        });
        Schema::create('bandwidth_invoice_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bandwidth_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('recipient_name', 150);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['bandwidth_invoice_id', 'phone'], 'bandwidth_delivery_invoice_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidth_invoice_deliveries');
        Schema::table('isp_users', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
