<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('n_subscriptions')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('device_name', 150)->nullable();
            $table->enum('device_type', ['mobile', 'browser', 'tv']);
            $table->string('device_token', 255)->unique();
            $table->boolean('is_owner_device')->default(false);
            $table->dateTime('last_activity')->nullable();
            $table->enum('status', ['active', 'blocked', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('n_devices');
    }
};