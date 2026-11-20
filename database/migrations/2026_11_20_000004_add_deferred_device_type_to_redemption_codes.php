<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('redemption_codes', 'redeemed_device_type')) {
            Schema::table('redemption_codes', function (Blueprint $table) {
                $table->string('redeemed_device_type', 20)->nullable()->after('device_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('redemption_codes', 'redeemed_device_type')) {
            Schema::table('redemption_codes', function (Blueprint $table) {
                $table->dropColumn('redeemed_device_type');
            });
        }
    }
};
