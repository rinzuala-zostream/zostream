<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('n_active_streams', function (Blueprint $table) {
            // Free and PPV playback does not necessarily have a subscription.
            $table->unsignedBigInteger('subscription_id')->nullable()->change();
            $table->string('content_key', 225)->nullable()->after('content_id');
            $table->index('stream_token', 'n_active_streams_token_idx');
            $table->index(
                ['subscription_id', 'device_type', 'status', 'last_ping'],
                'n_active_streams_seat_lookup_idx'
            );
            $table->index(
                ['device_id', 'content_type', 'content_key', 'status'],
                'n_active_streams_device_content_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->dropIndex('n_active_streams_token_idx');
            $table->dropIndex('n_active_streams_seat_lookup_idx');
            $table->dropIndex('n_active_streams_device_content_idx');
            $table->dropColumn('content_key');
        });
    }
};
