<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        ChatDatabase::schema()->table('chat_invite_rooms', function (Blueprint $table): void {
            $table->string('room_name', 60)->default('Private room')->after('host_user_id');
        });
    }

    public function down(): void
    {
        ChatDatabase::schema()->table('chat_invite_rooms', function (Blueprint $table): void {
            $table->dropColumn('room_name');
        });
    }
};
