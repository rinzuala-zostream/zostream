<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->string('content_type', 32)->nullable()->after('device_type');
            $table->unsignedBigInteger('content_id')->nullable()->after('content_type');
            $table->index(['content_type', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->dropIndex(['content_type', 'content_id']);
            $table->dropColumn(['content_type', 'content_id']);
        });
    }
};
