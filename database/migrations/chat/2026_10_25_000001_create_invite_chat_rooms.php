<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->create('chat_invite_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id')->unique();
            $table->string('host_user_id', 191);
            $table->string('content_type', 16)->nullable();
            $table->string('content_id', 191)->nullable();
            $table->unsignedInteger('watch_position_ms')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['host_user_id', 'active']);
        });

        ChatDatabase::schema()->create('chat_room_invites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('inviter_user_id', 191);
            $table->string('invitee_user_id', 191);
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'invitee_user_id']);
            $table->index(['invitee_user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->dropIfExists('chat_room_invites');
        ChatDatabase::schema()->dropIfExists('chat_invite_rooms');
    }
};
