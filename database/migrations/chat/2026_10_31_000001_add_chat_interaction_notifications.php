<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_outbox', function (Blueprint $t): void {
            $t->json('notification_data')->nullable();
            $t->string('notification_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->table('chat_outbox', function (Blueprint $t): void {
            $t->dropUnique(['notification_key']);
            $t->dropColumn(['notification_data', 'notification_key']);
        });
    }
};
