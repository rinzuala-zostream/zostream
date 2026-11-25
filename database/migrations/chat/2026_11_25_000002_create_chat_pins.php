<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->create('chat_pins', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('room_id');
            $t->unsignedBigInteger('message_id');
            $t->string('pinned_by', 191);
            $t->timestamp('created_at');
            $t->unique(['room_id', 'message_id']);
            $t->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->dropIfExists('chat_pins');
    }
};
