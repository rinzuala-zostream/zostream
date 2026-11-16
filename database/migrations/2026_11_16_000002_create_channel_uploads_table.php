<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('channel_id')->constrained('channels');
            $table->foreignId('content_id')->nullable()->constrained('channel_contents');
            $table->string('user_id');
            $table->string('kind', 20);
            $table->string('filename');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('chunk_size');
            $table->string('status', 20)->default('uploading');
            $table->string('mime', 100)->nullable();
            $table->text('url')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['channel_id', 'content_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_uploads');
    }
};
