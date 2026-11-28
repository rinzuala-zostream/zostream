# Mobile chat contract

Android and iOS use ZoStream bearer authentication for chat. Message writes, membership, invitations, reactions, reads, pins and reports go through `/api/v2/chat`; only ephemeral typing is client-writable in RTDB. Chat no longer has user-to-user blocking. Watch Together playback/control is outside this change.

## Realtime access

Updated SDK requests advertise `X-Chat-Protocol: rtdb-2`. Join and unread responses advertise `firebase_realtime: true` only to those clients. Older clients retain the HTTP history/sync path.

`POST firebase/token` verifies the ZoStream session and mints a Firebase custom token with the SQL user UID, a session-bound `chat_session` claim and `chat_name`. The native adapters use a separate named `ZoStreamChat` Firebase app; users do not see another login.

`POST firebase/lease` accepts an optional `active_room_id`. It checks the current SQL session and room membership, renews `chat_v1/access/{uid}/{sessionHash}/expires_at` for at most 90 seconds (never beyond bearer expiry), and requests renewal after 30 seconds. A removed/expired room returns `room_projection_refreshed: false`, causing the SDK to detach and clear the transcript. Revoked ZoStream sessions cannot renew; outstanding RTDB access can last up to the existing 90-second lease.

RTDB rules allow users to read only their own `user_messages/{uid}/{room}`, `user_sync_events/{uid}/{room}`, and `user_account_events/{uid}` feeds. Room reads also require an active room and active membership. All authorization state, transcripts and projections remain server-owned.

## Messages and sync

Global, movie and episode rooms use `POST rooms/join`. Private rooms require an accepted invitation and use `POST invite-rooms/{room}/join`. A removed member cannot reactivate themselves by joining again.

`POST rooms/{room}/messages` accepts `body` (1,000 characters), UUID `request_id`, `spoiler`, and optional `reply_to_id`, movie suggestion ID, room invitation ID or movie moment. Sender identity comes from the authenticated SQL row. Reply targets must exist in the same room; movie suggestion fields are resolved from the published catalog; moments must match the room's title.

A shared cache lock serializes room mutations. An RTDB transaction allocates a monotonic numeric cursor. One multi-location update writes the canonical message, request deduplication mapping, room cursor, eligible member projections/sync events, account invalidations and notification outbox entries. Retrying the same request ID returns the existing message.

SDKs listen to their message and sync feeds. Unread summaries update badges first, then a bounded background worker caches only the first unread window (at most 30 messages) for each room with unread messages. A cached complete first page is reused on subsequent summaries. If only part of that window is cached, the worker requests just the remaining capacity with `after` and `limit`; it never follows `has_more` to prefetch subsequent pages. Prefetching does not join a room or acknowledge reads. Joining or accepting an invitation does not copy old messages into the member feed.

Opening chat requests `messages?initial=1`: the response starts at the first eligible unread message, contains at most 30 messages, and uses `initial_after` to distinguish forward unread pagination from latest-page history when nothing is unread. Scrolling toward newer messages fetches one `after` page at a time, starting roughly three rows before the end on Android or within 35% of a viewport (minimum 180 points) on iOS; scrolling toward older messages fetches one `before` page. There is no automatic drain of unread pages, including on reconnect/reopen. An explicit jump to latest fetches only that final page.

Native disk caches store downloaded records, covered cursor ranges and a monotonic latest known message ID, so a late background prefetch cannot replace newer metadata. Reopening selects a window beginning at the remaining first unread message; cached pages display without fetching those pages again. Missing ranges go to the authenticated backend and are cached as they load. A cached reply/live message outside a downloaded interval does not conceal a missing page. The transcript listener rejoins at the current room cursor before reattaching after hiding, rather than replaying every missed unread body. Recovery checks at most one delta page and one bounded cached tail page for updates.

Loading/caching never marks messages read. Only visible message rows enqueue a read-through acknowledgement; the read endpoint advances that cursor monotonically. Unread summaries and read acknowledgements include account snapshot cursors so late responses cannot overwrite a newer badge.

## Typing

Both SDKs write `room_typing/{room}/{uid}_{typingSessionID}` directly through the native adapter. Rules enforce the caller's UID, token-bound name, send permission, nondecreasing sequence and an expiry no more than ten seconds ahead. Native clients use a six-second expiry and register `onDisconnect` removal. Multiple device/session indicators are deduplicated per user; a user's own indicator is hidden. Typing never produces a push. The HTTP typing endpoint remains as recovery/older-client compatibility.

## Private rooms and moderation

Create/invite endpoints use existing phone lookup, contact picker, user long-press, invitation list and deep-link screens. Invitation tokens are random and bound to the recipient's ZoStream account. Sharing `/invite` creates a card; opening it creates a pending invitation and does not admit someone until they accept. Only the creator can invite additional people or end a room.

Ordinary private rooms expire one hour after their last message. Reads, typing and reactions do not extend this period. Expiry is enforced during authorization and in `chat:maintain`. Rooms marked as Watch Together content or actively watching are excluded from ordinary-chat expiry.

Reactions use desired active/inactive state. Pins require existing ZoStream administrator access. Reports persist the reporter, message and reason.

## Push policy and operation

- Private room messages notify members except the sender.
- Global/movie/episode messages do not notify the room; replies notify the original author and reactions notify the message author.
- Self-actions never notify the actor. A private reply produces only one notification per recipient.
- Invitations notify their intended recipient.

Outbox rows are included in the originating RTDB update. Delivery is attempted after the HTTP response; the scheduler recovers pending rows. The delivery job rechecks membership, room expiry and invitation status. It claims an entry before FCM I/O and does not automatically replay an ambiguous sending/failed entry, avoiding duplicate pushes. FCM currently uses the account's existing `user.token` field. Android chat keys use the app's existing message/invitation notification channels and are never treated as movie IDs.

Deploy the RTDB rules before enabling the new backend and releasing the updated mobile apps. Configure `FIREBASE_DATABASE_URL` and the existing Firebase service account with RTDB and custom-token signing access. API and queue hosts must share the same database/Redis cache lock store. Keep the existing Laravel scheduler and queue worker running; `chat:maintain` runs every minute. No production deployment or live FCM delivery is performed by the local tests.

## Validation

`php vendor/bin/phpunit tests/Feature/ChatApiTest.php tests/Unit/NotificationImagePayloadTest.php`

`swift test --package-path Library/ios --filter ChatClientTests`

Run Android chat SDK/app tests from `ZoStreamNew`. The current unrelated `ActivationQrTest.java` does not compile against the redemption API; a temporary Gradle init-script exclusion can isolate chat tests without changing that source.

Run `python3 tests/firebase/chat_rules_test.py` against a disposable database emulator on `127.0.0.1:19090`. It uses only the `demo-zostream-chat` namespace and no production credentials.

Send acknowledgements: iOS accepts either the authenticated API response or a canonical RTDB message matching the pending request ID, room, sender and account. The first confirmation completes the send and cancels the outstanding HTTP task; an unconfirmed timeout preserves the draft and operation ID for retry. No additional listener or RTDB read is opened for this confirmation. Watch Together command handling is unchanged. Push delivery is queued normally rather than executed with `afterResponse`; a sync queue configuration falls back to the database queue, which needs an active database worker. Queue submission failure after a committed write leaves the durable pending outbox for `chat:maintain` and does not report that send as failed.
