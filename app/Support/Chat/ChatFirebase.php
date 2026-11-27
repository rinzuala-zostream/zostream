<?php

namespace App\Support\Chat;

use App\Models\SessionTokenModel;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Factory;

final class ChatFirebase
{
    private ?Auth $auth = null;
    private ?Database $database = null;

    public static function uid(string $zoStreamUserId): string
    {
        $key = (string) config('chat.firebase.uid_key');
        if ($key === '') {
            throw new \LogicException('CHAT_FIREBASE_UID_KEY must be configured before issuing Firebase chat tokens.');
        }

        return 'zs_'.hash_hmac('sha256', $zoStreamUserId, $key);
    }

    /**
     * Issue a short rules-visible session lease and Firebase Custom Token.
     * Room authorization remains a separate trusted projection operation.
     */
    public function issueToken(SessionTokenModel $session): array
    {
        abort_unless(config('chat.firebase.enabled'), 503, 'Firebase chat authentication is not enabled.');

        $userId = (string) $session->user_id;
        $sessionId = (string) $session->getKey();
        abort_if($userId === '' || $sessionId === '', 401, 'The chat session is invalid.');
        abort_if(! $session->access_expires_at || $session->access_expires_at->isPast(), 401, 'The Zo Stream session has expired.');

        $firebaseUid = self::uid($userId);
        $leaseSeconds = min(300, max(60, (int) config('chat.firebase.session_lease_seconds', 300)));
        $expiresAt = min(now()->addSeconds($leaseSeconds)->getTimestamp(), $session->access_expires_at->getTimestamp());
        $reference = $this->database()->getReference("chat_v1/auth_sessions/$firebaseUid/$sessionId");
        $previous = $reference->getValue();
        $revision = is_array($previous) ? (int) ($previous['revision'] ?? 0) + 1 : 1;
        $clientAccess = config('chat.firebase.client_access_enabled') && is_array($previous) && ($previous['active'] ?? false) === true
            && ($previous['client_access'] ?? false) === true && (int) ($previous['expires_at'] ?? 0) > now()->getTimestampMs();

        $reference->set([
            'active' => true,
            'client_access' => $clientAccess,
            'account_events_access' => ChatChannels::firebaseAccountEventsAvailable(),
            'expires_at' => $expiresAt * 1000,
            'zo_session_id' => $sessionId,
            'revision' => $revision,
        ]);

        $customToken = $this->auth()->createCustomToken($firebaseUid, ['chat_sid' => $sessionId], 3600);

        return [
            'firebase_uid' => $firebaseUid,
            'custom_token' => $customToken->toString(),
            'session_expires_at' => now()->setTimestamp($expiresAt)->toIso8601String(),
            'refresh_after_seconds' => min(180, max(1, $expiresAt - now()->getTimestamp() - 30)),
        ];
    }

    /** Renew rules-visible leases for an already authenticated Firebase session without minting a token. */
    public function renewSession(SessionTokenModel $session): int
    {
        abort_unless(config('chat.firebase.enabled'), 503, 'Firebase chat authentication is not enabled.');
        abort_unless($session->access_expires_at?->isFuture(), 401, 'The Zo Stream session has expired.');
        $uid = self::uid((string) $session->user_id);
        $sessionId = (string) $session->getKey();
        $reference = $this->database()->getReference("chat_v1/auth_sessions/$uid/$sessionId");
        $previous = $reference->getValue();
        abort_unless(is_array($previous) && ($previous['active'] ?? false) === true, 401, 'Firebase session is revoked.');
        $expiresAt = min(now()->addSeconds(min(300, max(60, (int) config('chat.firebase.session_lease_seconds', 300))))->getTimestamp(),
            $session->access_expires_at->getTimestamp());
        $reference->set(array_merge($previous, [
            'expires_at' => $expiresAt * 1000,
            'client_access' => config('chat.firebase.client_access_enabled') && ($previous['client_access'] ?? false) === true,
            'account_events_access' => ChatChannels::firebaseAccountEventsAvailable(),
            'revision' => (int) ($previous['revision'] ?? 0) + 1,
        ]));

        return $expiresAt;
    }

    /**
     * Disable a Firebase session on logout. Rules also enforce the short lease,
     * so an RTDB outage bounds any revocation delay to at most five minutes.
     */
    public function revokeSession(string $zoStreamUserId, string|int $sessionId): void
    {
        if (! config('chat.firebase.enabled') || trim($zoStreamUserId) === '' || (string) $sessionId === '') {
            return;
        }

        $firebaseUid = self::uid($zoStreamUserId);
        $reference = $this->database()->getReference("chat_v1/auth_sessions/$firebaseUid/$sessionId");
        $previous = $reference->getValue();
        $this->database()->getReference('chat_v1')->update([
            "auth_sessions/$firebaseUid/$sessionId" => array_merge(is_array($previous) ? $previous : [], [
                'active' => false,
                'client_access' => false,
                'account_events_access' => false,
                'expires_at' => now()->getTimestamp() * 1000,
                'zo_session_id' => (string) $sessionId,
                'revision' => (int) (is_array($previous) ? ($previous['revision'] ?? 0) : 0) + 1,
            ]),
            "user_typing/$firebaseUid" => null,
        ]);
    }

    /** Persist a short room lease derived only after the SQL policy allowed it. */
    public function projectRoomAuthorization(string $zoStreamUserId, string|int $sessionId, object $room, bool $canWrite, int $expiresAt, ?string $playbackHash = null): void
    {
        if (! config('chat.firebase.enabled')) { return; }
        $uid = self::uid($zoStreamUserId);
        $sessionReference = $this->database()->getReference("chat_v1/auth_sessions/$uid/$sessionId");
        $session = $sessionReference->getValue();
        abort_unless(is_array($session) && ($session['active'] ?? false) === true
            && (int) ($session['expires_at'] ?? 0) > now()->getTimestampMs(), 401, 'Firebase session lease is missing or expired.');
        $clientAccess = (bool) config('chat.firebase.client_access_enabled');
        $roomId = (int) $room->id;
        $reference = $this->database()->getReference("chat_v1/room_authz/$roomId/$uid/$sessionId");
        $previous = $reference->getValue();
        $authorization = [
            'can_read' => true,
            'can_write' => $canWrite,
            'read_until' => $expiresAt * 1000,
            'write_until' => $canWrite ? $expiresAt * 1000 : 0,
            'playback_hash' => $playbackHash,
            'revision' => (int) (is_array($previous) ? ($previous['revision'] ?? 0) : 0) + 1,
        ];
        $updates = [
            "room_authz/$roomId/$uid/$sessionId" => $authorization,
            "auth_sessions/$uid/$sessionId/client_access" => $clientAccess,
            "user_rooms/$uid/$roomId" => [
                'room_id' => (string) $roomId,
                'room_type' => (string) $room->type,
                'content_id' => $room->content_id === null ? null : (string) $room->content_id,
                'expires_at' => $expiresAt * 1000,
            ],
        ];
        // A block disables the account gate and clears its feed. When a later
        // authorized join restores that gate, clear again atomically so a
        // racing delivery worker cannot resurrect pre-block cached content.
        $priorSessionCanRead = false;
        if ($clientAccess && ($session['client_access'] ?? false) !== true) {
            $knownSessions = $this->database()->getReference("chat_v1/auth_sessions/$uid")->getValue();
            foreach (is_array($knownSessions) ? $knownSessions : [] as $knownSessionId => $knownSession) {
                if ((string) $knownSessionId !== (string) $sessionId && is_array($knownSession)
                    && ($knownSession['active'] ?? false) === true && ($knownSession['client_access'] ?? false) === true
                    && (int) ($knownSession['expires_at'] ?? 0) > now()->getTimestampMs()) { $priorSessionCanRead = true; break; }
            }
        }
        if ($clientAccess && ($session['client_access'] ?? false) !== true && ! $priorSessionCanRead) {
            $updates["user_messages/$uid"] = null;
            $updates["user_sync_events/$uid"] = null;
            $updates["user_typing/$uid"] = null;
        }
        $this->database()->getReference('chat_v1')->update($updates);
    }

    /** A new block disables RTDB reads before clearing the server-side feed cache. */
    public function blockUserFeed(string $zoStreamUserId): void
    {
        if (! config('chat.firebase.enabled') || trim($zoStreamUserId) === '') { return; }
        $uid = self::uid($zoStreamUserId);
        $sessions = $this->database()->getReference("chat_v1/auth_sessions/$uid")->getValue();
        $updates = ["user_messages/$uid" => null, "user_sync_events/$uid" => null, "user_typing/$uid" => null];
        foreach (is_array($sessions) ? $sessions : [] as $sessionId => $session) {
            $updates["auth_sessions/$uid/$sessionId/client_access"] = false;
        }
        $this->database()->getReference('chat_v1')->update($updates);
        if (ChatDatabase::schema()->hasTable('chat_firebase_typing_records')) {
            do {
                $rows = ChatDatabase::connection()->table('chat_firebase_typing_records')
                    ->where(fn ($query) => $query->where('viewer_uid', $uid)->orWhere('typist_uid', $uid))
                    ->limit(500)->get();
                if ($rows->isEmpty()) { break; }
                $this->clearTypingRows($rows);
            } while (true);
        }
    }

    /** Apply one idempotent multi-location write containing each feed row and its cursor event. */
    public function publishUserFeedBatch(array $updates): void
    {
        if (! config('chat.firebase.enabled') || $updates === []) { return; }
        $this->database()->getReference('chat_v1')->update($updates);
    }

    /** Publish stable outbox-keyed account invalidations; duplicate delivery is an idempotent set. */
    public function publishAccountEvents(array $updates): void
    {
        if (! config('chat.firebase.enabled') || $updates === []) { return; }
        $this->database()->getReference('chat_v1')->update($updates);
    }

    public function deleteAccountEvents(array $rows): void
    {
        if ($rows === []) { return; }
        $updates = [];
        foreach ($rows as $row) {
            $key = str_pad((string) $row->outbox_id, 20, '0', STR_PAD_LEFT);
            $updates["user_account_events/$row->firebase_uid/$key"] = null;
        }
        foreach (array_chunk($updates, 200, true) as $batch) {
            $this->database()->getReference('chat_v1')->update($batch);
        }
    }

    /** Publish a short-lived, block-filtered typing record to authorized viewers only. */
    public function publishTyping(int $roomId, string $typistUserId, ?array $typing): void
    {
        if (! config('chat.firebase.enabled') || ! config('chat.firebase.client_access_enabled')) { return; }
        $room = ChatDatabase::connection()->table('chat_rooms')->find($roomId);
        if (! $room || $room->closed) { return; }
        $authorizations = $this->roomAuthorizations($roomId);
        $sessionIds = [];
        foreach ($authorizations as $grants) {
            foreach (is_array($grants) ? $grants : [] as $sessionId => $grant) {
                if (is_array($grant) && ($grant['can_read'] ?? false) === true
                    && (int) ($grant['read_until'] ?? 0) > now()->getTimestampMs()) { $sessionIds[] = (string) $sessionId; }
            }
        }
        if ($sessionIds === []) { return; }
        $sessionRows = collect();
        foreach (array_chunk(array_values(array_unique($sessionIds)), 500) as $chunk) {
            $sessionRows = $sessionRows->merge(\Illuminate\Support\Facades\DB::table('session_tokens')->whereIn('id', $chunk)
                ->where('access_expires_at', '>', now())->get());
        }
        $sessionRows = $sessionRows->keyBy('id');
        $viewers = [];
        foreach ($authorizations as $firebaseUid => $grants) {
            foreach (is_array($grants) ? $grants : [] as $sessionId => $grant) {
                $session = $sessionRows->get($sessionId);
                if (! $session || (string) $session->user_id === $typistUserId
                    || self::uid((string) $session->user_id) !== $firebaseUid || ! is_array($grant)
                    || ($grant['can_read'] ?? false) !== true || (int) ($grant['read_until'] ?? 0) <= now()->getTimestampMs()) { continue; }
                $viewers[] = ['user_id' => (string) $session->user_id, 'device_id' => (string) $session->device_id,
                    'session_id' => (string) $session->id, 'session_hash' => hash('sha256', $session->access_token),
                    'playback_hash' => (string) ($grant['playback_hash'] ?? ''), 'feed_channel' => 'firebase-user-feed'];
            }
        }
        $eligible = iterator_to_array(app(ChatLiveDelivery::class)->eligibleViewers($room, $viewers, $sessionRows), false);
        if ($eligible === []) { return; }

        $blockedViewers = [];
        foreach (ChatDatabase::connection()->table('chat_blocks')->where('user_id', $typistUserId)
            ->orWhere('blocked_user_id', $typistUserId)->get(['user_id', 'blocked_user_id']) as $edge) {
            $blockedViewers[(string) ($edge->user_id === $typistUserId ? $edge->blocked_user_id : $edge->user_id)] = true;
        }
        $updates = [];
        $typistUid = self::uid($typistUserId);
        foreach ($eligible as $viewer) {
            if (isset($blockedViewers[$viewer['user_id']])) { continue; }
            $viewerUid = self::uid($viewer['user_id']);
            $path = "user_typing/$viewerUid/$roomId/$typistUid";
            $updates[$path] = $typing === null ? null : [
                'typist_uid' => $typistUid,
                'name' => ($typing['is_admin'] ?? false) ? 'Zo Stream' : mb_substr((string) ($typing['name'] ?? 'Viewer'), 0, 100),
                'is_admin' => (bool) ($typing['is_admin'] ?? false),
                'expires_at' => min((int) ($typing['expires_at'] ?? now()->addSeconds(6)->getTimestamp()) * 1000,
                    now()->addSeconds(30)->getTimestampMs()),
            ];
        }
        foreach (array_chunk($updates, 200, true) as $batch) {
            $this->database()->getReference('chat_v1')->update($batch);
        }
        if (ChatDatabase::schema()->hasTable('chat_firebase_typing_records')) {
            if ($typing === null) {
                ChatDatabase::connection()->table('chat_firebase_typing_records')->where('room_id', $roomId)
                    ->where('typist_uid', $typistUid)->delete();
            } else {
                $expiry = now()->setTimestamp((int) ($typing['expires_at'] ?? now()->addSeconds(6)->getTimestamp()));
                $rows = [];
                foreach (array_keys($updates) as $path) {
                    if (preg_match('#^user_typing/([^/]+)/([0-9]+)/#', $path, $parts)) {
                        $rows[] = ['viewer_uid' => $parts[1], 'room_id' => (int) $parts[2], 'typist_uid' => $typistUid,
                            'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                if ($rows !== []) {
                    ChatDatabase::connection()->table('chat_firebase_typing_records')->upsert($rows,
                        ['viewer_uid', 'room_id', 'typist_uid'], ['expires_at', 'updated_at']);
                }
            }
        }
    }

    /** Delete expired per-user copies after SQL retention, independent of access flag. */
    public function deleteUserFeedMessages(array $records): void
    {
        if ($records === []) { return; }
        $updates = [];
        foreach ($records as $record) {
            $uid = (string) $record->firebase_uid;
            $room = (int) $record->room_id;
            $messageKey = str_pad((string) $record->message_id, 20, '0', STR_PAD_LEFT);
            $updates["user_messages/$uid/$room/$messageKey"] = null;
        }
        $this->database()->getReference('chat_v1')->update($updates);
    }

    public function deleteUserFeedEvents(array $records): void
    {
        if ($records === []) { return; }
        $updates = [];
        foreach ($records as $record) {
            $uid = (string) $record->firebase_uid;
            $room = (int) $record->room_id;
            $cursor = str_pad((string) $record->cursor, 20, '0', STR_PAD_LEFT);
            $updates["user_sync_events/$uid/$room/$cursor"] = null;
        }
        $this->database()->getReference('chat_v1')->update($updates);
    }

    public function roomAuthorizations(int $roomId): array
    {
        if (! config('chat.firebase.enabled')) { return []; }
        $value = $this->database()->getReference("chat_v1/room_authz/$roomId")->getValue();

        return is_array($value) ? $value : [];
    }

    /** Revoke one room's lease immediately; an omitted user revokes all room leases. */
    public function revokeRoomAuthorization(int $roomId, ?string $zoStreamUserId = null, string|int|null $sessionId = null): void
    {
        if (! config('chat.firebase.enabled')) { return; }
        $room = $this->database()->getReference("chat_v1/room_authz/$roomId")->getValue();
        if (! is_array($room)) { return; }
        $onlyUid = $zoStreamUserId === null ? null : self::uid($zoStreamUserId);
        $now = now()->getTimestamp() * 1000;
        $updates = [];
        $clearTypingUsers = [];
        foreach ($room as $uid => $sessions) {
            if ($onlyUid !== null && $uid !== $onlyUid) { continue; }
            $uidHasAnotherActiveGrant = false;
            $sessionRecords = $sessionId === null ? [] : $this->database()->getReference("chat_v1/auth_sessions/$uid")->getValue();
            foreach (is_array($sessions) ? $sessions : [] as $sessionKey => $authorization) {
                if ($sessionId !== null && (string) $sessionKey !== (string) $sessionId) {
                    $sessionRows = is_array($sessionRecords) ? ($sessionRecords[$sessionKey] ?? null) : null;
                    if (is_array($authorization) && ($authorization['can_read'] ?? false) === true
                        && (int) ($authorization['read_until'] ?? 0) > $now && is_array($sessionRows)
                        && ($sessionRows['active'] ?? false) === true && (int) ($sessionRows['expires_at'] ?? 0) > $now) {
                        $uidHasAnotherActiveGrant = true;
                    }
                    continue;
                }
                $updates["room_authz/$roomId/$uid/$sessionKey"] = array_merge(is_array($authorization) ? $authorization : [], [
                    'can_read' => false,
                    'can_write' => false,
                    'read_until' => $now,
                    'write_until' => $now,
                    'revision' => (int) (is_array($authorization) ? ($authorization['revision'] ?? 0) : 0) + 1,
                ]);
            }
            if ($sessionId === null || ! $uidHasAnotherActiveGrant) {
                $updates["user_rooms/$uid/$roomId"] = null;
                $updates["user_typing/$uid/$roomId"] = null;
                $clearTypingUsers[] = $uid;
            }
        }
        $this->publishUserFeedBatch($updates);
        if (ChatDatabase::schema()->hasTable('chat_firebase_typing_records')) {
            if ($clearTypingUsers !== []) {
                do {
                    $typingRows = ChatDatabase::connection()->table('chat_firebase_typing_records')->where('room_id', $roomId)
                        ->where(function ($query) use ($clearTypingUsers): void {
                            foreach ($clearTypingUsers as $uid) {
                                $query->orWhere('viewer_uid', $uid)->orWhere('typist_uid', $uid);
                            }
                        })->limit(500)->get();
                    if ($typingRows->isEmpty()) { break; }
                    $this->clearTypingRows($typingRows);
                } while (true);
            }
        }
    }

    /** Remove ephemeral typing nodes after their expiry; no message/event history is written. */
    public function pruneExpiredTypingRecords(): void
    {
        if (! config('chat.firebase.enabled') || ! ChatDatabase::schema()->hasTable('chat_firebase_typing_records')) { return; }
        do {
            $rows = ChatDatabase::connection()->table('chat_firebase_typing_records')->where('expires_at', '<=', now())
                ->orderBy('expires_at')->limit(500)->get();
            if ($rows->isEmpty()) { break; }
            $this->clearTypingRows($rows);
        } while (true);
    }

    private function clearTypingRows(\Illuminate\Support\Collection $rows): void
    {
        $updates = [];
        foreach ($rows as $row) {
            $updates["user_typing/$row->viewer_uid/$row->room_id/$row->typist_uid"] = null;
        }
        foreach (array_chunk($updates, 200, true) as $batch) { $this->database()->getReference('chat_v1')->update($batch); }
        ChatDatabase::connection()->table('chat_firebase_typing_records')->where(function ($query) use ($rows): void {
            foreach ($rows as $row) {
                $query->orWhere(fn ($tuple) => $tuple->where('viewer_uid', $row->viewer_uid)->where('room_id', $row->room_id)
                    ->where('typist_uid', $row->typist_uid));
            }
        })->delete();
    }

    private function auth(): Auth
    {
        if ($this->auth === null) {
            $this->auth = $this->firebaseFactory()->createAuth();
        }

        return $this->auth;
    }

    private function database(): Database
    {
        if ($this->database === null) {
            $this->database = $this->emulatorDatabase() ?? $this->firebaseFactory()->createDatabase();
        }

        return $this->database;
    }

    /** Use the RTDB emulator's owner token only in explicitly enabled local tests. */
    private function emulatorDatabase(): ?Database
    {
        $host = getenv('FIREBASE_DATABASE_EMULATOR_HOST') ?: '';
        if (! app()->environment('testing') || ! config('chat.firebase.emulator_admin_access', false) || $host === '') { return null; }
        $parsed = parse_url('http://'.$host);
        if (! in_array($parsed['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || ! str_starts_with((string) config('firebase.database_url'), 'https://demo-')) {
            throw new \LogicException('Firebase emulator Admin access is restricted to loopback demo projects in tests.');
        }
        $url = \Kreait\Firebase\Database\UrlBuilder::create((string) config('firebase.database_url'));
        $handler = \GuzzleHttp\HandlerStack::create();
        $handler->push(\Kreait\Firebase\Http\Middleware::ensureJsonSuffix());
        $client = new \GuzzleHttp\Client(['handler' => $handler, 'headers' => ['Authorization' => 'Bearer owner']]);
        $api = new \Kreait\Firebase\Database\ApiClient($client, $url,
            new \Kreait\Firebase\Exception\DatabaseApiExceptionConverter(new \Kreait\Firebase\Http\ErrorResponseParser));
        $uri = new \GuzzleHttp\Psr7\Uri($url->getUrl('/'));

        return new \Kreait\Firebase\Database($uri, $api);
    }

    private function firebaseFactory(): Factory
    {
        $credentials = (string) config('firebase.credentials');
        $databaseUrl = (string) config('firebase.database_url');
        abort_unless($credentials !== '' && is_file($credentials) && $databaseUrl !== '', 503, 'Firebase chat is not configured.');

        return (new Factory)->withServiceAccount($credentials)->withDatabaseUri($databaseUrl);
    }
}
