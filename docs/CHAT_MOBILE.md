# Mobile chat (SDK 1.5.0)

Android mobile and iOS now expose Global Chat from Home and movie/episode chat
from online playback. The Vue admin includes chat moderation and analytics; see [admin guide](CHAT_ADMIN.md).
The native packages already referenced by both apps contain `ZoChatClient`;
there is no Firebase chat dependency. Analytics collection can be disabled
independently of chat.

Flow comparison and verification limits: [Android/iOS review](CHAT_MOBILE_PARITY.md).

On both mobile apps, Home has a floating **Global Chat** button at the bottom
right, above the bottom navigation. Online movie/episode playback has a chat
toggle in its player controls. Portrait shows the player above chat; landscape
shows the player on the left and chat in the right 40% of the available width.
Closing player chat restores the full player. The Home launcher opens its
existing chat sheet. Tapping it expands a rounded panel from the
button into the lower part of the screen. Tapping outside or Close reverses
the animation back into the button (Android Back also closes it). The panel
stays below full-screen height, adapts to the keyboard and safe areas, and leaves
the underlying playback visible. Closing or leaving the screen releases the
chat connection. Reduced-motion/system animation preferences are respected.

## Data and realtime flow

### Unread badges and read position

The Home launcher and player controller chat button show unread counts. Room
tabs show individual counts; iOS's Rooms menu also lists each room's count.
Counts exclude the viewer's own messages, blocked senders, removed messages,
expired history and rooms they cannot currently access. Home includes Global
and active private rooms; the player additionally includes its current content
and matching Watch Together rooms.

`GET /api/v4/chat/unread?type=global|movie|episode&content_id=...` returns
`total_unread` and `rooms` containing `room_id`, `key`, `unread_count`,
`last_read_message_id` and `first_unread_id`. IDs are strings. Movie counts
require the existing playback authorization. Badge snapshots refresh every ten
seconds while the screen is active, with additional refreshes on realtime events
and read acknowledgements. This does not add background push notifications.

`POST /api/v4/chat/rooms/{room}/read` with `message_id` advances a user/room read
cursor monotonically. Opening a sheet or fetching history does not mark messages
read: clients report messages actually visible in the transcript. Read cursors
are shared between devices and do not extend a private room's idle deadline.
Pending read acknowledgements are stored locally per account for retries.

Reopening/switching to a room starts at its first unread message, with a New
messages divider. Unread history starts immediately after that boundary and
loads in pages, including when the boundary is older than the recent window.
With no unread messages, the room opens at the bottom. While already following
the bottom, incoming messages continue to auto-scroll. Reading older messages
preserves the position; New messages jumps to the latest page. With no saved
read cursor yet, retained incoming messages count as unread.

Apply `2026_10_26_000003_create_chat_room_reads.php` before deploying these APIs
or the updated apps. Account deletion also removes server-side read cursors.

1. `POST /api/v4/chat/rooms/join` with `type: global|movie|episode` and, for
   content rooms, `content_id`. Identity comes from `auth.token`, never the body.
2. All content-room requests include `X-Playback-Token`. The backend verifies an
   active stream for the authenticated account, device, content type and content
   key, with a heartbeat within three minutes. Subscription/PPV entitlement is
   established by the existing playback-start flow. Playback authorization is
   cached for **at most five seconds**; stopping playback is reflected within
   that bound. Offline files cannot grant chat access.
3. The room response contains its numeric ID, private channel, current viewer ID,
   public WSS URL, slow-mode duration and approximate online connection count.
4. Clients connect using the Pusher protocol, then POST `socket_id` to
   `/rooms/{room}/auth`. Only this server computes the HMAC; the app secret stays
   server-side. Both clients supply their API origin and respond to ping frames.
5. `GET /rooms/{room}/messages` returns the newest 50 messages in ascending order.
   `?before=<oldest-id>` loads older pages; `?after=<newest-id>` catches up in
   ascending pages until `has_more` is false. Cursors are exclusive message IDs.
   Do not combine `before` and `after`. IDs are JSON strings, including on iOS.
6. Send `{request_id: UUID, body: text, spoiler: bool, app_session_id?: UUID,
   playback_session_id?: UUID}` to `POST /rooms/{room}/messages`. MySQL stores
   the message and durable outbox record in one transaction. Unique
   `(user_id, request_id)` makes retries idempotent. Preserve that UUID when
   retrying an uncertain send; the app shows an explicit retry action.
7. `chat:deliver` publishes `chat.changed` with **only** `kind` and `message_id`.
   Clients fetch authorized history through the API and merge by ID. No message
   text crosses the broadcast channel. Existing subscriptions therefore cannot
   bypass a new ban, block, closed room or expired playback permission.
8. Reconnect refreshes the recent page (discarding the previously loaded window)
   so deletions missed while offline are also removed. A 30-second foreground reconciliation also
   recovers missed notifications and heartbeats the room. Deleted events remove
   or tombstone the displayed message. Backgrounding/logout/closing releases
   native connections. Redis expires abandoned presence in 90 seconds (connection
   counts can count the same account on multiple devices).

Clients preserve scroll position when prepending history, reveal spoilers only
on tap and offer a new-message button while reading older messages. Long press
opens report/block actions; the Blocked menu allows unblocking. Movie chat groups
viewers by content, not by playback minute. Spoilers remain possible.

Recent messages (up to 100, TTL 10s), room metadata (30s), moderation permissions
(15s), block lists (60s), playback validation (5s), presence and atomic send
limits use Redis. Moderation/block API writes invalidate the relevant caches.
Manual database changes may remain cached for those TTLs. Cache-read outages
fall back to MySQL; sends fail closed if their atomic spam limiter is unavailable.
This avoids reopening an unthrottled write path during a Redis outage.

## Security and moderation API

### Private room lifecycle

`POST /invite-rooms` creates a private room when `type` is `global`. Private
rooms expire after one hour without a new message, measured from creation if
no messages have been sent. Reading, joining, accepting invitations, heartbeats
and retrying an existing message do not extend this deadline. Watch Together
rooms retain their existing playback-based access rules.

`GET /invite-rooms` restores active room tabs for the creator and accepted
invitees whenever the chat sheet opens. Pending invitees use Invitations first.
Expired, ended and closed rooms are excluded. Both apps also refresh this list
during the 30-second foreground reconciliation and return to Global if the
selected room is no longer available.

Only the creator of a private room can call `POST /invite-rooms/{room}/end`.
Android and iOS show **End room** in that creator's chat options, with a
confirmation. Ending is permanent and idempotent; it denies further joins,
history, authorization, heartbeats and messages for everyone. A durable `ended`
notification lets connected members refresh promptly. Message writes and manual
termination lock the same membership row so a concurrent send cannot reopen an
ended room. Idle expiry is enforced by API checks without depending on cron.

Every chat route is authenticated. Use HTTPS and WSS. Plain text only, maximum
1,000 characters; both UIs render text without HTML. Sender identity is trusted
middleware data and display names come from the user record. Per-account request
limits plus a shared Redis two-second send limit prevent multi-device/server
bypass. Reverb client events are disabled: messages can only enter via the API.

- `POST /blocks`: `{user_id, blocked: true|false}`; `GET /blocks` lists blocked
  viewers. Blocking hides the other viewer's messages from the blocker.
- `POST /rooms/{room}/messages/{message}/report`: `{reason, app_session_id?}`.
  Reports are idempotent per reporter/message and cannot target another room.
- `POST /rooms/{room}/heartbeat`: approximate online count; `/leave` is available
  for best-effort explicit departure. Native clients rely on connection close
  and TTL expiry when closing.
- **Admin only** `GET /reports?before=<report-id>`: pending report queue.
- **Admin only** `POST /reports/{report}/resolve`: `{reason}` and audited action.
- **Admin only** `POST /rooms/{room}/moderate`:
  `{action, target, reason, expires_at?}`. Actions are `delete` (target message
  ID), `mute`, `ban`, `unban` (target user ID), `close`, `open` (target room ID).
  Use room `0` for account-wide mute/ban/unban. Mute prevents sends; ban also
  prevents history/auth. All moderation actions are audited. Deleting a message
  replaces its body with a tombstone and invalidates recent history.

Tokens, playback tokens and message bodies are not included in chat worker logs
or analytics. This is moderated server-readable chat, not end-to-end encryption.
A ban prevents future reads; it cannot erase text someone has already read.
The existing OTP account-deletion flow erases chat text/display names, anonymizes
sender IDs, removes owned reports/blocks/bans, removes pending chat telemetry and
publishes deletion notifications. Configure backup retention separately.

## Analytics

The existing product event batch endpoint/queue handles `chat_opened`,
`chat_closed`, `chat_connection_failed`, `chat_message_failed` on mobile SDK 1.5.
Room type/ID, content ID and the optional playback analytics UUID connect chat to
existing watch sessions. Playback tokens must never be used as analytics IDs.

Only the server records `chat_message_sent` and `chat_message_reported`, after
successful persistence. These names are deliberately absent from the client
allowlist. The outbox uses stable event UUIDs and the existing analytics DB's
unique event ID prevents duplicate counts. Collection is checked both when the
outbox is created and before analytics delivery. Turning off analytics does not
turn off chat. Analytics delivery is independent of broadcast delivery; an
analytics outage cannot hold up later live notifications. Both retry with
bounded exponential backoff. The Vue Analytics page includes a Chat tab, and `/admin/chat` provides moderation and chat analytics.

## Retention and operations

`chat:prune` runs daily through the existing scheduler. Normal history expires
at 30 days and is excluded from reads even before pruning. Messages with pending
reports are retained for review for up to 90 days. Delivered outbox rows are
removed after seven days once telemetry is also handled. Ban expiry is cleaned
by the same task. Run one `chat:deliver` worker per deployment; duplicate delivery
is harmless, but multiple workers waste resources. Monitor pending outbox age,
Redis memory, WebSocket connections and HTTP error rates.

Deployment: [chat deployment](deploy/CHAT.md).
