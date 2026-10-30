<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_messages', function (Blueprint $t): void {
            $t->unsignedBigInteger('reply_to_id')->nullable()->index();
            $t->json('moment')->nullable();
        });
        ChatDatabase::schema()->create('chat_reactions', function (Blueprint $t): void {
            $t->unsignedBigInteger('message_id');
            $t->string('user_id', 191);
            $t->string('emoji', 16);
            $t->primary(['message_id', 'user_id', 'emoji']);
            $t->index('user_id');
        });
    }
    public function down(): void
    {
        ChatDatabase::schema()->dropIfExists('chat_reactions');
        ChatDatabase::schema()->table('chat_messages', function (Blueprint $t): void {
            $t->dropIndex(['reply_to_id']);
            $t->dropColumn(['reply_to_id', 'moment']);
        });
    }
};
