<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_rules', function (Blueprint $table) {
            $table->string('rule', 32)->primary();
            $table->boolean('enabled')->default(true);
            $table->string('title_template', 200);
            $table->text('body_template');
            $table->timestamps();
        });
        Schema::table('engagement_deliveries', function (Blueprint $table) {
            $table->index('created_at', 'engagement_delivery_created');
        });
    }

    public function down(): void
    {
        Schema::table('engagement_deliveries', function (Blueprint $table) {
            $table->dropIndex('engagement_delivery_created');
        });
        Schema::dropIfExists('engagement_rules');
    }
};
