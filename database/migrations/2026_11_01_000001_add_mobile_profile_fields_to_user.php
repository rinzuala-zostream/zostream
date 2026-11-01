<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique('user_username_unique');
            $table->string('profile_district_code', 20)->nullable();
            $table->string('profile_village_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropUnique('user_username_unique');
            $table->dropColumn(['username', 'profile_district_code', 'profile_village_code']);
        });
    }
};
