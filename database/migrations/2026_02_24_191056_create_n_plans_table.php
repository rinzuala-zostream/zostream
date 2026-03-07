<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('n_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('price', 10, 2)->default(0.00);
            $table->integer('duration_days')->default(30);
            $table->integer('device_limit_mobile')->default(1);
            $table->integer('device_limit_browser')->default(1);
            $table->integer('device_limit_tv')->default(1);
            $table->enum('quality', ['SD', 'HD', 'FULL_HD', '4K'])->default('HD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('n_plans');
    }
};