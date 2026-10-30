<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_room_invites', function (Blueprint $t): void {
            $t->string('link_token_hash', 64)->nullable()->unique();
            $t->timestamp('whatsapp_sent_at')->nullable();
        });
        ChatDatabase::schema()->create('chat_invitation_preferences', function (Blueprint $t): void {
            $t->string('user_id', 191)->primary();
            $t->boolean('whatsapp_enabled')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->dropIfExists('chat_invitation_preferences');
        ChatDatabase::schema()->table('chat_room_invites', function (Blueprint $t): void {
            $t->dropUnique(['link_token_hash']);
            $t->dropColumn(['link_token_hash', 'whatsapp_sent_at']);
        });
    }
};
