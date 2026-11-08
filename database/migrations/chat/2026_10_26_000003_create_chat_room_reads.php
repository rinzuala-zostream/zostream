<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->create('chat_room_reads', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 191);
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'room_id']);
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->dropIfExists('chat_room_reads');
    }
};
