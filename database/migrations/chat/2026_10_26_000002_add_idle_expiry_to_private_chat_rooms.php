<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_invite_rooms', function (Blueprint $table): void {
            $table->timestamp('idle_expires_at')->nullable()->index();
        });
        ChatDatabase::connection()->table('chat_invite_rooms')->whereNull('content_type')->orderBy('id')->chunkById(200, function ($rooms): void {
            $latest = ChatDatabase::connection()->table('chat_messages')->whereIn('room_id', $rooms->pluck('room_id'))
                ->selectRaw('room_id, MAX(created_at) as last_message_at')->groupBy('room_id')->pluck('last_message_at', 'room_id');
            foreach ($rooms as $room) {
                ChatDatabase::connection()->table('chat_invite_rooms')->where('id', $room->id)->update([
                    'idle_expires_at' => Carbon::parse($latest[$room->room_id] ?? $room->created_at)->addHour(),
                ]);
            }
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->table('chat_invite_rooms', function (Blueprint $table): void {
            $table->dropIndex(['idle_expires_at']);
            $table->dropColumn('idle_expires_at');
        });
    }
};
