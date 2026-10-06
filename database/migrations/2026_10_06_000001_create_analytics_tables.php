<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'analytics';

    public function up(): void
    {
        Schema::connection($this->connection)->create('playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->string('user_id', 191)->index();
            $table->string('device_id', 191)->index();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('content_id', 191);
            $table->string('content_type', 24);
            $table->unsignedInteger('revision')->default(1);
            $table->string('state', 24);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedBigInteger('watch_position_ms')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('watched_ms')->default(0);
            $table->unsignedBigInteger('unique_watched_ms')->default(0);
            $table->unsignedBigInteger('startup_ms')->nullable();
            $table->unsignedInteger('buffer_count')->default(0);
            $table->unsignedBigInteger('buffer_ms')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->decimal('completion_percent', 6, 3)->default(0);
            $table->boolean('completed')->default(false);
            $table->string('end_reason', 48)->nullable();
            $table->string('platform', 24);
            $table->string('app_version', 64);
            $table->string('sdk_version', 32)->nullable();
            $table->json('metrics_json');
            $table->timestamps();

            $table->index(['content_type', 'content_id', 'ended_at'], 'analytics_content_ended_idx');
            $table->index(['platform', 'app_version', 'ended_at'], 'analytics_platform_app_ended_idx');
            $table->index('ended_at');
        });

        Schema::connection($this->connection)->create('playback_errors', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->uuid('session_id')->index();
            $table->string('user_id', 191)->index();
            $table->string('device_id', 191)->index();
            $table->string('category', 64);
            $table->string('stage', 64);
            $table->string('code', 128);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('position_ms')->default(0);
            $table->boolean('is_fatal')->default(false);
            $table->boolean('is_retryable')->default(false);
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('network_type', 32)->nullable();
            $table->string('sanitized_message', 500)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['category', 'stage', 'occurred_at'], 'analytics_error_group_idx');
        });

        Schema::connection($this->connection)->create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('user_id', 191)->index();
            $table->string('device_id', 191)->index();
            $table->uuid('app_session_id')->index();
            $table->string('name', 64)->index();
            $table->dateTime('occurred_at')->index();
            $table->string('platform', 24);
            $table->string('app_version', 64);
            $table->json('properties_json')->nullable();
            $table->timestamps();

            $table->index(['name', 'occurred_at'], 'analytics_event_name_time_idx');
        });

        Schema::connection($this->connection)->create('daily_content_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date');
            $table->string('content_type', 24);
            $table->string('content_id', 191);
            $table->string('platform', 24);
            $table->unsignedBigInteger('playback_starts')->default(0);
            $table->unsignedBigInteger('valid_views')->default(0);
            $table->unsignedBigInteger('unique_viewers')->default(0);
            $table->unsignedBigInteger('watch_ms')->default(0);
            $table->unsignedBigInteger('completed_views')->default(0);
            $table->unsignedBigInteger('buffer_count')->default(0);
            $table->unsignedBigInteger('buffer_ms')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->unsignedBigInteger('startup_ms_total')->default(0);
            $table->timestamps();

            $table->unique(
                ['metric_date', 'content_type', 'content_id', 'platform'],
                'analytics_daily_content_unique'
            );
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('daily_content_metrics');
        $schema->dropIfExists('analytics_events');
        $schema->dropIfExists('playback_errors');
        $schema->dropIfExists('playback_sessions');
    }
};
