<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
        });

        ChatDatabase::schema()->table('chat_outbox', function (Blueprint $table): void {
            $table->unsignedBigInteger('firebase_cursor')->nullable()->index();
            $table->timestamp('firebase_delivered_at')->nullable();
            $table->unsignedSmallInteger('firebase_attempts')->default(0);
            $table->timestamp('firebase_available_at')->nullable();
            $table->uuid('firebase_claim_token')->nullable()->index();
            $table->timestamp('firebase_claimed_until')->nullable()->index();
            $table->index(['firebase_delivered_at', 'firebase_available_at', 'id'], 'chat_outbox_firebase_pending_idx');
        });

        ChatDatabase::schema()->create('chat_firebase_room_cursors', function (Blueprint $table): void {
            $table->unsignedBigInteger('room_id')->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamp('updated_at');
        });

        ChatDatabase::schema()->create('chat_firebase_message_recipients', function (Blueprint $table): void {
            $table->string('firebase_uid', 68);
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('message_id');
            $table->timestamp('published_at');
            $table->primary(['firebase_uid', 'room_id', 'message_id'], 'chat_firebase_message_recipient_pk');
            $table->index(['published_at', 'firebase_uid', 'room_id', 'message_id'], 'chat_firebase_recipient_prune_idx');
        });

        ChatDatabase::schema()->create('chat_firebase_event_recipients', function (Blueprint $table): void {
            $table->string('firebase_uid', 68);
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('cursor');
            $table->timestamp('published_at');
            $table->primary(['firebase_uid', 'room_id', 'cursor'], 'chat_firebase_event_recipient_pk');
            $table->index(['published_at', 'firebase_uid', 'room_id', 'cursor'], 'chat_firebase_event_prune_idx');
        });

        ChatDatabase::schema()->create('chat_firebase_typing_records', function (Blueprint $table): void {
            $table->string('viewer_uid', 68);
            $table->unsignedBigInteger('room_id');
            $table->string('typist_uid', 68);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->primary(['viewer_uid', 'room_id', 'typist_uid'], 'chat_firebase_typing_record_pk');
            $table->index(['expires_at', 'viewer_uid', 'room_id', 'typist_uid'], 'chat_firebase_typing_expiry_idx');
            $table->index(['typist_uid', 'room_id', 'viewer_uid'], 'chat_firebase_typist_cleanup_idx');
        });

        // Existing SQL/Reverb history is deliberately not backfilled to RTDB.
        ChatDatabase::connection()->table('chat_outbox')->whereNull('firebase_delivered_at')->update(['firebase_delivered_at' => now()]);
    }

    public function down(): void
    {
        ChatDatabase::schema()->dropIfExists('chat_firebase_room_cursors');
        ChatDatabase::schema()->dropIfExists('chat_firebase_message_recipients');
        ChatDatabase::schema()->dropIfExists('chat_firebase_event_recipients');
        ChatDatabase::schema()->dropIfExists('chat_firebase_typing_records');
        ChatDatabase::schema()->table('chat_outbox', function (Blueprint $table): void {
            $table->dropIndex('chat_outbox_firebase_pending_idx');
            $table->dropColumn(['firebase_cursor', 'firebase_delivered_at', 'firebase_attempts', 'firebase_available_at', 'firebase_claim_token', 'firebase_claimed_until']);
        });
        ChatDatabase::schema()->table('chat_messages', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
