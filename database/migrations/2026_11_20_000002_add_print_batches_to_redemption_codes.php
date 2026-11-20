<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemption_print_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('created_by');
            $table->string('request_fingerprint', 64);
            $table->unsignedTinyInteger('page_count');
            $table->timestamps();
        });
        Schema::table('redemption_codes', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->index();
            $table->unsignedTinyInteger('batch_page_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('redemption_codes', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['batch_id', 'batch_page_count']);
        });
        Schema::dropIfExists('redemption_print_batches');
    }
};
