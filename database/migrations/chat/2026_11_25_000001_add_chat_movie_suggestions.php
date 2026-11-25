<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_messages', fn (Blueprint $table) => $table->json('movie_suggestion')->nullable());
    }
    public function down(): void
    {
        ChatDatabase::schema()->table('chat_messages', fn (Blueprint $table) => $table->dropColumn('movie_suggestion'));
    }
};
