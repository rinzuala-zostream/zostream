<?php

namespace App\Console\Commands;

use App\Support\Chat\ChatDatabase;
use Illuminate\Console\Command;

final class PruneChat extends Command
{
    protected $signature = 'chat:prune';

    protected $description = 'Prune expired chat history in bounded batches';

    public function handle(): int
    {
        do {
            // Pending reports are retained for at most 90 days for review.
            $ids = ChatDatabase::connection()->table('chat_messages')->where('created_at', '<', now()->subDays(config('chat.retention_days')))
                ->where(function ($q) {
                    $q->where('created_at', '<', now()->subDays(90))->orWhereNotIn('id',
                        ChatDatabase::connection()->table('chat_reports')->select('message_id')->where('status', 'pending'));
                })->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            ChatDatabase::connection()->table('chat_reports')->whereIn('message_id', $ids)->delete();
            ChatDatabase::connection()->table('chat_watch_participants')->whereIn('session_id',
                ChatDatabase::connection()->table('chat_watch_sessions')->select('id')->whereIn('message_id', $ids))->delete();
            ChatDatabase::connection()->table('chat_watch_sessions')->whereIn('message_id', $ids)->delete();
            ChatDatabase::connection()->table('chat_messages')->whereIn('id', $ids)->delete();
        } while (true);
        ChatDatabase::connection()->table('chat_outbox')->whereNotNull('delivered_at')->where(fn ($q) => $q->whereNull('analytics')->orWhereNotNull('analytics_delivered_at'))->where('created_at', '<', now()->subDays(7))->delete();
        ChatDatabase::connection()->table('chat_bans')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        return self::SUCCESS;
    }
}
