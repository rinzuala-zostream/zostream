<?php

use App\Support\Chat\ChatDatabase;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (config('chat.firebase.enabled')) { return; }

        // Keep SQL history and sync cursors. Rows queued while Firebase was
        // disabled should not populate RTDB on the first deployment.
        $db = ChatDatabase::connection();
        $db->table('chat_outbox')->whereNotNull('firebase_cursor')->whereNull('firebase_delivered_at')
            ->whereNull('firebase_available_at')->whereNull('firebase_claim_token')
            ->select('id')->chunkById(1000, function ($rows) use ($db): void {
                $db->table('chat_outbox')->whereIn('id', $rows->pluck('id'))->whereNull('firebase_delivered_at')
                    ->whereNull('firebase_available_at')->whereNull('firebase_claim_token')
                    ->update(['firebase_delivered_at' => now()]);
            });
    }

    public function down(): void
    {
        // Reopening skipped deliveries would backfill the Firebase feed.
    }
};
