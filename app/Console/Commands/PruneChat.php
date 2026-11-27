<?php

namespace App\Console\Commands;

use App\Support\Chat\ChatDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PruneChat extends Command
{
    protected $signature = 'chat:prune';

    protected $description = 'Prune expired chat history in bounded batches';

    public function handle(): int
    {
        try {
            app(\App\Support\Chat\ChatFirebase::class)->pruneExpiredTypingRecords();
        } catch (\Throwable $error) {
            Log::warning('Expired Firebase chat typing cleanup deferred', ['exception' => $error::class]);
        }
        $this->pruneFirebaseFeeds();
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
            ChatDatabase::connection()->table('chat_reactions')->whereIn('message_id', $ids)->delete();
            ChatDatabase::connection()->table('chat_messages')->whereIn('id', $ids)->delete();
        } while (true);
        ChatDatabase::connection()->table('chat_outbox')->whereNotNull('delivered_at')->where(fn ($q) => $q->whereNull('analytics')->orWhereNotNull('analytics_delivered_at'))->where('created_at', '<', now()->subDays(7))->delete();
        ChatDatabase::connection()->table('chat_bans')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        return self::SUCCESS;
    }

    private function pruneFirebaseFeeds(): void
    {
        $db = ChatDatabase::connection();
        $this->pruneFirebaseAccountEvents($db);
        if (! ChatDatabase::schema()->hasTable('chat_firebase_message_recipients')
            || ! ChatDatabase::schema()->hasTable('chat_firebase_event_recipients')) { return; }
        $cutoff = now()->subDays(7);
        do {
            $rows = $db->table('chat_firebase_message_recipients')->where('published_at', '<', $cutoff)
                ->orderBy('published_at')->limit(500)->get();
            if ($rows->isEmpty()) { break; }
            try {
                $this->deleteFirebaseMessageRows($rows);
            } catch (\Throwable $error) {
                Log::warning('Expired Firebase chat feed cleanup deferred', ['exception' => $error::class]);
                return;
            }
        } while (true);

        do {
            $rows = $db->table('chat_firebase_event_recipients')->where('published_at', '<', $cutoff)
                ->orderBy('published_at')->limit(500)->get();
            if ($rows->isEmpty()) { break; }
            try {
                app(\App\Support\Chat\ChatFirebase::class)->deleteUserFeedEvents($rows->all());
            } catch (\Throwable $error) {
                Log::warning('Expired Firebase chat event cleanup deferred', ['exception' => $error::class]);
                return;
            }
            $this->deleteFirebaseRecipientRows('chat_firebase_event_recipients', $rows, 'cursor');
        } while (true);

        // The client protocol can read at most 100 messages and 500 events per
        // user/room. Remove older cached copies daily; SQL remains the bounded
        // reconciliation source for messages and expired event cursors.
        foreach ($db->table('chat_firebase_message_recipients')->select('firebase_uid', 'room_id')->groupBy('firebase_uid', 'room_id')->get() as $group) {
            do {
                $excess = $db->table('chat_firebase_message_recipients')->where('firebase_uid', $group->firebase_uid)->where('room_id', $group->room_id)
                    ->orderByDesc('message_id')->offset(100)->limit(500)->get();
                if ($excess->isEmpty()) { break; }
                try { $this->deleteFirebaseMessageRows($excess); }
                catch (\Throwable $error) {
                    Log::warning('Firebase chat feed cap cleanup deferred', ['exception' => $error::class]);
                    return;
                }
            } while (true);
        }
        foreach ($db->table('chat_firebase_event_recipients')->select('firebase_uid', 'room_id')->groupBy('firebase_uid', 'room_id')->get() as $group) {
            do {
                $excess = $db->table('chat_firebase_event_recipients')->where('firebase_uid', $group->firebase_uid)->where('room_id', $group->room_id)
                    ->orderByDesc('cursor')->offset(500)->limit(500)->get();
                if ($excess->isEmpty()) { break; }
                try { app(\App\Support\Chat\ChatFirebase::class)->deleteUserFeedEvents($excess->all()); }
                catch (\Throwable $error) {
                    Log::warning('Firebase chat event cap cleanup deferred', ['exception' => $error::class]);
                    return;
                }
                $this->deleteFirebaseRecipientRows('chat_firebase_event_recipients', $excess, 'cursor');
            } while (true);
        }
    }

    private function pruneFirebaseAccountEvents(\Illuminate\Database\Connection $db): void
    {
        if (! ChatDatabase::schema()->hasTable('chat_firebase_badge_recipients')) { return; }
        $table = 'chat_firebase_badge_recipients';
        $cutoff = now()->subDays(7);
        do {
            $rows = $db->table($table)->where('published_at', '<', $cutoff)->orderBy('published_at')->limit(500)->get();
            if ($rows->isEmpty()) { break; }
            try { app(\App\Support\Chat\ChatFirebase::class)->deleteAccountEvents($rows->all()); }
            catch (\Throwable $error) {
                Log::warning('Expired Firebase account chat event cleanup deferred', ['exception' => $error::class]);
                return;
            }
            $this->deleteFirebaseBadgeRecipientRows($rows);
        } while (true);

        foreach ($db->table($table)->select('firebase_uid')->groupBy('firebase_uid')->get() as $group) {
            do {
                $excess = $db->table($table)->where('firebase_uid', $group->firebase_uid)
                    ->orderByDesc('outbox_id')->offset(500)->limit(500)->get();
                if ($excess->isEmpty()) { break; }
                try { app(\App\Support\Chat\ChatFirebase::class)->deleteAccountEvents($excess->all()); }
                catch (\Throwable $error) {
                    Log::warning('Firebase account chat event cap cleanup deferred', ['exception' => $error::class]);
                    return;
                }
                $this->deleteFirebaseBadgeRecipientRows($excess);
            } while (true);
        }
    }

    private function deleteFirebaseBadgeRecipientRows(\Illuminate\Support\Collection $rows): void
    {
        ChatDatabase::connection()->table('chat_firebase_badge_recipients')->where(function ($query) use ($rows): void {
            foreach ($rows as $row) {
                $query->orWhere(fn ($tuple) => $tuple->where('firebase_uid', $row->firebase_uid)->where('outbox_id', $row->outbox_id));
            }
        })->delete();
    }

    private function deleteFirebaseMessageRows(\Illuminate\Support\Collection $rows): void
    {
        app(\App\Support\Chat\ChatFirebase::class)->deleteUserFeedMessages($rows->all());
        $this->deleteFirebaseRecipientRows('chat_firebase_message_recipients', $rows, 'message_id');
    }

    private function deleteFirebaseRecipientRows(string $table, \Illuminate\Support\Collection $rows, string $key): void
    {
        ChatDatabase::connection()->table($table)->where(function ($query) use ($rows, $key): void {
            foreach ($rows as $row) {
                $query->orWhere(fn ($tuple) => $tuple->where('firebase_uid', $row->firebase_uid)
                    ->where('room_id', $row->room_id)->where($key, $row->{$key}));
            }
        })->delete();
    }
}
