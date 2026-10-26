<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_invite_rooms', function (Blueprint $table): void {
            $table->timestamp('idle_expires_at')->nullable()->index();
        });
        DB::table('chat_invite_rooms')->whereNull('content_type')->orderBy('id')->chunkById(200, function ($rooms): void {
            $latest = DB::table('chat_messages')->whereIn('room_id', $rooms->pluck('room_id'))
                ->selectRaw('room_id, MAX(created_at) as last_message_at')->groupBy('room_id')->pluck('last_message_at', 'room_id');
            foreach ($rooms as $room) {
                DB::table('chat_invite_rooms')->where('id', $room->id)->update([
                    'idle_expires_at' => Carbon::parse($latest[$room->room_id] ?? $room->created_at)->addHour(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_invite_rooms', function (Blueprint $table): void {
            $table->dropIndex(['idle_expires_at']);
            $table->dropColumn('idle_expires_at');
        });
    }
};
