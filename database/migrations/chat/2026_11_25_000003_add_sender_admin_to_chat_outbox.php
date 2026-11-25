<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_outbox', function (Blueprint $table): void {
            $table->boolean('sender_is_admin')->nullable();
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->table('chat_outbox', fn (Blueprint $table) => $table->dropColumn('sender_is_admin'));
    }
};
