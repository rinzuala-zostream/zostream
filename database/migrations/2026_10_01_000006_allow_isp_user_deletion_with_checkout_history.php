<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_checkouts') || ! Schema::hasColumn('payment_checkouts', 'user_id')) {
            return;
        }

        Schema::table('payment_checkouts', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('payment_checkouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('isp_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_checkouts') || ! Schema::hasColumn('payment_checkouts', 'user_id')) {
            return;
        }

        Schema::table('payment_checkouts', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('isp_users')->restrictOnDelete();
        });
    }
};
