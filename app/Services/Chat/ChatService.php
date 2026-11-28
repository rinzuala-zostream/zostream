<?php

namespace App\Services\Chat;

use App\Support\AdminAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public function __construct(public FirebaseChatStore $store) {}

    private function publish(array $updates): void
    {
        $this->store->update($updates);
        foreach (array_keys($updates) as $path) {
            if (str_starts_with($path, 'push_outbox/')) {
                try {
                    // Queue the job; afterResponse executes it synchronously at
                    // termination and can keep API workers occupied by FCM I/O.
                    \App\Jobs\DeliverChatPush::dispatch(substr($path, strlen('push_outbox/')));
                } catch (\Throwable $e) {
                    // The write already committed. Maintenance retries the durable
                    // pending outbox when the queue becomes available again.
                    report($e);
                }
            }
        }
    }

    public function locked(string $room, callable $work): mixed
    {
        // Use a shared database/Redis cache store on every API/queue host.
        return Cache::lock('chat:room:'.hash('sha256', $room), 120)->block(10, $work);
    }

    private function lockedSend(string $room, string $uid, string $requestId, callable $work): mixed
    {
        // Persistent public rooms cannot be ended. Serialize their sends only
        // against retries of the same operation; a room-wide lock made every
        // message wait behind unrelated Firebase reads and writes.
        $key = hash('sha256', $room.':'.$uid.':'.strtolower($requestId));

        return Cache::lock('chat:send:'.$key, 120)->block(10, $work);
    }

    public function user(string $uid): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $uid), 403, 'Your chat identity is unavailable.');
        $user = DB::table('user')->where('uid', $uid)->first(['uid', 'username', 'name']);
        abort_unless($user, 403, 'Your chat identity is unavailable.');

        $username = trim((string) ($user->username ?? ''));
        $name = trim((string) ($user->name ?? ''));
        $displayName = $username !== '' ? $username : (
            preg_match('/^[\p{L}\s]+$/u', $name) === 1 && preg_match('/\p{L}/u', $name) === 1
                ? $name
                : 'Viewer'
        );

        return ['uid' => $uid, 'name' => $displayName, 'is_admin' => app(AdminAccess::class)->isAdmin($uid)];
    }

    public function room(string $id, ?string $uid = null, bool $send = false): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id), 404);
        $state = $this->store->get("rooms/$id/state");
        abort_unless(is_array($state), 404, 'This chat room is unavailable.');
        // Watch rooms are deliberately excluded from ordinary chat expiry.
        $expired = ($state['room_type'] ?? '') === 'invite' && empty($state['content_type'])
            && empty($state['watch_active']) && (int) ($state['expires_at'] ?? PHP_INT_MAX) <= now()->valueOf();
        abort_unless(($state['active'] ?? false) && ! $expired, 410, 'This room has ended.');
        if ($uid !== null) {
            $member = $this->store->get("rooms/$id/members/$uid");
            abort_unless(($member['active'] ?? false) === true, 403, 'You are not a member of this room.');
            if ($send) {
                abort_unless(($member['can_send'] ?? false) && ($state['can_send'] ?? false), 403, 'You cannot send messages in this room.');
            }
        }

        return $state;
    }

    public function descriptor(string $id, string $uid): array
    {
        $s = $this->room($id, $uid);

        return ['room_id' => $id, 'room_type' => $s['room_type'], 'room_name' => $s['room_name'] ?? null,
            'content_type' => $s['content_type'] ?? null, 'content_id' => $s['content_id'] ?? null,
            'content_title' => $s['content_title'] ?? null, 'is_host' => ($s['creator_id'] ?? '') === $uid,
            'is_admin' => $this->user($uid)['is_admin'], 'user_id' => $uid,
            'firebase_realtime' => true, 'sync_cursor' => (int) ($s['sync_cursor'] ?? 0),
            'history_pagination' => true, 'first_unread_id' => $this->firstUnread($id, $uid),
            'last_read_message_id' => (string) ($this->store->get("reads/$uid/$id") ?? '0'),
            'last_message_id' => (string) ($this->store->get("rooms/$id/last_message_id") ?? '0'),
            'channel' => '', 'websocket_url' => '', 'watch_position_ms' => 0];
    }

    public function join(array $actor, string $type, ?string $content): array
    {
        $id = $type === 'global' ? 'global' : $type.'-'.$content;

        return $this->locked($id, function () use ($actor, $type, $content, $id) {
            $uid = $actor['uid'];
            $s = $this->store->get("rooms/$id/state");
            if (! $s) {
                $title = null;
                if ($type !== 'global') {
                    abort_unless(ctype_digit((string) $content), 422, 'Choose a valid title.');
                    $title = DB::table($type === 'movie' ? 'movie' : 'episode')->where('num', $content)->where('isEnable', true)->value('title');
                    abort_unless($title, 404, 'This title is unavailable.');
                }
                $s = ['active' => true, 'can_send' => true, 'room_type' => $type, 'content_id' => $content,
                    'content_type' => $type === 'global' ? null : $type, 'content_title' => $title,
                    'room_name' => $type === 'global' ? 'Global Chat' : $title, 'sync_cursor' => 0];
                $this->store->update(["rooms/$id/state" => $s]);
            }
            $this->room($id);
            $newIndex = ! $this->store->get("user_rooms/$uid/$id");
            $this->store->update(["user_rooms/$uid/$id" => true]);
            $member = $this->store->get("rooms/$id/members/$uid");
            // A denied member is never silently reactivated by joining.
            abort_if(is_array($member) && ! ($member['active'] ?? false), 403, 'Your room access has been removed.');
            if (! $member) {
                $this->store->update(["rooms/$id/members/$uid" => $this->membership($actor), "user_rooms/$uid/$id" => true]);
            }
            if ($newIndex) {
                $updates = [];
                $this->accountEvent($updates, $uid, $this->store->nextCursor(), 'room_state_invalidated');
                $this->store->update($updates);
            }

            return $this->descriptor($id, $uid);
        });
    }

    private function membership(array $actor): array
    {
        return ['active' => true, 'can_send' => true, 'user_id' => $actor['uid'],
            'display_name' => $actor['name'], 'is_admin' => $actor['is_admin'], 'joined_at' => now()->toIso8601String()];
    }

    public function message(string $room, string $id): array
    {
        abort_unless(ctype_digit($id), 404);
        $record = $this->store->get("rooms/$room/messages/$id");
        abort_unless(is_array($record), 404, 'This message is unavailable.');

        return $record;
    }

    public function publicMessage(array $r, string $uid): array
    {
        $reactions = [];
        $mine = [];
        foreach ($r['reaction_users'] ?? [] as $emojiKey => $users) {
            $emoji = base64_decode(strtr($emojiKey, '-_', '+/'));
            $visible = array_filter($users, fn ($active) => $active);
            if ($visible) {
                $reactions[] = ['emoji' => $emoji, 'count' => count($visible)];
            }
            if ($users[$uid] ?? false) {
                $mine[] = $emoji;
            }
        }

        return ['id' => (string) $r['id'], 'room_id' => $r['room_id'], 'user_id' => $r['sender_id'],
            'request_id' => $r['client_op_id'], 'display_name' => $r['sender_name'], 'is_admin' => $r['sender_is_admin'] ?? false,
            'body' => $r['body'], 'spoiler' => $r['spoiler'], 'deleted' => $r['deleted'] ?? false,
            'created_at' => $r['created_at_iso'], 'reply_to_id' => $r['reply_to_id'] ?? null,
            'reply' => $r['reply'] ?? null, 'movie_suggestion' => $r['suggestion'] ?? null,
            'room_invite' => $r['invitation'] ?? null, 'moment' => $r['moment'] ?? null,
            'watch_invite' => $r['watch_invite'] ?? null, 'reactions' => $reactions, 'my_reactions' => $mine];
    }

    private function members(string $room): array
    {
        return array_filter($this->store->get("rooms/$room/members") ?? [], fn ($m) => ($m['active'] ?? false) === true);
    }

    private function accountEvent(array &$updates, string $uid, int $cursor, string $kind = 'unread_invalidated'): void
    {
        $updates['user_account_events/'.$uid.'/'.FirebaseChatStore::key($cursor)] = ['kind' => $kind, 'cursor' => $cursor];
    }

    private function event(array &$updates, string $room, array $record, int $cursor, string $kind, ?array $members = null): void
    {
        foreach ($members ?? $this->members($room) as $uid => $member) {
            $uid = (string) $uid;
            $updates["user_messages/$uid/$room/".$record['id']] = $record;
            $updates['user_sync_events/'.$uid.'/'.$room.'/'.FirebaseChatStore::key($cursor)] = [
                'kind' => $kind, 'message_id' => $record['id'], 'cursor' => $cursor, 'overlay_live' => $kind === 'created'];
            $this->accountEvent($updates, $uid, $cursor);
        }
    }

    private function push(array &$updates, string $recipient, string $sender, string $room, int $cursor, string $kind, string $body, array $roomState, array $extra = []): void
    {
        if ($recipient === $sender) {
            return;
        }
        $id = hash('sha256', "$cursor:$recipient");
        $updates["push_outbox/$id"] = ['status' => 'pending', 'recipient' => $recipient, 'sender' => $sender,
            'room_id' => $room, 'created_at' => now()->timestamp, 'body' => $body, 'kind' => $kind,
            'data' => array_merge(['key' => $kind === 'invite' ? 'chat_invite' : ($roomState['room_type'] === 'invite' ? 'chat_message' : 'chat_activity'),
                'room_id' => $room, 'room_type' => $roomState['room_type'], 'watch_content_id' => $roomState['content_id'] ?? '',
                'notification_id' => $id], $extra)];
    }

    public function send(array $actor, string $room, array $p): array
    {
        $startedAt = microtime(true);
        $workStartedAt = null;
        $stageStartedAt = null;
        $stageDurations = [];
        $markStage = function (string $stage) use (&$stageStartedAt, &$stageDurations): void {
            $now = microtime(true);
            $stageDurations[$stage] = (int) round(($now - ($stageStartedAt ?? $now)) * 1000);
            $stageStartedAt = $now;
        };
        $work = function () use ($actor, $room, $p, &$workStartedAt, &$stageStartedAt, $markStage) {
            $workStartedAt = $stageStartedAt = microtime(true);
            $uid = $actor['uid'];
            $s = $this->room($room, $uid, true);
            $markStage('room_authorization_ms');
            $request = strtolower($p['request_id']);
            if ($id = $this->store->get("room_requests/$room/$uid/$request")) {
                $message = $this->publicMessage($this->message($room, $id), $uid);
                $markStage('idempotent_retry_ms');

                return $message;
            }
            $markStage('dedupe_lookup_ms');
            $body = trim($p['body']);
            if ($body === '') {
                throw ValidationException::withMessages(['body' => 'Enter a message.']);
            }
            $reply = null;
            if ($replyID = $p['reply_to_id'] ?? null) {
                $original = $this->message($room, $replyID);
                abort_if(($original['deleted'] ?? false), 422, 'This reply target is unavailable.');
                $reply = ['id' => $original['id'], 'user_id' => $original['sender_id'], 'display_name' => $original['sender_name'],
                    'is_admin' => $original['sender_is_admin'] ?? false, 'body' => $original['spoiler'] ? null : $original['body'], 'spoiler' => $original['spoiler'], 'deleted' => false];
            }
            $suggestion = isset($p['movie_suggestion']) ? $this->movie($p['movie_suggestion']['id'], (bool) ($p['isChildMode'] ?? false), (bool) ($p['age_restriction'] ?? false)) : null;
            $invitation = null;
            if (isset($p['room_invite'])) {
                abort_unless(in_array($s['room_type'], ['global', 'movie', 'episode']), 422);
                $target = $this->room($p['room_invite']['room_id'], $uid);
                abort_unless(($target['creator_id'] ?? '') === $uid && empty($target['content_type']), 403);
                $invitation = ['room_id' => $p['room_invite']['room_id'], 'room_name' => $target['room_name'], 'active' => true];
            }
            if (isset($p['moment'])) {
                abort_unless(($s['content_id'] ?? null) === $p['moment']['content_id'] && ($s['content_type'] ?? null) === $p['moment']['content_type'], 422, 'This movie moment belongs to another title.');
            }
            $markStage('validation_and_extra_reads_ms');
            $cursor = $this->store->nextCursor();
            $markStage('cursor_transaction_ms');
            $id = (string) $cursor;
            $r = ['id' => $id, 'room_id' => $room, 'sender_id' => $uid, 'sender_name' => $actor['name'],
                'sender_is_admin' => $actor['is_admin'], 'client_op_id' => $request, 'body' => $body,
                'spoiler' => (bool) ($p['spoiler'] ?? false), 'deleted' => false, 'reply_to_id' => $replyID,
                'reply' => $reply, 'suggestion' => $suggestion, 'invitation' => $invitation, 'moment' => $p['moment'] ?? null,
                'created_at_iso' => now()->utc()->toIso8601String(), 'cursor' => $cursor];
            $u = ["rooms/$room/messages/$id" => $r, "room_requests/$room/$uid/$request" => $id,
                "rooms/$room/last_message_id" => $id, "rooms/$room/state/sync_cursor" => $cursor];
            if ($s['room_type'] === 'invite' && empty($s['content_type'])) {
                $u["rooms/$room/state/expires_at"] = now()->addHour()->valueOf();
                $u["private_expiries/$room"] = now()->addHour()->valueOf();
            }
            $members = $this->members($room);
            $this->event($u, $room, $r, $cursor, 'created', $members);
            $recipients = $s['room_type'] === 'invite' ? array_keys($members) : [];
            if ($reply) {
                $recipients[] = $reply['user_id'];
            }
            foreach (array_unique($recipients) as $recipient) {
                $this->push($u, (string) $recipient, $uid, $room, $cursor, $reply && $recipient === $reply['user_id'] ? 'reply' : 'message',
                    $actor['name'].($r['spoiler'] ? ': Spoiler message' : ': '.mb_substr($body, 0, 160)), $s, ['message_id' => $id]);
            }
            $markStage('projection_build_ms');
            $response = $this->publicMessage($r, $uid);
            $this->publish($u);
            $markStage('firebase_update_and_queue_ms');

            return $response;
        };

        // Invite rooms can be ended or expired concurrently, so keep their
        // room lifecycle lock. Global and title rooms stay open and can safely
        // process different sends in parallel.
        $persistentRoom = $room === 'global' || preg_match('/^(movie|episode)-[0-9]+$/D', $room) === 1;

        $response = $persistentRoom
            ? $this->lockedSend($room, $actor['uid'], $p['request_id'], $work)
            : $this->locked($room, $work);

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($totalMs >= 1000) {
            $workMs = $workStartedAt === null ? 0 : (int) round((microtime(true) - $workStartedAt) * 1000);
            \Illuminate\Support\Facades\Log::warning('Slow chat message send', [
                'room_id' => $room,
                'total_ms' => $totalMs,
                'lock_wait_ms' => max(0, $totalMs - $workMs),
                'stages' => $stageDurations,
            ]);
        }

        return $response;
    }

    public function history(string $room, string $uid, ?int $before, ?int $after, bool $initial = false, int $limit = 30): array
    {
        $this->room($room, $uid);
        $limit = max(1, min(30, $limit));
        $firstUnread = $initial ? $this->firstUnread($room, $uid) : null;
        $initialAfter = null;
        if ($initial && $before === null && $after === null && $firstUnread !== null) {
            $after = max(0, (int) $firstUnread - 1);
            $initialAfter = (string) $after;
        }
        // Read canonical history so a newly admitted member can see older messages.
        $values = [];
        $cursor = $after;
        $end = $before;
        do {
            $page = $this->store->query("rooms/$room/messages", 'cursor', $cursor !== null ? $cursor + 1 : null, $end !== null ? $end - 1 : null, $limit + 1, $after !== null);
            if (! $page) {
                break;
            }
            uasort($page, fn ($a, $b) => $a['cursor'] <=> $b['cursor']);
            foreach ($page as $r) {
                if (! ($r['deleted'] ?? false)) {
                    $values[$r['id']] = $r;
                }
            }
            if ($after !== null) {
                $cursor = (int) end($page)['cursor'];
            } else {
                $end = (int) reset($page)['cursor'];
            }
        } while (count($values) <= $limit && count($page) === $limit + 1);
        uasort($values, fn ($a, $b) => $a['cursor'] <=> $b['cursor']);
        $more = count($values) > $limit;
        $values = array_slice(array_values($values), $after !== null ? 0 : -$limit, $limit);

        return ['messages' => array_map(fn ($r) => $this->publicMessage($r, $uid), $values), 'has_more' => $more,
            'first_unread_id' => $firstUnread, 'initial_after' => $initialAfter,
            'last_message_id' => (string) ($this->store->get("rooms/$room/last_message_id") ?? '0')];
    }

    private function firstUnread(string $room, string $uid): ?string
    {
        $after = (int) ($this->store->get("reads/$uid/$room") ?? 0);
        do {
            $page = $this->store->query("rooms/$room/messages", 'cursor', $after + 1, null, 31, true);
            uasort($page, fn ($a, $b) => $a['cursor'] <=> $b['cursor']);
            foreach ($page as $r) {
                if ($r['sender_id'] !== $uid && ! ($r['deleted'] ?? false)) {
                    return (string) $r['id'];
                }
                $after = (int) $r['cursor'];
            }
        } while (count($page) === 31);

        return null;
    }

    public function sync(string $room, string $uid, int $cursor): array
    {
        $this->room($room, $uid);
        $items = $this->store->query("user_sync_events/$uid/$room", '$key', $cursor + 1, null, 101, true);
        ksort($items);
        $more = count($items) > 100;
        $events = [];
        foreach (array_slice($items, 0, 100) as $item) {
            $cursor = max($cursor, $item['cursor']);
            if (in_array($item['kind'], ['created', 'deleted'])) {
                $record = $this->store->get("user_messages/$uid/$room/".$item['message_id']);
                if (! $record) {
                    continue;
                }
                $item['message'] = $this->publicMessage($record, $uid);
            }
            $events[] = $item;
        }

        return ['cursor_expired' => false, 'next_cursor' => $cursor, 'has_more' => $more, 'events' => $events];
    }

    public function badge(string $room, string $uid, int $snapshot = 0): array
    {
        $read = (int) ($this->store->get("reads/$uid/$room") ?? 0);
        $unread = array_filter($this->store->query("rooms/$room/messages", 'cursor', $read + 1),
            fn ($r) => $r['sender_id'] !== $uid && ! ($r['deleted'] ?? false));
        uasort($unread, fn ($a, $b) => $a['cursor'] <=> $b['cursor']);
        $s = $this->room($room, $uid);

        return ['room_id' => $room, 'key' => $s['room_type'] === 'global' ? 'global' : ($s['room_type'] === 'invite' ? 'invite:'.$room : $s['room_type'].':'.$s['content_id']),
            'kind' => 'read', 'snapshot_cursor' => (string) $snapshot, 'read_through' => (string) $read,
            'unread_count' => count($unread), 'last_read_message_id' => (string) $read,
            'last_message_id' => (string) ($this->store->get("rooms/$room/last_message_id") ?? '0'),
            'first_unread_id' => $unread ? (string) reset($unread)['id'] : null];
    }

    public function unread(string $uid): array
    {
        // Capture the account cursor before reading room snapshots. Events that
        // arrive during the reads must still invalidate this summary on attach.
        $events = $this->store->query("user_account_events/$uid", '$key', null, null, 1);
        $snapshot = $events ? (int) array_key_last($events) : 0;
        $rooms = [];
        foreach (array_keys($this->store->get("user_rooms/$uid") ?? []) as $id) {
            try {
                $rooms[] = $this->badge((string) $id, $uid, $snapshot);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404, 410])) {
                    throw $e;
                }
            }
        }

        return ['rooms' => $rooms, 'total_unread' => array_sum(array_column($rooms, 'unread_count')), 'user_id' => $uid, 'is_admin' => $this->user($uid)['is_admin'],
            'channels' => [], 'websocket_url' => '', 'firebase_realtime' => true, 'event_cursor' => (string) $snapshot];
    }

    public function read(string $room, string $uid, string $id): array
    {
        return $this->locked($room, function () use ($room, $uid, $id) {
            $this->room($room, $uid);
            $this->message($room, $id);
            $read = max((int) $id, (int) $this->store->get("reads/$uid/$room"));
            $u = ["reads/$uid/$room" => $read];
            $cursor = $this->store->nextCursor();
            $this->accountEvent($u, $uid, $cursor);
            $this->store->update($u);

            return ['room_id' => $room, 'last_read_message_id' => (string) $read, 'badge' => $this->badge($room, $uid, $cursor)];
        });
    }

    public function react(array $actor, string $room, string $id, string $emoji, bool $active): array
    {
        return $this->locked($room, function () use ($actor, $room, $id, $emoji, $active) {
            $uid = $actor['uid'];
            $roomState = $this->room($room, $uid, true);
            $r = $this->message($room, $id);
            abort_if($r['deleted'], 422);
            $key = rtrim(strtr(base64_encode($emoji), '+/', '-_'), '=');
            if ((bool) ($r['reaction_users'][$key][$uid] ?? false) === $active) {
                return $this->publicMessage($r, $uid);
            }
            if ($active) {
                $r['reaction_users'][$key][$uid] = true;
            } else {
                unset($r['reaction_users'][$key][$uid]);
            }
            $cursor = $this->store->nextCursor();
            $u = ["rooms/$room/messages/$id" => $r, "rooms/$room/state/sync_cursor" => $cursor];
            $this->event($u, $room, $r, $cursor, 'reactions');
            if ($active) {
                $this->push($u, $r['sender_id'], $uid, $room, $cursor, 'reaction', $actor['name']." reacted $emoji to your message.", $roomState, ['message_id' => $id]);
            }
            $this->publish($u);

            return $this->publicMessage($r, $uid);
        });
    }

    public function pins(string $room, string $uid): array
    {
        $this->room($room, $uid);
        $pins = [];
        foreach ($this->store->get("rooms/$room/pins") ?? [] as $id => $active) {
            $r = $this->message($room, (string) $id);
            if (! $r['deleted']) {
                $pins[] = $this->publicMessage($r, $uid);
            }
        }

        return ['pins' => $pins, 'is_admin' => $this->user($uid)['is_admin']];
    }

    public function pin(array $actor, string $room, string $id, bool $active): array
    {
        return $this->locked($room, function () use ($actor, $room, $id, $active) {
            $this->room($room, $actor['uid'], true);
            abort_unless($actor['is_admin'], 403, 'Administrator access is required.');
            $r = $this->message($room, $id);
            abort_if($r['deleted'], 422);
            $cursor = $this->store->nextCursor();
            $u = ["rooms/$room/pins/$id" => $active ?: null];
            $this->event($u, $room, $r, $cursor, 'pins');
            $this->store->update($u);

            return ['pinned' => $active];
        });
    }

    public function movie(string $id, bool $kids = false, bool $ageRestriction = false): array
    {
        $q = DB::table('movie')->where('isEnable', true)->where('status', 'Published')->where(fn ($q) => $q->where('id', $id)->orWhere('num', $id));
        if ($kids) {
            $q->where('isChildMode', true);
        }
        if (! $ageRestriction) {
            $q->where('isAgeRestricted', false);
        }
        $r = $q->first(['id', 'title', 'poster']);
        abort_unless($r, 422, 'This movie is unavailable.');

        return ['id' => (string) $r->id, 'title' => $r->title, 'poster' => $r->poster];
    }

    public function createRoom(array $actor, array $p): array
    {
        $room = (string) $this->store->nextCursor();

        return $this->locked($room, function () use ($actor, $p, $room) {
            $uid = $actor['uid'];
            $state = ['active' => true, 'can_send' => true, 'room_type' => 'invite',
                'room_name' => trim($p['room_name']), 'creator_id' => $uid,
                'created_at' => now()->valueOf(), 'expires_at' => now()->addHour()->valueOf(), 'sync_cursor' => 0];
            abort_if($state['room_name'] === '', 422, 'Enter a room name.');
            $u = ["rooms/$room/state" => $state, "rooms/$room/members/$uid" => $this->membership($actor), "user_rooms/$uid/$room" => true, "private_expiries/$room" => $state['expires_at']];
            $this->accountEvent($u, $uid, $this->store->nextCursor(), 'room_state_invalidated');
            // Validate invitees before creating any room.
            foreach ($p['invitee_ids'] as $other) {
                $this->user($other);
            }
            $this->store->update($u);
            $this->invitePeople($actor, $room, $p['invitee_ids'], false);

            return $this->descriptor($room, $uid);
        });
    }

    public function invitePeople(array $actor, string $room, array $ids, bool $lock = true): array
    {
        $work = function () use ($actor, $room, $ids) {
            $uid = $actor['uid'];
            $s = $this->room($room, $uid);
            abort_unless(($s['creator_id'] ?? '') === $uid && empty($s['content_type']), 403, 'Only the room creator can invite people.');
            $count = 0;
            $u = [];
            foreach (array_unique($ids) as $other) {
                $this->user($other);
                if ($other === $uid || ($this->store->get("rooms/$room/members/$other/active") ?? false)) {
                    continue;
                }
                $existing = $this->store->get("room_invitees/$room/$other");
                if ($existing && ($this->store->get("invites/$existing/status") ?? '') === 'pending') {
                    continue;
                }
                $id = (string) $this->store->nextCursor();
                $token = bin2hex(random_bytes(32));
                $invite = ['invite_id' => $id, 'room_id' => $room, 'room_name' => $s['room_name'],
                    'recipient' => $other, 'inviter_id' => $uid, 'inviter_name' => $actor['name'], 'status' => 'pending',
                    'invitation_token' => $token, 'created_at' => now()->valueOf(), 'watch_position_ms' => 0];
                $u["invites/$id"] = $invite;
                $u["invitation_links/$token"] = $id;
                $u["user_invites/$other/$id"] = true;
                $u["room_invitees/$room/$other"] = $id;
                $this->accountEvent($u, $other, (int) $id, 'room_state_invalidated');
                $this->push($u, $other, $uid, $room, (int) $id, 'invite', $actor['name'].' invited you to '.$s['room_name'], $s, ['invitation_token' => $token, 'invite_id' => $id]);
                $count++;
            }
            if ($u) {
                $this->publish($u);
            }

            return ['room_id' => $room, 'invite_count' => $count];
        };

        return $lock ? $this->locked($room, $work) : $work();
    }

    public function privateRooms(string $uid, ?string $recipient): array
    {
        $result = [];
        foreach (array_keys($this->store->get("user_rooms/$uid") ?? []) as $id) {
            try {
                $s = $this->room((string) $id, $uid);
                if ($s['room_type'] !== 'invite') {
                    continue;
                }
                $r = $this->descriptor((string) $id, $uid);
                if ($recipient) {
                    $r['recipient_joined'] = (bool) $this->store->get("rooms/$id/members/$recipient/active");
                }
                $result[] = $r;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404, 410])) {
                    throw $e;
                }
            }
        }

        return ['rooms' => $result];
    }

    public function invitations(string $uid): array
    {
        $result = [];
        foreach (array_keys($this->store->get("user_invites/$uid") ?? []) as $id) {
            $r = $this->store->get("invites/$id");
            if (! $r || $r['status'] !== 'pending') {
                continue;
            }
            try {
                $this->room($r['room_id']);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (in_array($e->getStatusCode(), [404, 410])) {
                    continue;
                } throw $e;
            }
            unset($r['recipient'], $r['inviter_id'], $r['invitation_token']);
            $result[] = $r;
        }

        return ['invites' => $result];
    }

    public function invitation(string $uid, string $id, ?bool $accept = null, bool $token = false): array
    {
        if ($token) {
            $id = (string) ($this->store->get("invitation_links/$id") ?? '');
        }
        abort_unless(ctype_digit($id), 404, 'This invitation is invalid.');
        $r = $this->store->get("invites/$id");
        abort_unless($r && $r['recipient'] === $uid, 403, 'Sign in with the invited Zo Stream account.');

        return $this->locked($r['room_id'], function () use ($uid, $id, $accept) {
            $r = $this->store->get("invites/$id");
            $room = $r['room_id'];
            $s = $this->room($room);
            abort_if($r['status'] === 'declined', 410, 'This invitation was declined.');
            if ($accept !== null && $r['status'] === 'pending') {
                $u = ["invites/$id/status" => $accept ? 'accepted' : 'declined'];
                if ($accept) {
                    $u["rooms/$room/members/$uid"] = $this->membership($this->user($uid));
                    $u["user_rooms/$uid/$room"] = true;
                }
                $cursor = $this->store->nextCursor();
                $this->accountEvent($u, $uid, $cursor, 'room_state_invalidated');
                $this->store->update($u);
                $r['status'] = $accept ? 'accepted' : 'declined';
            }

            return ['room_id' => $room, 'room_name' => $s['room_name'], 'content_type' => null, 'content_id' => null,
                'status' => $r['status'], 'accepted' => $r['status'] === 'accepted', 'inviter_name' => $r['inviter_name']];
        });
    }

    public function claimInvitation(array $actor, string $room, string $message): array
    {
        $uid = $actor['uid'];
        $this->room($room, $uid);
        $record = $this->message($room, $message);
        abort_if($record['deleted'], 404);
        $target = $record['invitation']['room_id'] ?? null;
        abort_unless($target, 404);

        return $this->locked($target, function () use ($uid, $target) {
            $s = $this->room($target);
            if ($this->store->get("rooms/$target/members/$uid/active")) {
                return ['room_id' => $target, 'accepted' => true];
            }
            $host = $this->user($s['creator_id']);
            $this->invitePeople($host, $target, [$uid], false);

            return ['room_id' => $target, 'accepted' => false];
        });
    }

    public function endRoom(array $actor, string $room): array
    {
        return $this->locked($room, function () use ($actor, $room) {
            $s = $this->room($room, $actor['uid']);
            abort_unless(($s['creator_id'] ?? '') === $actor['uid'] && empty($s['content_type']), 403);
            $u = ["rooms/$room/state/active" => false, "rooms/$room/state/can_send" => false, "room_typing/$room" => null];
            $cursor = $this->store->nextCursor();
            foreach ($this->members($room) as $uid => $m) {
                $this->accountEvent($u, (string) $uid, $cursor, 'room_state_invalidated');
            }
            $this->store->update($u);

            return ['ended' => true];
        });
    }
}
