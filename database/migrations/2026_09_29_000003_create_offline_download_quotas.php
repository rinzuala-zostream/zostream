<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_download_daily_quotas', function (Blueprint $table) {
            $table->id();
            $table->char('user_key', 64);
            $table->string('user_id', 191);
            $table->date('quota_date');
            $table->unsignedTinyInteger('downloads_count')->default(0);
            $table->timestamps();

            $table->unique(['user_key', 'quota_date'], 'offline_quota_user_date_unique');
        });

        Schema::create('offline_download_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quota_id')
                ->constrained('offline_download_daily_quotas')
                ->cascadeOnDelete();
            $table->char('content_key', 64);
            $table->string('content_type', 16);
            $table->string('content_id', 191);
            $table->timestamps();

            $table->unique(['quota_id', 'content_key'], 'offline_grant_content_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_download_grants');
        Schema::dropIfExists('offline_download_daily_quotas');
    }
};
