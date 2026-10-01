<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('n_subscriptions', function (Blueprint $table): void {
            $table->date('whatsapp_reminder_sent_for')->nullable()->after('is_active')->index();
            $table->unsignedTinyInteger('whatsapp_reminder_days_left')->nullable()->after('whatsapp_reminder_sent_for');
        });
    }

    public function down(): void
    {
        Schema::table('n_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp_reminder_sent_for', 'whatsapp_reminder_days_left']);
        });
    }
};
