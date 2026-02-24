<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('n_stream_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('n_subscriptions')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('n_devices')->nullOnDelete();
            $table->enum('event_type', ['start', 'stop', 'ping', 'kick', 'renew']);
            $table->json('event_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('n_stream_events');
    }
};