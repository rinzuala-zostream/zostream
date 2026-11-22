<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login already supports devices without a subscription; switches retain those identities too.
        if (Schema::hasTable('n_devices')) {
            Schema::table('n_devices', fn (Blueprint $table) => $table->unsignedBigInteger('subscription_id')->nullable()->change());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('n_devices')) return;
        if (DB::table('n_devices')->whereNull('subscription_id')->exists()) {
            throw new RuntimeException('Cannot require device subscriptions while unbound devices exist.');
        }
        Schema::table('n_devices', fn (Blueprint $table) => $table->unsignedBigInteger('subscription_id')->nullable(false)->change());
    }
};
