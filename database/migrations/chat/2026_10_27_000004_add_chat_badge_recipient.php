<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_outbox', fn (Blueprint $t) => $t->string('recipient_user_id', 191)->nullable());
    }

    public function down(): void
    {
        ChatDatabase::schema()->table('chat_outbox', fn (Blueprint $t) => $t->dropColumn('recipient_user_id'));
    }
};
