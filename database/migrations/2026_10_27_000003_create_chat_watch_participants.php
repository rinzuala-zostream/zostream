<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_watch_participants', function (Blueprint $t): void {
            $t->unsignedBigInteger('session_id');
            $t->string('user_id', 191);
            $t->timestamp('joined_at');
            $t->unsignedBigInteger('stream_id')->nullable();
            $t->string('stream_token_hash', 64)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->primary(['session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_watch_participants');
    }
};
