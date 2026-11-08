<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_token', 120)->unique();
            $table->string('channel_code', 64)->nullable()->unique();
            $table->string('device_id', 255)->nullable()->index();
            $table->string('device_name', 150)->nullable();
            $table->enum('device_type', ['mobile', 'browser', 'tv'])->default('browser');
            $table->enum('status', ['pending', 'approved', 'completed', 'expired', 'cancelled'])
                ->default('pending')
                ->index();
            $table->string('user_id', 225)->nullable()->index();
            $table->enum('auth_method', ['otp', 'password'])->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_auth_sessions');
    }
};
