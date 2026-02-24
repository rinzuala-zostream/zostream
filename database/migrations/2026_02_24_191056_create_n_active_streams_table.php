<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('n_active_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('n_subscriptions')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('n_devices')->cascadeOnDelete();
            $table->enum('device_type', ['mobile', 'browser', 'tv']);
            $table->string('stream_token', 255);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_ping')->nullable();
            $table->enum('status', ['active', 'stopped', 'expired'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('n_active_streams');
    }
};