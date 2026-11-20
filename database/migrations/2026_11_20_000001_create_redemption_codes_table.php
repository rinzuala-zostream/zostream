<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemption_codes', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('label', 150);
            $table->string('type', 20);
            $table->string('movie_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedInteger('rental_days')->default(7);
            $table->string('created_by');
            $table->string('status', 20)->default('available')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redeemed_by')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('payment_history_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemption_codes');
    }
};
