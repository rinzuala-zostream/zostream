<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_themes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('published_version_id')->nullable()->index();
            $table->string('created_by', 128);
            $table->timestamps();
        });
        Schema::create('app_theme_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('app_themes');
            $table->unsignedInteger('number');
            $table->json('document');
            $table->string('published_by', 128);
            $table->timestamp('published_at');
            $table->unique(['theme_id', 'number']);
        });
        Schema::create('app_theme_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('app_theme_versions');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->index(['start_at', 'end_at']);
        });
        Schema::create('app_theme_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('app_themes');
            $table->string('asset_key', 40);
            $table->string('path');
            $table->text('url');
            $table->string('checksum', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->timestamp('created_at');
        });
        Schema::create('app_theme_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->nullable()->constrained('app_theme_versions');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->string('actor_id', 128);
            $table->string('reason', 500);
        });
        Schema::create('app_theme_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->nullable()->constrained('app_themes');
            $table->string('actor_id', 128);
            $table->string('action', 40);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');
        });
        // Serializes publication and override changes, including when no override exists yet.
        Schema::create('app_theme_controls', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
        });
        DB::table('app_theme_controls')->insert(['id' => 1]);
    }

    public function down(): void
    {
        foreach (['app_theme_controls', 'app_theme_audit_logs', 'app_theme_overrides', 'app_theme_assets', 'app_theme_schedules', 'app_theme_versions', 'app_themes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
