<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', fn (Blueprint $t) => $t->json('watch_invite')->nullable());
        Schema::create('chat_watch_sessions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('room_id')->index();
            $t->unsignedBigInteger('message_id')->unique();
            $t->string('host_user_id', 191);
            $t->unsignedBigInteger('host_stream_id');
            $t->string('stream_token_hash', 64);
            $t->string('content_type', 16);
            $t->string('content_id', 191);
            $t->unsignedInteger('position_ms')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_watch_sessions');
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn('watch_invite'));
    }
};
