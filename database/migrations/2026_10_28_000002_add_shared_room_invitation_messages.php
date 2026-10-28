<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', fn (Blueprint $t) => $t->unsignedBigInteger('invite_room_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $t): void {
            $t->dropIndex(['invite_room_id']);
            $t->dropColumn('invite_room_id');
        });
    }
};
