<?php

use App\Services\RedemptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redemption_pending_devices')) {
            app(RedemptionService::class)->activateLegacyCategoryGrants();
            Schema::dropIfExists('redemption_pending_devices');
        }
    }

    public function down(): void
    {
        // Activated grants are irreversible, so this data migration has no rollback.
    }
};
