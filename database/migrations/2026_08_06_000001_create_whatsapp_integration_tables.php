<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->text('verify_token')->nullable();
            $table->boolean('auto_reply_enabled')->default(false);
            $table->text('auto_reply_message')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wamid')->nullable()->unique();
            $table->string('contact_phone', 40)->index();
            $table->string('contact_name')->nullable();
            $table->enum('direction', ['inbound', 'outbound'])->index();
            $table->string('type', 40)->default('text');
            $table->text('body')->nullable();
            $table->string('status', 40)->default('received')->index();
            $table->string('reply_to_wamid')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('message_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_settings');
    }
};
