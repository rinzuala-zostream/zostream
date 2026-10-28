<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_outbox', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_outbox', fn (Blueprint $table) => $table->dropColumn(['claim_token', 'claimed_until']));
    }
};
