<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->string('gateway', 20)->default('razorpay')->after('external_order_id');
            $table->text('payment_session_id')->nullable()->after('razorpay_key_id');
            $table->string('gateway_payment_id')->nullable()->unique()->after('razorpay_payment_id');
            $table->string('razorpay_key_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('payment_checkouts')
            ->whereNull('razorpay_key_id')
            ->update(['razorpay_key_id' => 'removed-cashfree-checkout']);

        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->dropUnique(['gateway_payment_id']);
            $table->dropColumn(['gateway', 'payment_session_id', 'gateway_payment_id']);
            $table->string('razorpay_key_id')->nullable(false)->change();
        });
    }
};
