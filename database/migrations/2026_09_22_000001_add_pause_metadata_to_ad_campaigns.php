<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->string('pause_reason', 32)->nullable()->after('status');
            $table->timestamp('resume_at')->nullable()->after('pause_reason')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropIndex(['resume_at']);
            $table->dropColumn(['pause_reason', 'resume_at']);
        });
    }
};
