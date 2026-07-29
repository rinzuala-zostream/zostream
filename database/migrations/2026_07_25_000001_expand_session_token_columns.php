<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_tokens', function (Blueprint $table) {
            $table->string('access_token', 255)->change();
            $table->string('refresh_token', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('session_tokens', function (Blueprint $table) {
            $table->string('access_token', 64)->change();
            $table->string('refresh_token', 64)->change();
        });
    }
};
