<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;

/** Bounded SQL reconciliation for per-user Firebase event cursors. */
final class ChatSyncService
{
    public function __construct(private readonly ChatService $chat) {}

    public function sync(Request $request, int $roomId, int $cursor, array $known = [], ?int $recoveryWatermark = null): array
    {
        $this->chat->room($request, $roomId);
        $db = ChatDatabase::connection();
        $uid = (string) $request->input('auth_user_id');
        $blocked = $this->chat->blocked($uid);
        $state = $db->table('chat_firebase_room_cursors')->where('room_id', $roomId)->value('cursor');
        $current = (int) ($state ?? 0);
        abort_if($recoveryWatermark !== null && $recoveryWatermark > $current, 422, 'The recovery watermark is ahead of the committed room cursor.');
        $oldest = $db->table('chat_outbox')->where('room_id', $roomId)->whereNotNull('firebase_cursor')->min('firebase_cursor');
        $expired = $cursor > $current || ($cursor < $current && ($oldest === null || $cursor < (int) $oldest - 1));

        if ($expired) {
            // Keep the first recovery watermark fixed across inventory batches. New
            // outbox commits remain available after it and are fetched by normal sync.
            $watermark = $recoveryWatermark ?? $current;
            return $this->reconcile($request, $roomId, $known, $watermark, $blocked);
        }

        $rows = $db->table('chat_outbox')->where('room_id', $roomId)->where('firebase_cursor', '>', $cursor)
            ->orderBy('firebase_cursor')->limit(101)->get();
        $hasMore = $rows->count() > 100;
        $rows = $rows->take(100)->values();
        $messageIds = $rows->whereIn('kind', ['feed_created', 'feed_deleted', 'reactions', 'sync'])
            ->pluck('message_id')->filter()->unique()->values();
        $messages = $messageIds->isEmpty() ? collect() : $db->table('chat_messages')->where('room_id', $roomId)
            ->whereIn('id', $messageIds)->where('created_at', '>=', now()->subDays(config('chat.retention_days')))
            ->get($this->chat->messageColumns())->keyBy('id');
        $events = [];
        $readThrough = (int) ($db->table('chat_room_reads')->where('user_id', $uid)->where('room_id', $roomId)->value('last_read_message_id') ?? 0);
        foreach ($rows as $row) {
            $eventCursor = (int) $row->firebase_cursor;
            if (($row->recipient_user_id ?? null) !== null && (string) $row->recipient_user_id !== $uid) { continue; }
            $kind = match ($row->kind) {
                'feed_created' => 'created',
                'feed_deleted' => 'deleted',
                'reactions' => 'reactions',
                'read' => 'read',
                'room_invite_status' => 'room_invite_status',
                'ended' => 'ended',
                'sync' => 'sync',
                default => null,
            };
            if ($kind === null) { continue; }
            $event = ['cursor' => $eventCursor, 'kind' => $kind,
                'message_id' => $row->message_id === null ? null : (string) $row->message_id];
            if (in_array($row->kind, ['feed_created', 'feed_deleted', 'reactions', 'sync'], true) && $row->message_id !== null) {
                $message = $messages->get($row->message_id);
                if (! $message || in_array((string) $message->user_id, $blocked, true)) { continue; }
                $event['message'] = $this->decorateMessage($message, $uid);
                if (in_array($row->kind, ['feed_created', 'feed_deleted'], true)
                    && (string) $message->user_id !== $uid && (int) $message->id > $readThrough) {
                    $event['unread_delta'] = $row->kind === 'feed_created' && $message->deleted_at === null ? 1 : -1;
                }
            }
            if ($row->kind === 'read' && (string) ($row->recipient_user_id ?? '') === $uid) {
                $event['read_state'] = app(ChatReadService::class)->readState($uid, $roomId);
            }
            $events[] = $event;
        }
        $nextCursor = $hasMore && $rows->isNotEmpty() ? (int) $rows->last()->firebase_cursor : $current;

        return ['cursor_expired' => false, 'events' => $events, 'next_cursor' => $nextCursor, 'has_more' => $hasMore];
    }

    private function reconcile(Request $request, int $roomId, array $known, int $current, array $blocked): array
    {
        $db = ChatDatabase::connection();
        $uid = (string) $request->input('auth_user_id');
        $ids = collect($known)->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $knownById = collect($known)->keyBy(fn ($record) => (int) $record['id']);
        $reconciled = $ids->isEmpty() ? collect() : $db->table('chat_messages')->where('room_id', $roomId)->whereIn('id', $ids)
            ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))
            ->get($this->chat->messageColumns())->keyBy('id');
        $visibleKnown = [];
        $hiddenIds = [];
        foreach ($ids as $id) {
            $message = $reconciled->get($id);
            if (! $message || in_array((string) $message->user_id, $blocked, true)) {
                $hiddenIds[] = (string) $id;
                continue;
            }
            $knownRevision = (int) ($knownById->get($id)['revision'] ?? 0);
            if ((int) ($message->revision ?? 1) !== $knownRevision) {
                $visibleKnown[] = $this->decorateMessage($message, $uid);
            }
        }
        $recent = $db->table('chat_messages')->where('room_id', $roomId)->whereNotIn('user_id', $blocked)
            ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))->orderByDesc('id')->limit(30)
            ->get($this->chat->messageColumns())->reverse()->map(fn ($message) => $this->chat->message($message))->all();
        $recent = $this->decorateMany($recent, $uid);

        return ['cursor_expired' => true, 'events' => [], 'next_cursor' => $current,
            'recovery_watermark' => $current, 'has_more' => false,
            'reconciled_messages' => $visibleKnown,
            'hidden_message_ids' => $hiddenIds, 'recent_messages' => $recent,
            'read_state' => app(ChatReadService::class)->readState($uid, $roomId),
            'reconcile_pins' => true];
    }

    private function decorateMessage(object $message, string $uid): array
    {
        $base = $this->chat->message($message);
        return $this->decorateMany([$base], $uid)[0];
    }

    private function decorateMany(array $messages, string $uid): array
    {
        if ($messages === []) { return []; }
        $messages = app(PrivateWatchService::class)->hydrate($messages);
        $messages = app(RoomInvitationService::class)->hydrate($messages);
        return app(ChatInteractions::class)->decorate($messages, $uid);
    }
}
