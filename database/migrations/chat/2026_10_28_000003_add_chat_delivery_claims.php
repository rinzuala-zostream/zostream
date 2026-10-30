<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_outbox', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->table('chat_outbox', fn (Blueprint $table) => $table->dropColumn(['claim_token', 'claimed_until']));
    }
};
