<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('banner', 500)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->enum('status', ['pending', 'active', 'suspended', 'deleted'])->default('pending');
            $table->timestamps();

            $table->foreign('user_id')->references('num')->on('user');
        });

        Schema::create('channel_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels');
            $table->string('name', 100)->nullable();
            $table->integer('duration_days');
            $table->decimal('price', 10, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('final_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['channel_id', 'is_active']);
        });

        Schema::create('channel_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('plan_id')->constrained('channel_subscription_plans');
            $table->dateTime('subscribed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('num')->on('user');
        });

        Schema::create('channel_subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('plan_id')->constrained('channel_subscription_plans');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending');
            $table->timestamp('created_at')->nullable();

            $table->index(['channel_id', 'user_id']);
            $table->index('transaction_id');
            $table->foreign('user_id')->references('num')->on('user');
        });

        Schema::create('channel_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels');
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->enum('content_type', ['video', 'audio', 'live', 'podcast', 'article'])->nullable();
            $table->enum('access_type', ['free', 'subscriber_only', 'ppv'])->default('subscriber_only');
            $table->string('thumbnail', 500)->nullable();
            $table->integer('duration')->default(0);
            $table->dateTime('release_date')->nullable();
            $table->enum('status', ['draft', 'published', 'private'])->default('draft');
            $table->timestamps();

            $table->index(['channel_id', 'status']);
            $table->index(['access_type', 'status']);
        });

        Schema::create('channel_content_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('channel_contents')->cascadeOnDelete();
            $table->enum('media_type', ['video', 'audio', 'subtitle', 'thumbnail'])->nullable();
            $table->string('quality', 50)->nullable();
            $table->string('language', 20)->nullable();
            $table->text('url');
            $table->bigInteger('file_size')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('channel_content_ppv', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('channel_contents')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->integer('rental_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('channel_content_rentals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('content_id')->constrained('channel_contents');
            $table->dateTime('rented_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'content_id']);
            $table->index(['content_id', 'status']);
            $table->foreign('user_id')->references('num')->on('user');
        });

        Schema::create('channel_content_rental_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('content_id')->constrained('channel_contents');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->dateTime('rented_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'content_id']);
            $table->index('transaction_id');
            $table->foreign('user_id')->references('num')->on('user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_content_rental_history');
        Schema::dropIfExists('channel_content_rentals');
        Schema::dropIfExists('channel_content_ppv');
        Schema::dropIfExists('channel_content_media');
        Schema::dropIfExists('channel_contents');
        Schema::dropIfExists('channel_subscription_history');
        Schema::dropIfExists('channel_subscribers');
        Schema::dropIfExists('channel_subscription_plans');
        Schema::dropIfExists('channels');
    }
};
