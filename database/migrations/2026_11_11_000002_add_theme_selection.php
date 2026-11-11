<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_themes', function (Blueprint $table) {
            $table->string('preset_key', 40)->nullable()->index();
        });
        Schema::table('app_theme_controls', function (Blueprint $table) {
            $table->foreignId('selected_theme_id')->nullable()->constrained('app_themes');
        });
        $keys = ['default', 'christmas', 'new_year', 'valentine', 'chapchar_kut', 'easter', 'independence_day', 'diwali', 'halloween', 'pawl_kut', 'anniversary', 'mim_kut'];
        foreach (DB::table('app_themes')->select(['id', 'key'])->get() as $theme) {
            foreach ($keys as $key) {
                if (preg_match('/^'.preg_quote($key, '/').'(?:_\d{4}(?:_[a-z0-9]{8})?)?$/D', $theme->key)) {
                    DB::table('app_themes')->where('id', $theme->id)->update(['preset_key' => $key]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('app_theme_controls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_theme_id');
        });
        Schema::table('app_themes', function (Blueprint $table) {
            $table->dropIndex(['preset_key']);
            $table->dropColumn('preset_key');
        });
    }
};
