# Deploy mobile chat

Reactions, replies, typing, movie moments and the transient player overlay are
documented in [CHAT_INTERACTIONS.md](CHAT_INTERACTIONS.md), including the new
PostgreSQL migration and rollout order.

## Chat action error dialogs — 30 October

Rebuild Android and iOS for the updated error presentation. Failed sends, room
opening, initial history loading, history pagination, watch-session joins and
block/report actions show a titled notice with the server's explanation. Failed
sends preserve the draft and request ID; Android offers Retry/Cancel, and iOS
keeps the Send retry control available after dismissing the notice. Existing
invitation dialogs continue handling invitation errors.

Connection status stays in the header. Periodic reconciliation failures retain
the current transcript and retry in the background without repeated modal
notices. Closing chat or switching rooms dismisses its pending error notice.
No backend API or database change is required for this presentation update.

## Initial message loading — 30 October

Deploy the updated backend before rebuilding Android and iOS (including the local
ZoAnalytics Swift package). Opening a room now fetches
`GET /api/v4/chat/rooms/{room}/messages?initial=1`: one response supplies the first
unread position and its history page. It avoids waiting for two full-account
unread summaries before loading messages. Only unsent read progress for the
selected room is flushed first, when present. Badge refreshes continue separately.

The response adds nullable string fields `first_unread_id` and `initial_after`.
When unread messages exist, history starts just before the oldest eligible unread
message and is ordered ascending; `has_more` indicates newer pages. Otherwise it
returns the usual recent page and `has_more` indicates older history. `initial=1`
cannot be combined with `before` or `after`. Authorization, blocks, retention and
deleted-message rules still apply. Android loads room-list, block-list and watch
metadata after initial history so they do not queue ahead of the transcript.

No database migration or Reverb protocol change is required. Older app builds
continue using the previous messages endpoint behavior. Retest cold opening with
three messages, older unread history exceeding one page, a read/reopen cycle,
cached tab switching and reconnect recovery. Actual VPS/network latency still
needs device measurement; local tests verify ordering and correctness.

## When both apps stay on Reconnecting

Message history and sending use HTTPS independently of the live WebSocket. If
messages appear only after reopening, verify the live transport and outbox on
the deployed API release:

```sh
php artisan chat:realtime-status --probe --origin=https://zostream.in
sudo supervisorctl status
```

Use the HTTPS API root actually returned by official-client verification as
`--origin` if it differs. The diagnostic reads effective cached configuration
and pending broadcasts, then optionally tests the public WebSocket Upgrade.
It does not subscribe, publish messages, drain the outbox or print keys/secrets.
Expect HTTP 101 with a valid upgrade signature. A successful upgrade alone does
not verify private subscription authorization or worker delivery.

Fix any reported WSS URL/public-key mismatch, disallowed API Origin, missing
Reverb configuration or overdue outbox. Check DNS/TLS, the `/app/` Upgrade proxy
and Reverb logs for a failed upgrade. Ensure both `reverb:start` and `chat:deliver`
are supervised; `queue:work` alone does not deliver chat socket events. After
changing deployment configuration, rebuild the config cache and restart both
chat processes. Preserve the leased-channel API/worker/mobile compatibility
described below. Do not switch off TLS or allow all origins as a workaround.

### Live, but messages appear only after reopening

`Live` confirms a socket subscription, not that the delivery worker is running
the same release or publishing to that subscription's channel. HTTP 101 and an
empty outbox also cannot establish that. After each backend chat release,
restart the long-running delivery process to load the new code/config:

```sh
sudo supervisorctl restart zostream-chat-delivery
```

Keep the receiver's chat open and send a new message from another account.
Reverb itself need not restart for a delivery-worker code update; restart it
when its configuration/code changes. On 28 October, the reported production
case recovered immediately after restarting only `zostream-chat-delivery`,
without reopening the receiver. This points to stale worker process state;
the exact earlier publication channel was not captured.

The same investigation reproduced a separate cache race in local feature tests:
a receiver fetching after publication could receive the old recent snapshot.
The worker now invalidates recent history before publishing, and `after` delta
requests read committed database rows rather than the recent snapshot. Initial
latest-page reads retain their cache; authorization, blocked-user filtering,
retention and pagination remain enforced. Deploy this API/worker fix and restart
the delivery process. The cache regressions failed before the fix and pass after
it; the backend chat/queue/diagnostic/WhatsApp suite passes 84 tests with 803
assertions. No additional mobile reload or polling behavior is introduced.

The 28 October mobile SDK update requests transcript deltas every five seconds
while a room is open and its socket is disconnected. The UI ignores these
recovery ticks while hidden; room lists, presence and unread snapshots keep
their existing cadence. Successful socket events still refresh immediately and
disable this fast fallback. This recovery path requires updated Android and iOS
builds; it does not repair a failing production WebSocket endpoint.

Verify with two accounts: keep both transcripts open, send a message and confirm
immediate receipt while Live. Then interrupt the WebSocket while keeping HTTPS
available, confirm receipt on the next recovery tick without reopening, restore
the socket and confirm Live resumes. Allow for HTTP request duration and existing
server throttling; five seconds is the polling interval, not a latency guarantee.

Source validation for this update: backend chat/queue/diagnostic tests passed
(80 tests, 785 assertions), and Android app/SDK chat tests passed (37 tests).
The Swift SDK builds and six chat test methods passed through a standalone
assertion runner, including disconnected recovery and cancellation on close.
Full XCTest is unavailable in the installed command-line toolchain; Apple chat
UI syntax parsing passed, but an iOS app/device run is still required.

## Deployment setup

Deploy backend **before** releasing the updated apps. `CHAT_ENABLED` defaults to
false so incomplete infrastructure cannot accidentally accept chat writes.
No production migration or deployment is performed by adding this code.

1. Install the committed Composer lockfile (`composer install --no-dev
   --optimize-autoloader`). The existing dependency set requires PHP 8.4; the
   Composer platform pin keeps local PHP 8.5 from upgrading beyond that target.
   Enable the Redis PHP extension and use the existing Redis authentication,
   private networking and memory monitoring. Reverb is included in this lockfile.
2. Configure the dedicated PostgreSQL database using [CHAT_POSTGRESQL.md](CHAT_POSTGRESQL.md),
   then run `php artisan chat:migrate --force`. Chat migrations live under
   `database/migrations/chat`; ordinary `php artisan migrate` does not run them.
   Main MySQL user, subscription and playback tables remain on the existing connection.
3. Generate private Reverb credentials and configure the `.env.example` chat
   entries. `REVERB_APP_KEY` is public; `REVERB_APP_SECRET` is private. The public
   URL must include that public key:
   `wss://chat.zostream.in/app/PUBLIC_KEY?protocol=7&client=zostream-mobile&version=1.0&flash=false`.
   This hostname is a deployment example and must have DNS/TLS configured.
4. Run `php artisan config:cache`; supervise the two processes below. Keep
   Reverb's HTTP port private. `REVERB_HOST/PORT/SCHEME` identify the **internal**
   publish endpoint (normally `127.0.0.1:8080`, HTTP), while
   `CHAT_WEBSOCKET_URL` identifies the **public** TLS endpoint.
5. Install a TLS proxy using the location example below. Set
   `REVERB_ALLOWED_ORIGINS` to the comma-separated API root hostnames used by
   official apps. The native clients explicitly send that Origin. Browser
   origins alone do not grant private-channel access.
6. Verify login, global chat on two devices, online movie/episode chat, history
   scroll, reconnect catch-up, send retry, report/block/unblock and moderation.
   Check that stopped/expired playback loses content-room access within five
   seconds. Verify the analytics kill switch and backend-generated event counts.
7. Set `CHAT_ENABLED=true`, rebuild config cache and restart the supervised chat
   processes. Keep the existing Laravel scheduler running for `chat:prune`.
   When testing before enablement, enable only in the staging environment.

Example Supervisor entries (replace paths/user to match the server):

```ini
[program:zostream-reverb]
command=php /var/www/zostream/current/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/zostream/current
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/zostream-reverb.log
stopwaitsecs=20

[program:zostream-chat-delivery]
command=php /var/www/zostream/current/artisan chat:deliver
directory=/var/www/zostream/current
user=www-data
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/zostream-chat-delivery.log
stopwaitsecs=20
```

### Capacity update (October 2026)

This release adds socket message feeds and routes visible-room presence through a per-socket private control channel. Reverb handles the presence event using Redis only; it does not run a database query per heartbeat. Message fan-out uses bounded batches and rechecks current sessions, room membership, blocks, and playback access before publishing. The legacy outbox event and HTTP transcript recovery remain enabled for older app builds.

Apply the single new migration before starting the updated API and worker:

```sh
php artisan chat:migrate --force
```

Keep `CHAT_CACHE_STORE=redis` for multi-server deployments. Install the updated API and mobile SDKs, rebuild Laravel config cache, then restart Reverb so its listener and Redis connections load the release. The Reverb app must continue to have client events disabled; presence uses an internal event on a socket-specific private channel.

To keep analytics or push delays from holding up websocket fan-out, replace the single `zostream-chat-delivery` Supervisor program above with these three programs (same `directory`, `user`, restart, and log settings). Do not leave the old all-lane process enabled alongside these:

```ini
[program:zostream-chat-realtime]
command=php /var/www/zostream/current/artisan chat:deliver --lane=realtime
numprocs=1
...

[program:zostream-chat-push]
command=php /var/www/zostream/current/artisan chat:deliver --lane=push
numprocs=1
...

[program:zostream-chat-analytics]
command=php /var/www/zostream/current/artisan chat:deliver --lane=analytics
numprocs=1
...
```

Run `supervisorctl reread`, `supervisorctl update`, and check all three workers plus `zostream-reverb` are `RUNNING`. Start with one process per lane; increase realtime workers only after checking database, Redis, Reverb, and outbox latency under representative load. This code change reduces work per idle socket and batches live delivery, but does not establish a production user capacity by itself; load-test the VPS before increasing advertised capacity.

Nginx fragment inside the TLS virtual host for the public chat hostname:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
}
location / { return 404; }
```

The `/apps/...` publish API stays on the private internal endpoint. Native
clients never need the secret, Redis address or database credentials.

For multiple Reverb nodes, enable `REVERB_SCALING_ENABLED=true` with a private
shared Redis connection and use the same app credentials on each node. Run one
outbox worker across the deployment. The committed configuration disables client
broadcast events, limits frame size and enables connection rate limiting.

To disable chat, set `CHAT_ENABLED=false` and rebuild config cache. APIs then
refuse joins, reads and sends; change notifications contain no chat text. Keep
stored history for recovery. A code rollout should restart Reverb and the outbox
worker so they load the updated config and code.

## Watch together in an existing private room

Apply the new migration before deploying the updated API/workers or releasing mobile builds:

```sh
php artisan chat:migrate --force
```

Android and iOS still support creating a watch room from the player. To use an
existing private room, its creator opens a movie/episode, selects that private
room in player chat, types `/`, taps the `/watch` suggestion and presses Send.
Accepted members tap the resulting title card to open the player at the host's
current position. Premium titles require the joining member's subscription;
PPV titles remain excluded.

The private room retains its content type, membership and history. Watch
sessions are attached to messages and to the creator's specific active stream.
Sending `/watch` again replaces the previous session. Stopped, expired or
replaced streams and deleted cards cannot be joined; normal private chat stays
available under its existing one-hour inactivity rule. Position controls use private-channel `watch_control` events carrying authoritative
playback state. Clients apply the payload directly; API recovery runs on reconnect
or while the control socket is disconnected.

Verify with two accounts: creator slash autocomplete, member card tap from Home
and from the player, movie/episode routing, current position sync, premium
subscription failure, stale cards, replacement sessions and normal chat after
playback ends. Restart chat workers after deploying the API changes.

## LIVE cards and playback controls

Before deploying this API or the updated mobile builds, apply:

```sh
php artisan chat:migrate --force
```

Restart the `chat:deliver` worker to deliver `watch_control` and `watch_status`
events. These private-channel events carry position/paused state and invitation
counts, and never playback tokens, URLs, credentials or messages.
Both dedicated watch rooms and `/watch` sessions support host play, pause and seek.
Control sequence numbers reject delayed writes; server timestamps and position
extrapolation account for playback during transport. Mobile clients retain a
control subscription when switching chat tabs and never publish guest controls.
An authenticated state fetch recovers on join/reconnect; a disconnected socket
uses a one-minute API fallback.

Private-room cards check `/rooms/{room}/watch-status` when joining or reconnecting.
While connected, card liveness and counts update from WebSocket payloads. Only the current session with an active matching host stream receives
LIVE highlighting. Stopped/replaced sessions lose their highlight. Creators see
“You’re hosting”; invitees see the Watch button until the session ends. Private
room membership and history stay available. A live watch session keeps its private
room available while the creator is streaming.


## Watch session freshness and invitation counts

Apply this migration before the API update:

```sh
php artisan chat:migrate --force
```

Watch position requests now refresh only the authenticated device's matching active
playback token, at most once per 45 seconds. This keeps the three-minute host
freshness check working even though legacy stream ping is a no-op. Stopped and
replaced tokens remain invalid. Host watch requests renew the private room's idle
deadline, and a live session is exempt from private chat inactivity expiry.

`GET /api/v4/chat/rooms/{room}/watch-status` adds `joined_count` and
`streaming_count` for the live session. Both count distinct invitees and exclude
the creator. Joins are idempotent; reopening the invitation does not increase the
joined total. Streaming requires a recent authenticated player position request
(within 150 seconds), the same token, and an active matching stream. Reading chat
alone does not count as watching. An orderly stream stop removes the viewer from
the next count; a disconnected player ages out after its 150-second lease, with the next room
heartbeat probe broadcasting the change (up to 60 seconds later).

Verify using two accounts for more than three minutes: host pause/play/seek,
invitee exit and rejoin with the original card, and joined/watching counts before
joining, during playback, and after exit. Also verify playback beyond one hour
without sending additional chat messages. Restart chat workers after deployment.


## WebSocket playback and count delivery

Deploy the updated API, `Library` SDKs, Android app and Apple app together, then
restart the long-running `chat:deliver` worker. This change needs no additional
migration beyond the watch-participants migration above. Older app builds keep
their polling behavior until updated.

`chat.changed` on the authenticated private room channel now includes a `watch`
object with `room_id`, `session_id`, `active`, a monotonic string `event_id`,
`joined_count`, `streaming_count`, and `playback` (position, paused, revision and
server time). Dedicated content watch rooms have a null session ID and no invitee
counts. A null playback signals an ended/unavailable session. The worker builds
snapshots from current authoritative state, so a delayed/retried event cannot
restore an older session. Clients reject older event IDs and playback revisions.
The HTTP `rooms/{room}/watch-status` response also includes `active` for both
dedicated rooms and `/watch` sessions. Clients must use this flag: a dedicated
room's null `session_id` does not mean that playback ended. Apple clients clear
the previous ended state when joining a dedicated room again.
Guests apply incoming state without another HTTP request. They retain their watch
subscription when switching chat tabs. Socket ping/pong detects broken links.

The ten-second playback and invitation-status polls are removed on both mobile
platforms. Initial join and successful reconnect fetch a baseline. While the socket
is disconnected, API reconciliation runs about once per minute. Live players POST
`rooms/{room}/watch-heartbeat` once per minute with the session ID, maintaining
authenticated stream freshness, room lifetime and participant presence. The host
heartbeat also uploads a sequenced position/paused sample to correct clock drift;
guests receive that sample over WebSocket. It returns only liveness and the current control revision. If a guest is missing
that revision after allowing two seconds for an in-flight frame, it performs a
baseline recovery. This also recovers when the delivery worker stalls while the
socket itself remains connected.
Explicit host controls still use authenticated HTTP writes and push immediately.

Join, participant playback activation, stream stop and token replacement publish
updated counts/liveness. Steady heartbeats only publish membership status when it
changes. Room probes share a 15-second cache throttle rather than scanning the
participant list separately for every viewer. Existing chat presence heartbeats
and unread-badge reconciliation remain separate from watch playback.

Verify with two devices and API traffic inspection: play/pause/seek and counts
must update from socket frames without position/status requests; normal playback
has only the one-minute watch heartbeat. Disable the socket and verify fallback,
restore it and verify baseline recovery plus immediate subsequent controls. Stop
or replace the creator's stream, and confirm the original card loses LIVE status.


## Live unread badges

Deploy this migration before the API release that writes personal badge events:

```sh
php artisan chat:migrate --force
```

Restart `chat:deliver` with the new release before updating the mobile builds.
The worker must understand `recipient_user_id`: personal read/sync events publish
only to the account's hashed private channel, never to a room channel. Keep old
worker processes stopped during this transition. Run your normal API config/route
cache refresh. Android and Apple both require updated app builds and SDK sources.

`chat.changed` carries lightweight room badge events (IDs/eligibility, without
message text). `chat.badge` carries account-private read snapshots and recovery
signals. Authenticated POST `/api/v4/chat/unread/auth` authorizes each subscription;
private membership, bans/closure and content playback are checked. One socket
observes the account, Global, current content and allowed private rooms, and does
not count as chat presence. Stable public-context channels discover newly created
rooms; private rooms have no shared public discovery channel.

No unread count endpoint runs per message. Each phone applies event deltas and
read snapshots locally, with deduplication and buffering across baseline requests.
The idle ten-second poll becomes a five-minute integrity snapshot (30 times fewer
scheduled count fetches). Disconnected fallback is one minute. Opening/reconnecting,
first-unread deletions, membership/block changes and bounded-log recovery may also
fetch a baseline. This reduces scheduled badge database work; production capacity
still needs measurement under actual message/viewer traffic.

Verify Home and player badges with the chat sheet closed, own and blocked senders,
message deletion, read acknowledgement from a second device, duplicate/reordered
frames, and socket reconnect. Confirm there are no ten-second unread requests and
no additional unread fetch for each ordinary message/read event. Check that another
account cannot authorize your personal channel or an unaccepted private room.


## Lifecycle/recovery and leased channels — 27 October 2026

This release needs coordinated API, `chat:deliver`, Android SDK/app and Apple
SDK/app updates. It needs no additional migration beyond those already listed.
Restart the long-running delivery worker; it now publishes only to leased
channels. Older SDKs that reject an auth channel different from the logical
join channel must be updated for live chat. Do not retain a worker publishing
to permanent channels, because that would bypass the metadata expiry guarantee.

Authorization returns a physical `.lease.<epoch>` channel and `expires_in`.
Updated SDKs renew with authenticated HTTP at expiry using their 30-second
health timer; the server grants no new leases after access is revoked.
Publication to the previous epoch stops after 60 seconds, giving a maximum
six-minute metadata lease. Message bodies remain protected by per-request access
checks. Chat disablement also suspends broadcast delivery; pending events retry
when it is re-enabled. Admin review and moderation remain available.

Verify room and badge renewal across an epoch boundary without extra sockets;
ban/stop playback and check renewal denial; check no old-channel publication
past the grace period. Leave Reverb connected while stopping the delivery worker:
the open transcript must recover recent messages/deletions through its five-minute
integrity check; room authorization/presence maintenance remains thirty seconds.
Confirm reconciliation does not add watch-position
polls. Also verify live private rooms after an elapsed idle deadline, closed-room
invitation rejection, disabled viewer endpoints, and account-deletion cleanup.

## Watch Together guest playback controls — 28 October

Deploy the updated backend and both mobile apps; no new migration is required.
Restart `zostream-chat-delivery` after deployment. Verify with two real accounts
before release; these changes affect playback, not just the chat card.

- Invitees cannot use the player seek buttons, double-tap seeking or scrubber.
  Creators retain seeking. iOS also sets AVKit's
  [linear-playback restriction](https://developer.apple.com/documentation/avkit/avplayerviewcontroller/requireslinearplayback)
  for invitee player presentation.
- Invitees may pause locally. Incoming creator controls do not cancel that
  local pause. Play requests an authorized, fresh creator position; playback
  resumes from that position, respecting a still-paused creator. A newer socket
  state wins over an older HTTP response arriving during this request.
- Both watch players run at stable 1x. Random speed variation and user speed
  changes are suppressed during Watch Together. Creator buffering is reported
  as paused; guests follow when playback resumes. Client device-clock differences
  are not added to the server's already-extrapolated position. Decoder and network
  delay still prevent a guarantee of frame-identical playback.
- A creator-end snapshot, replacement watch session, or denied watch access
  stops the invitee player and submits its stream stop. The backend also marks
  a stopped creator's private `/watch` session inactive and stops matching bound
  guest stream tokens. Replaced tokens and another active watch binding are
  preserved. The private chat remains usable subject to normal room lifetime.
- Player overlays show Creator/Following creator and the ended state. Content
  changes reset the watch restriction so independent playback retains its controls.

Test pause for at least a minute and resume while the creator is playing,
paused and buffering; test host seek, slow/late responses, differing device
clocks, switching content, guest quality changes, host Back/Close and natural
completion. Test with chat hidden or another room selected. Confirm the invitee
stops once, its player service/stream closes, and the ended message remains.
An abrupt crash/network loss without a stop signal still relies on existing
stream freshness/heartbeat expiry; it cannot emit an immediate departure event.

Local verification: backend chat/diagnostic/queue/WhatsApp tests pass (87 tests,
821 assertions). Android app/SDK compilation and chat tests pass, including fresh
resume, clock-skew and duplicate-end-event cases. Eleven extracted iOS player
behavior checks and five state-ordering checks run using standalone Swift
harnesses; screen syntax parsing passes. Full iOS app compilation, XCTest,
device rendering, two-device latency and live provider delivery are not verified
by those harnesses.

## Dedicated Watch Together room expiry — 28 October

Deploy `2026_10_28_000001_bind_dedicated_watch_rooms_to_host_stream` before
restarting `zostream-chat-delivery`. New dedicated rooms bind to the creator's
stream ID and token hash. The migration binds currently live legacy rooms and
ends abandoned ones. A different device/session playing the same title cannot
keep a bound room alive or reopen it after it ends.

A successful creator playback stop permanently sets the room inactive and queues
an `ended` event. Discovery, pending invites, invitation links, history, sends,
position reads and channel authorization reject the ended room. Android and iOS
remove its tab and cached transcript for both the creator and invitees, and return
a selected room to Global chat. This also works after a missed socket event when
the API or room list confirms closure. Guest Watch Together playback stops,
including when another chat tab is selected.
Plain private chat rooms keep their existing lifecycle.

An unclean exit cannot send a stop request: access expires with the existing
three-minute stream heartbeat deadline. The delivery worker checks for abandoned
rooms every 15 seconds even with an empty outbox, then publishes their ended
state. Keep the delivery worker running; socket disconnection alone is not used
to infer playback termination.

Deployment commands, from the production backend directory:

```sh
php artisan chat:migrate --force
sudo supervisorctl restart zostream-chat-delivery
```

Ship the updated Android and iOS apps for immediate tab/cache retirement. Verify
with two accounts: stop the creator, confirm the guest player stops and its room
tab disappears, then restart the same title and confirm the old room/link still
cannot be opened. Also test with the guest on Global chat and with an unclean
creator exit beyond three minutes.

## Global chat invitations and `/invite` cards — 28 October

Run `php artisan chat:migrate --force` for
`2026_10_28_000002_add_shared_room_invitation_messages`, deploy the Library SDKs
and both mobile apps, and restart `zostream-chat-delivery`.

In Global chat, long press another viewer's username/message and choose **Invite
to private room**. The creator chooses one of their active plain private rooms.
The client posts the existing invitees endpoint with `delivery: in_app`; this
creates a normal pending invitation and sends ZoStream push, without queuing a
WhatsApp job. Invited users see the same Invitations list and accept/decline flow.
Phone-number invitations retain their existing WhatsApp and push behavior.

In Global, Movie and Episode chat, typing `/` offers `/invite` when the viewer
owns an active private room. Sending `/invite` opens a room picker with selectable room cards and an explicit
Share invitation button. Both apps dismiss the picker before sending; Cancel
preserves the draft and sends nothing. iOS snapshots the source chat and draft,
rechecks that the selected room is active after dismissal, and ignores a repeated
dismissal or a changed source chat. Its transcript also skips redundant bottom
scroll requests and ignores subpixel/width-only size changes during presentation.
A successful share publishes a purple private-room invitation card. This public card grants no room access by
itself: **View invitation** creates/reuses a pending invitation for the signed-in
viewer, then opens Invitations. An accepted member can reopen their room. The
server checks source-chat access, message visibility/deletion/retention, creator
ownership and target-room lifecycle. Card sharing is unavailable inside private
rooms and cannot share another creator's room or a dedicated watch room.

Ending a room publishes `room_invite_status` to each public source chat containing
one of its cards. Both SDKs surface the state without reloading history. Every
loaded card for that target renders **Room ended** and loses its join action.
History reads and bounded status reconciliation compute current state even from
cached transcripts; reconnect also checks older loaded cards. The delivery
worker detects idle expired shared rooms every 15 seconds while preserving a
live `/watch` session's existing room lifetime.

Push notifications carry a recipient-bound invitation token and open the existing
invitation screen when tapped. Android now displays chat invitation notifications
in the foreground as well as FCM's background handling. iOS registers APNs/FCM
after sign-in/notification permission and updates its token through authenticated
`POST /api/v4/account/push-token`; the backend binds that token to the current
account. iOS distribution requires a Push Notifications-enabled provisioning
profile and an APNs key/certificate configured for this app in Firebase. Verify
push delivery and cold/foreground notification taps on physical devices; local
unit checks cannot verify the production APNs/FCM provider setup.

## Invitation sheet and phone entry — 28 October

Android invitation links and push notifications now use a translucent activity
hosting a native Material bottom sheet. The sheet has a drag handle, close button,
scrolling room details and pinned accept/decline actions. Dismissing it closes the
invitation host; sign-in preserves the invitation token, and accepted rooms keep
the existing private-chat/watch-player routing. A successful decline dismisses the
sheet. Accepting a private room dismisses the invitation before opening chat;
closing that chat finishes the transparent host instead of revealing the old
invitation. Failed requests retain their retry/error UI. Secure-window protection also
applies to the dialog itself.

Android and iOS create-room/invite-more forms no longer ask for a country calling
code or prepend +91. A local number is sent as a local number; a contact's explicit
+ / 00 international prefix is preserved/normalized. The server compares the full
national number or canonical international number using the recipient's stored
`auth_phone` and `country_code`. It rejects suffix-only matches. If multiple
accounts share a national number, enter the full international number in the same
phone field to disambiguate; no separate country selector is required.

Deploy the backend lookup update and new app builds. This change adds no migration.

For long-press invitations, the room picker now checks
`GET chat/invite-rooms?recipient_user_id={user}`. The response includes
`recipient_joined` for rooms owned by the caller; membership in rooms they do not
own is not disclosed. An accepted invitation counts as joined even after its
original invitation deadline. Both apps show joined rooms in grey with **Already
joined**, disallow selecting them, and keep confirmation disabled until the
membership check succeeds. Failed checks offer Retry. Disabled create-room type
options also use explicit neutral grey styling. Deploy the API and app updates;
this membership check adds no database migration.

## Member limits and private-message push

Private rooms accept at most **10 members including the creator**. Dedicated
Watch Together rooms accept at most **5 including the creator**. Pending invites
do not reserve seats: acceptance locks the room and returns HTTP 409 when full,
leaving that invitation pending so it can still be declined. Existing accepted
members can reopen their room. A `/watch` session in a private room also admits
at most 5 distinct members including its creator; repeated joins by the same
user reuse their seat. A heartbeat cannot bypass this admission check. Seats
belong to that watch session; starting a new session creates a new participant list.

Only private/dedicated-watch room messages enqueue `message_push` outbox rows,
one per accepted recipient other than the sender, in the message transaction.
`chat:deliver` handles push separately from WebSocket broadcasts, so a failed push
can retry without rebroadcasting the message. Delivery rechecks membership, room
lifecycle, deletion, blocks, bans, read receipts and current visibility. Spoiler
text is replaced by a generic preview. Pending invitees never receive room-message
push. Messages older than five minutes expire instead of notifying from a backlog.

The native chat clients report foreground room visibility on the existing
heartbeat endpoint and renew it every 30 seconds. A foreground Watch Together
player also holds visibility for its watch room even when its chat is hidden.
Merely keeping a WebSocket connected does not suppress notifications. Each client
has its own `visibility_id`, so closing chat cannot clear a watch player's marker
on the same device. Hiding, switching rooms, closing, and backgrounding publish a
hidden state; sequence numbers reject late older requests. Unclean exits or failed
final requests expire within 90 seconds. Cache failure falls back to delivering
the notification. The final hidden-state request survives transport shutdown.

`key=chat_message` carries `room_id` and `message_id`. Both apps route taps through
an authenticated room join, opening private chat or the dedicated watch player;
a room ID never grants membership. Sign-in can resume the pending destination.
Invitation notifications retain their token-based accept/decline flow.

Deploy the backend and shared SDK changes, then release both native apps. Old
clients do not report visibility and may receive pushes while their room is open.
This update requires no new database migration beyond the existing chat schema.
Refresh production configuration and restart the long-running delivery worker:

```sh
php artisan config:cache
sudo supervisorctl restart zostream-chat-delivery
```

Verify on physical Android/iOS devices: full-room rejection, rejoin at capacity,
push while a different room is open or the app is in the background, no push in
the visible target room/foreground watch player, and correct cold/warm tap routing.

## Private `/watch` session termination — 29 October

The delivery worker now checks private `/watch` sessions as well as dedicated
watch rooms every 15 seconds. It reads active sessions and their bound streams
in batches of 200. A missed stop callback, replaced token, deleted invitation,
closed room or expired host heartbeat ends the session and stops its matching
guest streams. Session termination and its `watch_status` outbox row commit in
one transaction. The private chat remains available under its existing idle rule.

An orderly creator stop publishes ended status immediately through the outbox.
An unclean exit is detected after the existing three-minute heartbeat deadline,
plus up to one worker sweep and event delivery time. No per-viewer polling is added.

History and send/retry responses include `watch_invite.active`, recomputed even
when message bodies came from the recent-history cache. Private watch snapshots
also include `latest_session_id` when inactive. Updated apps remember that ended
session boundary even if the event arrives before its message or while another
tab is selected. Older sessions cannot regain a Watch button when history loads.

Deploy backend and the iOS SDK change, release both updated apps, then restart
`zostream-chat-delivery` (or all configured chat delivery lanes). No new migration
or Reverb restart is required for this update.

Verify with two devices: creator stop, app termination without a stop request,
session replacement, reopening an old tab, and delayed invitation delivery.
Ended cards must stay ended and a stopped guest must not keep streaming. A fresh
`/watch` must still be usable in the same private chat.

## Create a room from a chat participant — 29 October

Android and iOS offer **Create private room** when long-pressing another user's
message in Global or Movie/Episode Chat. The create-room form shows that account
as the selected invitee and starts with Private chat selected. No phone lookup
is needed. The existing **Invite to private room** action is also available in
both public chat contexts when the creator has an active private room.

Selected-user creation uses `POST /api/v4/chat/invite-rooms` with
`delivery=in_app`. Push, the Invitations list, links and Accept/Decline use the
existing invitation flow; creating the room does not grant the invitee access.
Phone/contact creation retains default delivery. Deploy the backend support for
this field before releasing updated apps. No new migration is required.
