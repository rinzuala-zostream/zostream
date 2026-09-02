<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ads') && ! Schema::hasColumn('ads', 'campaign_id')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->index();
            });
        }
        if (Schema::hasTable('ads') && ! Schema::hasColumn('ads', 'is_active')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->index();
            });
        }

        Schema::table('ad_submissions', function (Blueprint $table) {
            $table->string('placement_code', 60)->default('home_top')->after('type');
            $table->string('billing_model', 12)->default('FLAT')->after('placement_code');
            $table->unsignedBigInteger('target_quantity')->nullable()->after('billing_model');
            $table->decimal('quoted_rate', 12, 4)->default(0)->after('target_quantity');
            $table->decimal('quoted_amount', 12, 2)->default(0)->after('quoted_rate');
            $table->char('currency', 3)->default('INR')->after('quoted_amount');
            $table->decimal('daily_budget', 12, 2)->nullable()->after('currency');
        });

        Schema::create('ad_advertisers', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('contact_name');
            $table->string('phone', 40)->index();
            $table->string('email')->nullable()->index();
            $table->text('billing_address')->nullable();
            $table->string('tax_id', 80)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ad_placement_slots', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('label');
            $table->string('platform', 30)->default('all');
            $table->string('media_type', 20);
            $table->string('dimensions', 60)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ad_billing_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_slot_id')->constrained('ad_placement_slots')->cascadeOnDelete();
            $table->string('billing_model', 12);
            $table->decimal('rate', 12, 4);
            $table->decimal('minimum_charge', 12, 2)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->boolean('requires_prepayment')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['placement_slot_id', 'billing_model'], 'ad_rate_slot_model_unique');
        });

        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertiser_id')->constrained('ad_advertisers');
            $table->foreignId('submission_id')->unique()->constrained('ad_submissions');
            $table->string('name');
            $table->string('billing_model', 12);
            $table->decimal('rate', 12, 4);
            $table->boolean('requires_prepayment')->default(true);
            $table->unsignedBigInteger('target_quantity')->nullable();
            $table->unsignedBigInteger('consumed_quantity')->default(0);
            $table->decimal('estimated_amount', 12, 2);
            $table->decimal('accrued_amount', 12, 4)->default(0);
            $table->decimal('daily_budget', 12, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('status', 32)->default('pending_payment')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_at', 'end_at'], 'ad_campaign_serving_lookup');
        });

        Schema::create('ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->text('media_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('target_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('skip_after_seconds')->nullable();
            $table->boolean('is_skippable')->default(false);
            $table->unsignedBigInteger('existing_ad_num')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ad_campaign_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('placement_slot_id')->constrained('ad_placement_slots');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['campaign_id', 'creative_id', 'placement_slot_id'], 'ad_campaign_creative_slot_unique');
        });

        Schema::create('ad_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 40)->unique();
            $table->foreignId('advertiser_id')->constrained('ad_advertisers');
            $table->foreignId('campaign_id')->constrained('ad_campaigns');
            $table->date('billing_period_start')->nullable();
            $table->date('billing_period_end')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('ad_invoices')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('ad_campaigns');
            $table->string('description');
            $table->string('billing_model', 12);
            $table->decimal('quantity', 14, 4);
            $table->decimal('rate', 12, 4);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('ad_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertiser_id')->constrained('ad_advertisers');
            $table->foreignId('invoice_id')->constrained('ad_invoices');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('payment_method', 40)->nullable();
            $table->string('gateway', 40)->default('manual');
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_order_id'], 'ad_payment_gateway_order_unique');
        });

        Schema::create('ad_impressions', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('placement_slot_id')->constrained('ad_placement_slots');
            $table->string('user_id')->nullable()->index();
            $table->string('device_id')->nullable()->index();
            $table->string('platform', 30)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->boolean('is_valid')->default(true)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ad_clicks', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('impression_id')->nullable()->constrained('ad_impressions')->nullOnDelete();
            $table->string('user_id')->nullable()->index();
            $table->string('device_id')->nullable()->index();
            $table->boolean('is_valid')->default(true)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ad_video_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('impression_id')->nullable()->constrained('ad_impressions')->nullOnDelete();
            $table->string('event', 30);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->boolean('is_valid')->default(true)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ad_billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertiser_id')->constrained('ad_advertisers');
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->string('event_type', 20);
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->decimal('quantity', 14, 4);
            $table->decimal('rate', 12, 4);
            $table->decimal('amount', 12, 6);
            $table->timestamp('created_at')->useCurrent()->index();

            $table->unique(['source_type', 'source_id'], 'ad_billing_source_unique');
        });

        $now = now();
        $slots = [
            ['code' => 'home_top', 'label' => 'Home — Top banner', 'platform' => 'all', 'media_type' => 'image', 'dimensions' => '16:9', 'description' => 'Top position on the Zo Stream home screen.'],
            ['code' => 'home_middle', 'label' => 'Home — Middle banner', 'platform' => 'all', 'media_type' => 'image', 'dimensions' => '16:9', 'description' => 'Banner between home content sections.'],
            ['code' => 'details_banner', 'label' => 'Details page banner', 'platform' => 'all', 'media_type' => 'image', 'dimensions' => '16:9', 'description' => 'Banner on movie and series details pages.'],
            ['code' => 'pre_roll', 'label' => 'Video — Pre-roll', 'platform' => 'all', 'media_type' => 'video', 'dimensions' => '16:9', 'description' => 'Video shown before playback starts.'],
            ['code' => 'mid_roll', 'label' => 'Video — Mid-roll', 'platform' => 'all', 'media_type' => 'video', 'dimensions' => '16:9', 'description' => 'Video shown during playback.'],
            ['code' => 'post_roll', 'label' => 'Video — Post-roll', 'platform' => 'all', 'media_type' => 'video', 'dimensions' => '16:9', 'description' => 'Video shown after playback.'],
        ];
        foreach ($slots as $slot) {
            DB::table('ad_placement_slots')->insert(array_merge($slot, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]));
        }

        $rateMap = [
            'home_top' => ['FLAT' => 500, 'CPM' => 120, 'CPC' => 4],
            'home_middle' => ['FLAT' => 350, 'CPM' => 90, 'CPC' => 3],
            'details_banner' => ['FLAT' => 250, 'CPM' => 70, 'CPC' => 2],
            'pre_roll' => ['FLAT' => 1000, 'CPM' => 200, 'CPV' => 2],
            'mid_roll' => ['FLAT' => 1200, 'CPM' => 220, 'CPV' => 2.5],
            'post_roll' => ['FLAT' => 700, 'CPM' => 150, 'CPV' => 1.5],
        ];
        $slotIds = DB::table('ad_placement_slots')->pluck('id', 'code');
        foreach ($rateMap as $code => $rates) {
            foreach ($rates as $model => $rate) {
                DB::table('ad_billing_rates')->insert([
                    'placement_slot_id' => $slotIds[$code],
                    'billing_model' => $model,
                    'rate' => $rate,
                    'minimum_charge' => 100,
                    'currency' => 'INR',
                    'requires_prepayment' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_billing_events');
        Schema::dropIfExists('ad_video_events');
        Schema::dropIfExists('ad_clicks');
        Schema::dropIfExists('ad_impressions');
        Schema::dropIfExists('ad_payments');
        Schema::dropIfExists('ad_invoice_items');
        Schema::dropIfExists('ad_invoices');
        Schema::dropIfExists('ad_campaign_placements');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_billing_rates');
        Schema::dropIfExists('ad_placement_slots');
        Schema::dropIfExists('ad_advertisers');

        Schema::table('ad_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'placement_code', 'billing_model', 'target_quantity', 'quoted_rate',
                'quoted_amount', 'currency', 'daily_budget',
            ]);
        });

        if (Schema::hasTable('ads') && Schema::hasColumn('ads', 'campaign_id')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->dropColumn('campaign_id');
            });
        }
        if (Schema::hasTable('ads') && Schema::hasColumn('ads', 'is_active')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
