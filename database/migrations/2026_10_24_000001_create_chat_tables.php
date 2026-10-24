<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rooms', function (Blueprint $t) {
            $t->id();
            $t->string('room_key', 191)->unique();
            $t->string('type', 16);
            $t->string('content_id', 191)->nullable();
            $t->boolean('closed')->default(false);
            $t->timestamps();
        });
        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('room_id');
            $t->string('user_id', 191);
            $t->uuid('request_id');
            $t->string('display_name', 100);
            $t->text('body');
            $t->boolean('spoiler')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_id']);
            $t->index(['room_id', 'id']);
            $t->index('created_at');
        });
        Schema::create('chat_bans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('room_id');
            $t->string('user_id', 191);
            $t->string('kind', 16);
            $t->timestamp('expires_at')->nullable();
            $t->unique(['room_id', 'user_id']);
        });
        Schema::create('chat_blocks', function (Blueprint $t) {
            $t->id();
            $t->string('user_id', 191);
            $t->string('blocked_user_id', 191);
            $t->unique(['user_id', 'blocked_user_id']);
        });
        Schema::create('chat_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('message_id');
            $t->string('user_id', 191);
            $t->string('reason', 500);
            $t->string('status', 16)->default('pending');
            $t->timestamps();
            $t->unique(['message_id', 'user_id']);
        });
        Schema::create('chat_moderation_actions', function (Blueprint $t) {
            $t->id();
            $t->string('admin_id', 191);
            $t->unsignedBigInteger('room_id');
            $t->string('action', 32);
            $t->string('target', 191);
            $t->string('reason', 500);
            $t->timestamps();
        });
        Schema::create('chat_outbox', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('room_id');
            $t->string('kind', 32);
            $t->unsignedBigInteger('message_id')->nullable();
            $t->json('analytics')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('analytics_delivered_at')->nullable();
            $t->timestamps();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->unsignedSmallInteger('analytics_attempts')->default(0);
            $t->timestamp('analytics_available_at')->nullable();
            $t->index(['delivered_at', 'available_at', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['chat_outbox', 'chat_moderation_actions', 'chat_reports', 'chat_blocks', 'chat_bans', 'chat_messages', 'chat_rooms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
