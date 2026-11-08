<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['chat_watch_sessions', 'chat_invite_rooms'] as $table) {
            ChatDatabase::schema()->table($table, function (Blueprint $t): void {
                $t->boolean('paused')->default(false);
                $t->unsignedBigInteger('control_sequence')->default(0);
                $t->unsignedBigInteger('sampled_at_ms')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['chat_watch_sessions', 'chat_invite_rooms'] as $table) {
            ChatDatabase::schema()->table($table, fn (Blueprint $t) => $t->dropColumn(['paused', 'control_sequence', 'sampled_at_ms']));
        }
    }
};
