<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('aadhaar_qr_verified_at')->nullable()->after('aadhaar_back_path');
        });

        Schema::table('customer_onboardings', function (Blueprint $table): void {
            $table->timestamp('aadhaar_qr_verified_at')->nullable()->after('aadhaar_back_path');
        });
    }

    public function down(): void
    {
        Schema::table('customer_onboardings', function (Blueprint $table): void {
            $table->dropColumn('aadhaar_qr_verified_at');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('aadhaar_qr_verified_at');
        });
    }
};
