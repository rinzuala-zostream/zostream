# Firebase Chat Contract v1

The checked-in contract is [chat-schema-v1.json](../Library/firebase/chat-schema-v1.json). It introduces a new `chat_v1` namespace; existing SQL room IDs and room configuration remain authoritative, and the new messages path starts empty. No SQL history is copied.

## Identity and authorization

The stable Firebase UID is `zs_` followed by the lowercase hex HMAC-SHA-256 of the exact Zo Stream user ID, using the dedicated `CHAT_FIREBASE_UID_KEY`. Back up this key and do not rotate it without an identity migration.

### Custom Token endpoint

`POST /api/v4/chat/firebase/token` requires the existing Zo Stream bearer session, `X-Client-Platform` (`android`, `ios`, `windows`, or `macos`), and optionally `active_room_id`. Include the current `X-Playback-Token` when refreshing a movie, episode, or watch room. The endpoint is limited to 30 requests per minute.

Success uses the standard v4 envelope:

```json
{
  "success": true,
  "data": {
    "firebase_uid": "zs_<hmac-sha256>",
    "custom_token": "<Firebase custom token>",
    "session_expires_at": "<ISO-8601>",
    "refresh_after_seconds": 180,
    "room_projection_refreshed": true
  },
  "message": null,
  "meta": { "api_version": "4" },
  "error": null
}
```

`room_projection_refreshed` is false when no active room is supplied or the existing SQL policy no longer authorizes it. The endpoint issues a token for one Zo Stream session; the custom claim `chat_sid` binds RTDB rules to that session. Logout, refresh-token expiry, administrator idle expiry, and account deletion attempt immediate session revocation. A session lease is at most five minutes.

`CHAT_FIREBASE_ENABLED` controls trusted token/projection/delivery operations and defaults to false. `CHAT_FIREBASE_CLIENT_ACCESS_ENABLED` is a separate, false-by-default final gate for client RTDB reads. Never enable it in production as part of this backend phase.

### Authorization projection

The projector runs after `POST /api/v4/chat/rooms/join`, `POST /api/v4/chat/invite-rooms/{room}/join`, or token refresh with `active_room_id`. It delegates the decision to the existing `ChatService` SQL checks. Records are keyed by room ID, HMAC UID, and Zo Stream session ID so playback on one device cannot authorize another device. Global-room leases are at most 240 seconds; movie, episode, and invitation-room leases are at most 90 seconds. All Firebase projection writes are Admin SDK only. Logout, room leave, room closure, room ban, account deletion, and a new block disable or remove the relevant grants/feed. Time limits bound stale access if a Firebase write fails.

The `user_rooms/{firebaseUid}` projection contains only the user's own joined-room metadata. Existing invite discovery remains SQL-authorized; do not infer that this cache is a complete invite list.

## Message submission and publication

Clients submit through the existing authenticated `POST /api/v4/chat/rooms/{room}/messages` Laravel API. There are no client writes to RTDB message, reaction, read, typing, authorization, or event paths. The API validates room policy, 1,000-character text limit, slow-mode, mute/ban, replies, movie suggestions, invitation cards, Watch Together controls, and the existing request UUID before SQL commit. A success response is the accepted SQL message. A validation, policy, or rate-limit 4xx means it was not accepted. A timeout, dropped connection, or ambiguous 5xx is an unknown outcome because SQL may have committed before the response or Firebase publication failed; keep the pending operation and retry with the exact same `request_id` so the SQL idempotency path returns the canonical result without duplicate messages or notifications.

For offline retries, persist the `request_id` UUID, account ID, room ID, exact body and structured fields before sending. Reuse that UUID for every retry. Laravel's SQL uniqueness/idempotency path returns the existing message for a duplicate request. Do not write a message directly to Firebase.

Example plain message submission:

```http
POST /api/v4/chat/rooms/17/messages
Authorization: Bearer <Zo Stream access token>
Content-Type: application/json
```

```json
{
  "request_id": "8ea973b1-3985-4227-8079-3cb81c152428",
  "body": "Hello",
  "reply_to_id": null,
  "spoiler": false
}
```

The 201 response is the normal v4 envelope with the canonical message under `data`, including its SQL `id`, `room_id`, `user_id`, `created_at`, `revision`, reply and structured-card fields. A retry with the same `request_id` returns that same canonical message. Existing request fields for suggestions (`movie_suggestion.id`), invitations (`room_invite.room_id`), moments, and `/watch` are still validated by Laravel; clients should continue to use the existing API contract for those fields.

The SQL transaction writes its outbox rows and increments a per-room sequence under a row lock. `chat:deliver` retries Firebase publication with bounded exponential backoff. The worker re-reads canonical SQL state and performs an idempotent multi-location RTDB update for each recipient batch: the user's message snapshot and matching sync event are committed together. A delayed RTDB publication does not undo SQL acceptance; the outbox retries it. Reactions, pin changes, read changes, and room lifecycle updates publish trusted sync events. FCM remains on the existing accepted-message SQL outbox and is not sent a second time by Firebase delivery.

The Firebase mirror includes text, replies by stable SQL ID, movie suggestion data, room invitation references, Watch Together invitation cards, moments, tombstones, revisions and pin state. It does not migrate old SQL history. SQL remains the authoritative complete history and operation API during this phase.

## Block filtering and data visibility

Rules deny room-wide message-list reads because RTDB rules cannot filter a list by each recipient's block list. Trusted delivery instead writes only to `user_messages/{firebaseUid}/{roomId}` after current SQL session, membership/playback, ban, invitation/subscription and block checks. A new block atomically disables that account's RTDB reads and clears its per-user feed/event branches; the next authorized room join can restore the session gate, while future fan-out continues to omit senders the recipient blocked. SQL history and sync endpoints apply the same server-side block filter. Client-side filtering remains useful for already-downloaded local cache, but it is not the access control.

This design duplicates recent message bytes per recently authorized recipient. It caps the mirror at 100 messages per user/room and prunes after seven days. At 400 messages/day, RTDB write volume is approximately `message bytes × eligible recipients`, plus one small event per recipient. The repository has no production audience/billing baseline, so USD 0–1/month cannot be verified; measure active room recipients, bytes, and Firebase billing during a controlled staging trial before any production gate change.

### Fan-out sizing model

The following is a reproducible payload simulation for a typical 200-character plain message serialized with the current schema: 629 bytes for the message and 89 bytes for its event (718-byte pair), excluding Firebase protocol/transport overhead, TLS, retries, and larger structured cards. It is a sizing model, not a live benchmark. Each recipient receives one atomic multi-location update containing that pair. For 400 messages/day:

| Eligible recipients/message | recipient pairs/day | Firebase changed paths/day | Ideal batched Admin calls/day (100 pairs/call) | logical RTDB write bytes/day | 7-day retained node payload with 100-message/500-event caps |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | 4,000 | 8,000 | 400 | 2.87 MB | 1.07 MB |
| 50 | 20,000 | 40,000 | 400 | 14.36 MB | 5.37 MB |
| 100 | 40,000 | 80,000 | 400 | 28.72 MB | 10.74 MB |
| 500 | 200,000 | 400,000 | 2,000 | 143.60 MB | 53.70 MB |
| 1,000 | 400,000 | 800,000 | 4,000 | 287.20 MB | 107.40 MB |

The retained-node column models distinct recipient-room feeds at the configured 100 message/500 event caps: `100 × 629 + 500 × 89 = 107,400` logical bytes per feed. It is a retained payload estimate, not seven times the daily write volume; RTDB metadata/indexing is excluded. Each accepted message makes `ceil(recipients/100)` ideal recipient-pair batches because pairs are atomic, even though each pair changes two database paths. A 1,000-recipient message therefore needs 10 Admin calls, and 400 such messages/day means 4,000 ideal calls/day (0.046/s average; bursts excluded), not 40,000. Actual billing/download, protocol overhead, retries, and cache/listener reads are not measured here. Firebase's published free tier and rates apply project-wide ([Firebase pricing](https://firebase.google.com/pricing), [RTDB billing details](https://firebase.google.com/docs/database/usage/billing)). SQL/query and transport benchmarks have not been run; the current log records recipient count, payload bytes, changed paths, Admin batch count and processing duration, while retries/prune duration still need dedicated instrumentation.

### Architecture choice

Direct client RTDB submission offers the least Laravel/SQL write load, but cannot safely enforce the existing slow-mode, ban/mute, room/playback/subscription, reply, moderation, structured-card, block and idempotency rules before visibility. A Cloud Function or separate processor would still need authoritative policy/storage and adds paid/operated infrastructure and cross-system consistency. Current SQL + transactional outbox + per-user RTDB mirror preserves those controls and FCM deduplication, but does not eliminate the SQL message write or API request, adds worker/SQL read load, and duplicates egress/storage proportional to recipients. A shared RTDB room feed is cheaper but violates server-side block visibility. Thus the current hybrid is the safest practical backend-only transition; it does not yet demonstrate the original VPS reduction or USD 0–1 target. Keep it as a staged compatibility layer, not a claim of workload reduction. Measure request/query/worker baselines before deciding whether a future trusted processor can safely change authority.

## History and incremental synchronization

- First history page: `GET /api/v4/chat/rooms/{room}/messages?initial=true&latest=true&limit=30`. Existing SQL filtering applies; the optional page limit is 1–100. On later openings, render the local database immediately and do not fetch this page again unless the local room has no history.
- Older pages use `before=<oldest SQL message id>`; deltas use `after=<last SQL message id>`. Keep SQL IDs as decimal strings in local storage and Firebase payloads. RTDB keys are 20-digit zero-padded SQL IDs for numeric ordering.
- Recent realtime message cache: query `chat_v1/user_messages/{firebaseUid}/{roomId}` by key, `limitToLast(30)` initially and at most 100. Room reads are bound to the authenticated `chat_sid`, the session lease, and the room projection. This cache is not a complete offline archive; use local storage and SQL reconciliation for older history.
- Realtime change stream: on first attach without a saved cursor, query `chat_v1/user_sync_events/{firebaseUid}/{roomId}` by key with `limitToLast(500)`, then call `/sync` from the server-provided cursor. On normal reconnect, query by key with `startAt(zeroPad(savedCursor + 1), limitToFirst(100))`; continue from the last key while catching up, and call `/sync` when a batch fills or a cursor gap is suspected. Security Rules cap this incremental query at 100 rows. Event IDs are 20-digit zero-padded per-room cursors. Cursors are monotonic and delivery is serialized per room. A user sees only events for which that user passed the SQL authorization and block filter.
- Bounded recovery: `GET /api/v4/chat/rooms/{room}/sync?cursor=<last cursor>` scans at most 100 outbox rows. Supply up to 100 local `{id,revision}` pairs. Normal responses return events, `next_cursor`, and `has_more`. If the cursor is older than retained outbox state, the response has `cursor_expired:true`, reconciles changed known messages and hidden IDs, returns the latest 30 visible messages and read state, and sets `reconcile_pins:true`. Then load pins through the existing bounded pins endpoint. Never download the whole room to recover.
- A normal sync response has `{ "cursor_expired": false, "events": [{"cursor": 41, "kind": "created", "message_id": "5001", "message": {"id": "5001", "revision": 1}}], "next_cursor": 41, "has_more": false }`. The event may omit `message` for room/read/invitation events. Treat `created`, `deleted`, `reactions`, and `sync` message payloads as snapshots; fetch canonical interactions from the SQL endpoint when needed.
- Every client stores the cursor per account and room, applies each `(room_id,cursor)` once, and commits message/event/cursor changes in one local database transaction. On a listener/history race, merge by SQL message ID and greatest revision.
- The first-history response includes `sync_cursor`, sampled from the per-room SQL cursor before the history query. Register Firebase listeners immediately after entering the room, then call sync from this cursor after local merge; this closes the history/listener race. Events with no user-visible change may create legitimate cursor gaps. Advance to the server's `next_cursor`, not `last_event_cursor + 1`.
- On cursor expiry, send the local message inventory in batches of at most 100 `{id,revision}` values, repeating the same expired cursor until every local row has been checked. This handles caches larger than 100 records. Apply `hidden_message_ids` as purge/tombstone decisions and changed snapshots by revision. If the room endpoint returns 403/404 after room switch or revocation, purge that room's server-derived cache for the active account; keep an explicitly marked local-only draft queue separate. A block response can invalidate cached senders without transferring their contents by reconciling each local ID batch.

### Session and room authorization renewal

- Initial Firebase sign-in only: `POST /api/v4/chat/firebase/token` with existing Zo Stream bearer auth, `X-Client-Platform: android|ios`, and optional `{ "active_room_id": 17 }`. Call it when there is no Firebase user or Firebase SDK sign-in needs a new Custom Token. Do not request a Custom Token to refresh room access.
- Lease request: `POST /api/v4/chat/firebase/lease` with `Authorization: Bearer <Zo Stream access token>`, `X-Client-Platform: android|ios`, `Content-Type: application/json`, and `{ "active_room_id": 17 }`. Success returns `{ "session_expires_at": "<ISO-8601>", "refresh_after_seconds": 180|60, "room_projection_refreshed": true }` inside the v4 `data` envelope. `false` means SQL no longer authorizes that room; detach its listeners and treat subsequent reads as revoked.
- Firebase Auth SDK refreshes its Firebase ID token automatically. The custom claim `chat_sid` remains fixed to the Zo Stream session.
- While a global room is foregrounded, call `POST /api/v4/chat/firebase/lease` at the returned `refresh_after_seconds` (normally 180); for movie, episode, and invitation rooms the response recommends 60 seconds because their room lease is capped at 90 seconds. Send the same Zo Stream bearer auth/platform header and `{ "active_room_id": 17 }`. It renews the rules-visible session lease and rechecks/renews the SQL room lease without minting a Custom Token. Route rate limit is 10/minute. Joining/switching rooms still uses the existing SQL join endpoint, which projects authorization; leaving uses its revoke path.
- The session rules lease is at most 300 seconds; a global room lease is at most 240 seconds and movie/episode/invite leases at most 90 seconds. The returned 180/60-second active-room renewal keeps leases ahead of expiry. For playback rooms, include the current `X-Playback-Token`; the backend rechecks the active stream and subscription. A failed renewal does not extend access. Retry with bounded exponential backoff while the old lease remains, then stop listeners at expiry and retain local cache until an authorized sync confirms visibility.
- On foreground, renew before attaching Firebase listeners if the last lease may be older than 180 seconds. On background, detach listeners; do not poll. On return, renew once and reattach only after success. On network failure, use local data, retry with backoff, and do not infer continued authorization from a cached lease. Room switching revokes the old room session grant and projects the new one. Revocation events and SQL session revocation are immediate when reachable; lease timestamps cap stale RTDB access.
- With `N` global foreground sessions renewing every 180 seconds, the endpoint rate is `N/180` requests/second (10,000 sessions ≈55.6 requests/second). For short-lease rooms renewing every 60 seconds it is `N/60` (10,000 ≈166.7 requests/second), plus joins/switches. This is a backend API/query workload and must be load-tested; it is not a zero-cost polling loop. Multiple active rooms can be renewed in one call only after a future endpoint supports batching; clients should not issue one renewal per listener.

## Reactions, reads, pins, typing, and structured cards

These mutations remain on the existing authenticated Laravel endpoints. Do not attempt RTDB client writes. Reaction and pin changes appear as sync events; fetch the canonical API representation when applying the event. Accepted message creation/deletion events now include `unread_delta` for currently authorized, unblocked recipients when the message is beyond their SQL read-through position; read events carry canonical `read_state`. These per-room deltas do not provide a complete account-wide badge projection for accounts without an active room lease, so retain the SQL unread summary as the baseline and Reverb/API path for badge parity. Typing is published by trusted Laravel writes to `chat_v1/user_typing/{viewerFirebaseUid}/{roomId}/{typistFirebaseUid}` with a maximum 30-second `expires_at`; records are recipient-scoped, block-filtered, coalesced by the existing typing endpoint, and removed on stop/logout/revocation or bounded cleanup. It is not individual online presence. Watch Together playback state, host controls, participants, and lifecycle remain on Reverb. Only Watch Together message cards enter the trusted per-user message mirror.

When listening to `user_typing/{uid}/{room}`, attach only while that room lease is valid and detach on leave, background, logout, room revocation, or account switch. Ignore entries whose `expires_at <= server_time_ms`; the Rules authorize only bounded reads and deny all client writes. The Firebase typing projection is currently gated by both Firebase feature flags.

Room metadata, joined-room discovery, full membership state, invitation responses, pins, and closure updates do not yet have a complete per-account RTDB projection. Continue using their existing Laravel API and Reverb events; do not assume `user_rooms` is a complete room directory.

The preserved SQL endpoints are `GET /api/v4/chat/unread` for account/context summaries, `POST /api/v4/chat/rooms/{room}/read` for read-through updates, `POST /api/v4/chat/rooms/{room}/typing` and `GET /api/v4/chat/rooms/{room}/typing` for typing state, and `GET /api/v4/chat/rooms/{room}/sync?cursor={cursor}` for cursor recovery. They use the same Zo Stream bearer and platform headers as other v4 chat calls. Typing writes can continue through the existing API; Firebase listeners replace the Reverb typing subscription only after a client adopts and validates this contract. Message events use `created`, `deleted`, `reactions`, `read`, `sync`, and `room_invite_status`; events may include `unread_delta`, while read events include the authoritative `read_state` payload. There is no complete Firebase event contract yet for room metadata/membership changes.

## Local database and offline behavior

Each supported client should reuse its existing persistent store where possible and add a versioned normalized message table keyed by `(account_id, room_id, sql_message_id)`, plus reactions, pin state, room read position, cursor state and a durable pending-operation table. The UI reads from this local store. Keep account caches isolated; clear them on logout/account deletion according to existing privacy behavior. Merge messages by revision, preserve deletion tombstones, and deduplicate sends by `request_id`. On first load, render local rows immediately and fetch the latest 30 only when the local room cache is empty; on reconnect, resume the cursor listener and call bounded SQL reconciliation only for missed/expired changes. Keep the older-page cursor; do not redownload already stored history.

Platform implementations are Android Mobile (`ZoStreamNew`), iOS Mobile (`Zo Stream apple thar`), Windows and macOS Desktop (`Zo Stream Desktop`). Use native Firebase Auth custom-token sign-in and RTDB SDKs; desktop SDK availability and packaging need verification before those clients choose a transport. Do not modify Android TV, tvOS, web, or browser clients in this phase.

### Android Mobile and iOS Mobile staged integration checklist

This subsection applies only after backend readiness approval. It does not authorize a client code change or production Firebase gate change.

| Concern | Android Mobile (`ZoStreamNew`) | iOS Mobile (`Zo Stream apple thar`) |
|---|---|---|
| Zo Stream API auth | Existing `Authorization: Bearer <access token>` plus `X-Client-Platform: android`, existing `X-Client-Version` and device headers; middleware derives account/device/session identity | Same headers, with `X-Client-Platform: ios` |
| Initial Firebase sign-in | `POST /api/v4/chat/firebase/token`; Firebase Android Auth `signInWithCustomToken` only when no Firebase user/session token is available | Same endpoint; Firebase iOS Auth `signIn(withCustomToken:)` |
| Lease | `POST /api/v4/chat/firebase/lease` with `{active_room_id}` at returned 180s global or 60s short-room interval; no token mint | Same endpoint and timing |
| Local store upgrade | Add versioned account/room message revision, event cursor, read position, pin/reaction state, and durable send queue to existing database/store; migrate cached rows as revision 1 | Add equivalent fields/tables to existing local store migration; preserve current message UI models and cache namespace |
| Open room | Render local rows immediately; on first empty cache request `messages?initial=true&latest=true&limit=30`; listen to per-user feed/events (bounded query), then call sync from returned `sync_cursor` | Same ordering and limits; attach listeners after authorization lease success |
| Reconnect | Renew once on foreground if deadline reached; attach bounded listeners; call `/sync` from saved cursor; avoid reloading latest history page | Same |
| Offline send | Persist exact request UUID and payload before existing Laravel send API; retry same UUID; only show as accepted after canonical SQL response, keep pending/error state per existing UX | Same; preserve current compose/retry UX |
| Room switch/background | Detach both RTDB listeners; call existing SQL leave/join; never keep listeners on old room; foreground revalidates lease | Same |
| Watch Together | Keep existing playback/control channel, host authority, playback reconciliation, and Reverb subscription unchanged; Firebase chat listener is independent | Same |

Use `limitToLast(30)` on `user_messages/{uid}/{room}` only for the first empty-cache history/feed bootstrap, and `limitToLast(500)` on sync events only when no local cursor exists. On reconnect, resume events from the saved cursor using `startAt(savedCursor + 1)` and bounded `limitToFirst(100)`; do not download the latest 30 message rows or latest 500 events again. Keep listener registrations per account+room and remove them on leave/logout. During listener/history overlap, commit by `(account,room,SQL message id)` and max revision in the local transaction. This keeps UI behavior driven by existing local models while backend/client parity work is completed.

## Security Rules and rollout

The combined rules preserve existing QR/configuration/payment/release paths. They deny room-wide message reads and all client writes to messages, reactions, read receipts, typing, authorization projections and synchronization events. Per-user message/event reads are bounded, session-scoped, room-scoped, and disabled unless both feature gates allow them. Run the Firebase Database Emulator suite before any rules publication. Production Firebase rules have not been published, and production cutover is not approved. Keep SQL/Reverb chat live; Reverb remains required for Watch Together playback.

### Backend deployment and rollback

1. Back up the configured chat database and provision the Firebase Admin service account/database URL plus a stable `CHAT_FIREBASE_UID_KEY` in the secret store. Keep both Firebase gates false.
2. Deploy the backend code and run `php artisan chat:migrate --force` against the configured chat database. Confirm the cursor/revision/recipient tables and columns before starting workers.
3. Start the existing `chat:deliver` worker and scheduled `chat:prune` command. With `CHAT_FIREBASE_ENABLED=false`, the Firebase lane does no RTDB work; SQL/Reverb and existing FCM continue.
4. Keep production rules unchanged. Any staging authorization/fan-out trial requires separately provisioned staging credentials and explicit approval; this repository phase does not approve enabling either gate.

To roll back while both gates remained false, stop the new code/workers and revert the code release. The chat migration runner has no dedicated rollback command; use Laravel's migration rollback against the chat connection and path (for example `php artisan migrate:rollback --database=<chat-connection> --path=database/migrations/chat --step=1 --force`) only after checking that this is the latest migration batch. If any Firebase records have been published, do not drop recipient indices/cursors until those records are pruned or a recovery plan is in place. Do not roll back this migration after enabling clients or publishing data without a reviewed data-preservation plan.

Useful API errors include HTTP 401 (Zo Stream/session invalid), 403 (platform or room authorization), 404 (room/message unavailable), 409 (idempotency conflict), 422 (`VALIDATION_FAILED`), 429 (rate limited), and 503 (feature disabled or Firebase unavailable). Reuse the response `error.code` when present; do not retry permanent 4xx failures without user correction.

## Current rollout state

Token issuance, SQL authorization projection, trusted outbox delivery, bounded per-user fan-out, cursor recovery, and cleanup code are checked in. Neither Firebase feature gate is enabled by default, no client repository has been modified, no production Firebase settings/data/rules have been changed, and the existing SQL/Reverb clients remain authoritative. Firebase usage and production policy parity are not yet measured, so this contract describes the backend wire contract for review and staged client work, not an approved production cutover.
