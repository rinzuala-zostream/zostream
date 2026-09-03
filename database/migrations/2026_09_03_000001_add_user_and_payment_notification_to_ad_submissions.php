<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_submissions', function (Blueprint $table) {
            $table->string('user_id')->nullable()->after('id')->index();
            $table->text('public_token_encrypted')->nullable()->after('public_token_hash');
            $table->timestamp('approval_whatsapp_sent_at')->nullable()->after('reviewed_at');
            $table->text('approval_whatsapp_error')->nullable()->after('approval_whatsapp_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_submissions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn([
                'user_id',
                'public_token_encrypted',
                'approval_whatsapp_sent_at',
                'approval_whatsapp_error',
            ]);
        });
    }
};
