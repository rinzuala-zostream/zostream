<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 32)->unique();
            $table->char('public_token_hash', 64)->unique();
            $table->string('status', 32)->default('pending_review')->index();
            $table->string('business_name');
            $table->string('contact_name');
            $table->string('contact_phone', 40);
            $table->string('contact_email')->nullable()->index();
            $table->string('ads_name');
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->text('media_url')->nullable();
            $table->text('destination_url')->nullable();
            $table->date('requested_start_date')->nullable();
            $table->unsignedInteger('requested_period_days');
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('approved_ad_num')->nullable()->index();
            $table->char('submitted_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('ad_submission_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_submission_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('file_url');
            $table->text('storage_path')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['ad_submission_id', 'kind', 'sort_order'], 'ad_submission_assets_lookup');
        });

        Schema::create('ad_submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_submission_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('note')->nullable();
            $table->string('actor_type', 20)->default('advertiser');
            $table->string('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ad_submission_id', 'created_at'], 'ad_submission_events_timeline');
        });

        if (Schema::hasTable('ads') && ! Schema::hasColumn('ads', 'target_url')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->text('target_url')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ads') && Schema::hasColumn('ads', 'target_url')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->dropColumn('target_url');
            });
        }

        Schema::dropIfExists('ad_submission_events');
        Schema::dropIfExists('ad_submission_assets');
        Schema::dropIfExists('ad_submissions');
    }
};
