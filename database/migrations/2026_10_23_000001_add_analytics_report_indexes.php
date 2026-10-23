<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'analytics';

    public function up(): void
    {
        Schema::connection($this->connection)->table('playback_sessions', function (Blueprint $table) {
            $table->index(['started_at', 'platform', 'app_version'], 'analytics_started_platform_app_idx');
            $table->index(['started_at', 'content_type'], 'analytics_started_content_type_idx');
            $table->index(['user_id', 'started_at'], 'analytics_user_started_idx');
        });

        Schema::connection($this->connection)->table('playback_errors', function (Blueprint $table) {
            $table->index(['occurred_at', 'category', 'stage', 'code'], 'analytics_error_occurred_group_idx');
        });

        Schema::connection($this->connection)->table('analytics_events', function (Blueprint $table) {
            $table->index(['occurred_at', 'platform', 'app_version'], 'analytics_event_occurred_platform_idx');
            $table->index(['name', 'occurred_at', 'user_id'], 'analytics_event_name_occurred_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('analytics_events', function (Blueprint $table) {
            $table->dropIndex('analytics_event_occurred_platform_idx');
            $table->dropIndex('analytics_event_name_occurred_user_idx');
        });

        Schema::connection($this->connection)->table('playback_errors', function (Blueprint $table) {
            $table->dropIndex('analytics_error_occurred_group_idx');
        });

        Schema::connection($this->connection)->table('playback_sessions', function (Blueprint $table) {
            $table->dropIndex('analytics_started_platform_app_idx');
            $table->dropIndex('analytics_started_content_type_idx');
            $table->dropIndex('analytics_user_started_idx');
        });
    }
};
