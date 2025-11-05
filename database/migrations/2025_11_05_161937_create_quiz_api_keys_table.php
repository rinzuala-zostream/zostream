<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quiz_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 100)->unique();
            $table->string('owner_name');
            $table->string('email')->nullable();
            $table->string('description')->nullable();
            $table->dateTime('valid_from')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_api_keys');
    }
};
