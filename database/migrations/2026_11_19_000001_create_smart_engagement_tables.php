<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_users', function (Blueprint $table) {
            $table->string('user_id', 191)->primary();
            $table->boolean('enabled')->default(true);
            // Unknown profile mode is deliberately restricted to the kids catalogue.
            $table->string('content_mode', 12)->default('kids');
            $table->boolean('has_app_activity')->default(false);
            $table->dateTime('last_active_at')->nullable();
            $table->dateTime('last_watch_at')->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('last_opened_at')->nullable();
            $table->unsignedInteger('unopened_count')->default(0);
            $table->json('watch_hours')->nullable();
            $table->string('interest_content_id', 191)->nullable();
            $table->dateTime('interest_at')->nullable();
            $table->dateTime('next_check_at');
            $table->timestamps();
            $table->index(['enabled', 'next_check_at', 'user_id'], 'engagement_users_due');
        });

        Schema::create('engagement_content', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 191);
            $table->string('content_type', 12);
            $table->string('content_id', 191);
            $table->unsignedBigInteger('position_ms')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->dateTime('last_watch_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('last_session_id', 191)->nullable();
            $table->unique(['user_id', 'content_type', 'content_id'], 'engagement_content_identity');
            $table->index(['user_id', 'last_watch_at'], 'engagement_content_recent');
        });

        Schema::create('engagement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 191);
            $table->string('dedupe_key', 64)->unique();
            $table->string('rule', 32);
            $table->string('content_type', 12);
            $table->string('content_id', 191);
            $table->string('source_key', 255);
            $table->dateTime('source_at');
            $table->string('status', 16)->default('pending');
            $table->dateTime('scheduled_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('played_at')->nullable();
            $table->string('reason', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'sent_at'], 'engagement_delivery_user');
            $table->index(['status', 'scheduled_at'], 'engagement_delivery_due');
            $table->index(['status', 'updated_at'], 'engagement_delivery_recovery');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_deliveries');
        Schema::dropIfExists('engagement_content');
        Schema::dropIfExists('engagement_users');
    }
};
