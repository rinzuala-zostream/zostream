<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_login_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_hash', 64)->unique();
            $table->string('user_id', 225)->index();
            $table->string('device_id', 225)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_login_grants');
    }
};
