<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_invite_rooms', function (Blueprint $table): void {
            $table->unsignedBigInteger('host_stream_id')->nullable()->index();
            $table->string('stream_token_hash', 64)->nullable();
        });
        if (! Schema::hasTable('n_active_streams') || ! Schema::hasTable('n_devices')) { return; }
        DB::table('chat_invite_rooms')->whereNotNull('content_type')->where('active', true)
            ->orderBy('id')->chunkById(200, function ($rooms): void {
                foreach ($rooms as $room) {
                    $stream = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
                        ->where('d.user_id', $room->host_user_id)->where('s.status', 'active')
                        ->where('s.content_type', $room->content_type)->where('s.content_key', $room->content_id)
                        ->where('s.last_ping', '>=', now()->subMinutes(3))->orderByDesc('s.id')->first(['s.id', 's.stream_token']);
                    DB::table('chat_invite_rooms')->where('id', $room->id)->update($stream
                        ? ['host_stream_id' => $stream->id, 'stream_token_hash' => hash('sha256', $stream->stream_token)]
                        : ['active' => false]);
                    if (! $stream) {
                        DB::table('chat_outbox')->insert(['room_id' => $room->room_id, 'kind' => 'ended',
                            'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('chat_invite_rooms', function (Blueprint $table): void {
            $table->dropIndex(['host_stream_id']);
            $table->dropColumn(['host_stream_id', 'stream_token_hash']);
        });
    }
};
