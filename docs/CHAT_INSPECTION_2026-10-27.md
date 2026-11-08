# Zo Stream chat inspection — 27 October 2026

## Scope and result

Reviewed the Laravel chat routes, authentication boundary, controllers, services,
schema, delivery/pruning commands, WhatsApp invitations, Vue moderation screens,
Android/iOS chat SDKs and the mobile chat/player integration paths. This is a
source inspection with local backend verification. It does not establish the
configuration, availability or capacity of the deployed service.

The main architecture is implemented and the existing backend tests have no
failures. The issues below concern lifecycle consistency, recovery and incomplete
cleanup. The original inspection made no application changes. The follow-up below records source fixes; no production deployment was performed.

## Findings, ordered by practical priority

### 1. Connected chat has no periodic transcript recovery (medium, source finding)

Locations: `Library/android/zoanalytics/src/main/java/in/zostream/analytics/ZoChatClient.kt:46`
and `Library/ios/Sources/ZoAnalytics/ZoChatClient.swift:175`.

The SDKs run a 30-second timer, but it only maintains socket/presence health.
They emit a fallback `sync` only when disconnected, at approximately one-minute
intervals. Heartbeat failures are discarded. The app screens fetch messages on
events/opening/reconnection; neither SDK periodically reconciles a connected
transcript. Room-list/block refreshes tied to `sync` have the same limitation.

If the delivery worker stalls while Reverb remains connected, a saved message
can remain absent from another viewer's open transcript until a later event,
reopen or reconnect. A missed deletion can remain visible. Automatic private
room expiry also need not remove the selected room promptly during a quiet,
connected session. The watch-control path has a heartbeat revision recovery
mechanism; the ordinary message transcript has no equivalent.

Recommendation: add bounded connected reconciliation, including access-error
handling, and test a healthy socket with a stopped delivery worker. The five-minute
unread snapshot is separate and does not itself refresh transcript contents.

### 2. Live private-room access disagrees with room discovery (medium, reproduced)

Locations: `app/Support/Chat/ChatService.php:12`,
`app/Http/Controllers/Api/V4/ChatController.php:252`,
`app/Support/Chat/ChatReadService.php:60`, and
`app/Http/Controllers/Api/V4/ChatInvitationLinkController.php:43`.

`assertInviteRoomActive()` explicitly allows a private room past its idle deadline
when a live `/watch` session exists. Room lists, unread candidate selection,
invitation listing/acceptance and invitation-link resolution use the older
deadline-only condition. The WhatsApp job uses that condition too.

Probe: create a live `/watch` session and move the private room's deadline into
the past while keeping the host stream fresh. A member's join returns 200, but
the room list and unread summary omit that same room. Mobile room restoration
can consequently discard a usable room/tab. Steady host heartbeats normally
renew the deadline; interruption/recovery and older clients can expose the
inconsistency.

Recommendation: share one lifecycle predicate across authorization, discovery
and invitation handling, with tests for a live session after the idle deadline.

### 3. Account erasure leaves invitation and watch identities (medium, reproduced)

Location: `app/Support/Chat/ChatService.php:177`; called by
`app/Http/Controllers/OTPController.php:733`.

Message text/display names are erased and sender IDs anonymized. Reads,
blocks, bans, owned reports and queued personal badge events are cleared.
However, deletion does not clean `chat_invite_rooms.host_user_id`,
`chat_room_invites.inviter_user_id` / `invitee_user_id`,
`chat_watch_sessions.host_user_id`, or `chat_watch_participants.user_id`.
Stored `chat_messages.watch_invite` JSON also remains behind the tombstone.

Probe confirmed that deleting Alice's chat data leaves her UID in the room,
invitation and watch-session rows. The prune command has no general cleanup for
old invitation/room records, so those references need not age out automatically.

Recommendation: define which relationship/audit records must remain, anonymize
or remove other user references, terminate hosted sessions, and notify affected
clients. Extend deletion tests beyond messages and blocks.

### 4. Numeric invitation acceptance can succeed for a closed room (medium, reproduced)

Location: `app/Http/Controllers/Api/V4/ChatController.php:320`.

`respondInvite()` checks pending status, invitation expiry and invite-room
activity, but does not join/check `chat_rooms.closed`. The link-based endpoint
does check it. Probe: close a room with a pending invite, then accept through
`POST /invites/{id}/respond`. It returns `accepted: true`; joining immediately
afterwards returns 403. This does not grant chat access, but persists a misleading
membership transition and produces a broken user flow.

The numeric path also reads the pending invitation before an untransactional
conditional update and does not check affected rows. Competing accept/decline
requests could report success inconsistent with the winning state; this race
was identified in source, not exercised concurrently.

Recommendation: lock and validate invitation/room state in a transaction, share
the link endpoint's checks, and return the actual persisted response state.

### 5. Chat feature flag is not enforced consistently (medium, reproduced)

Locations: `ChatController.php:63`, `:290`, `:320`, `:593`, and `:632`.

Join/send/unread check `chat.enabled`, but several other routes do not.
Probe confirmed that after setting it false, numeric invitation acceptance and
block writes still return 200. Lookup and invitation/block reads also have no
central feature-flag guard. Admin moderation may reasonably remain available,
but that should be an explicit policy separate from viewer APIs.

Recommendation: enforce viewer availability in shared chat middleware, document
any administrative exceptions, and test the flag across all viewer endpoints.

### 6. Admin episode titles use the wrong table (low, reproduced)

Location: `app/Http/Controllers/Api/V4/AdminChatController.php:55`.

`titles()` maps `episode` to table `episode`; chat playback resolves episodes
from `episodes`. Probe populated `episodes` and requested admin room history:
the room title fell back to `episode:ep-a` rather than the real episode title.
The lookup also lacks `ChatContent`'s numeric-key fallback. Private rooms appear
under generated room keys rather than their human room names, and there is no
explicit Invite filter in the admin room/metrics selectors.

Recommendation: reuse consistent content resolution and include invite-room
names/types in the admin presentation.

### 7. Existing socket authorization has a metadata revocation limit (design limitation)

Locations: `app/Console/Commands/DeliverChat.php:66` and the SDK subscription loops.

History and message bodies are reauthorized by HTTP. Current socket events also
carry sender/message IDs, badge eligibility and watch playback/count snapshots.
Private-channel authorization happens at subscription time. Bans/expired playback
do not actively evict an already authorized socket in the inspected code.
Therefore HTTP denial protects message text, while an existing subscription can
continue receiving these metadata frames until it unsubscribes/disconnects.

This was assessed from the publish/auth paths; no live Reverb revocation test
was run. If immediate metadata revocation is required, use server-driven channel
eviction or subscriptions with an enforced lease/revalidation policy.

## Structure

| Layer | Main implementation | Responsibility |
| --- | --- | --- |
| Routes | `routes/api_v4.php:168` | Authenticated chat API and route-specific throttles |
| Trusted identity | `AuthTokenMiddleware` | Resolve token; overwrite supplied auth identity |
| Chat API | `ChatController` | Rooms, messages, invites, reports, blocks, host controls |
| Link API | `ChatInvitationLinkController` | Hash token, verify recipient, show/respond |
| Authorization/cache | `ChatService` | Room access, bans/mutes, playback, presence, outbox |
| Read state | `ChatReadService` | Unread summaries, account/context channels, monotonic reads |
| Watch sessions | `PrivateWatchService`, `WatchPlaybackState` | Stream binding, participant leases, sequenced controls |
| Persistence | `database/migrations/*chat*` and watch-control migration | Rooms, messages, memberships, reads, moderation, outbox |
| Delivery | `DeliverChat`, `SendChatInvitationWhatsApp` | Reverb, independent analytics, queued WhatsApp |
| Retention | `PruneChat`, scheduler | 30-day history, up to 90-day pending-report retention |
| Admin | `AdminChatController`, `ChatPage.vue`, `ChatAnalyticsPanel.vue` | Moderation review, restrictions, audit, operational/usage metrics |
| SDKs | `Library/android/.../ZoChatClient.kt`, `Library/ios/.../ZoChatClient.swift` | HTTP envelopes, Pusher protocol, auth, reconnect, callbacks |
| Android UI | `ZoStreamNew/.../chat/ChatDialog.kt`, `ChatUnreadTracker.kt`, invitation/launcher classes | Transcript, rooms, visible reads, cards, player routing |
| Apple UI | `Zo Stream/Pages/ChatView.swift`, `PlayerView.swift` | View model, transcript, unread store, invitations, player sync |

The backend intentionally uses query-builder services rather than chat Eloquent
models. Android's chat dialog is about 1,880 lines; Apple's chat file is about
2,770 lines and combines several stores/views. Both are maintainability hotspots:
history, membership and playback state are sufficiently distinct to extract
incrementally when changes are made.

## End-to-end behavior

### Open and receive

1. Home opens Global; online player chat opens the current movie/episode context.
2. Auth middleware resolves account/device. Content rooms additionally require
   a matching active playback token with stream freshness within three minutes.
3. Join creates/reuses the public room or validates accepted private membership.
4. API returns room/channel/WSS information; SDK obtains socket ID and a signed
   server-generated subscription authorization. Secrets stay server-side.
5. History loads in pages of 50. Exclusive `before`/`after` cursors support older
   history and catch-up. Clients merge by message ID, preserve scroll position,
   and can begin at the first unread boundary.
6. `chat.changed` prompts authorized history fetches; watch snapshots can be
   applied directly. SDKs answer Pusher pings and reconnect with backoff.

### Send and moderate

1. UI sends UUID request ID, plain text and spoiler/session metadata.
2. Server checks membership/playback/restrictions and validates up to 1,000
   characters. A shared atomic cache key imposes two-second account slow mode.
3. Message and outbox row commit together. Unique `(user_id, request_id)` makes
   retry idempotent. Private sends serialize against room termination.
4. `chat:deliver` publishes a lightweight event. Broadcast and analytics retries
   have separate progress/backoff. Message bodies are absent from socket events.
5. Reports deduplicate by reporter/message. Admin delete produces a tombstone;
   mute denies sends, ban denies reads/auth/sends, and actions create audit rows.
   Blocking filters what the blocker sees; it is not reciprocal room removal.

### Private rooms and watch together

1. Creator names a room and chooses invitees through phone/account lookup.
   Each create/add request accepts 1–20 invitees; this is not a total room cap.
2. Room creation persists memberships and hashed, per-recipient 64-character
   invitation tokens. Pending invites expire after 24 hours. Accepted membership
   remains usable subject to room state.
3. Notifications use in-app invitation retrieval, FCM where a token exists, and
   optional encrypted queued WhatsApp jobs. Provider outages do not roll back
   a committed room; external delivery is not an exactly-once guarantee.
4. Plain private rooms have a one-hour idle deadline, extended by new messages;
   the host can end them permanently. A live `/watch` session also affects
   availability, with the discovery inconsistency described above.
5. Dedicated content watch rooms require the host to keep watching. The `/watch`
   command instead adds a watch card to an existing private room, preserving it
   as a general chat room. Only its creator's active player may start/control it.
6. Premium participation requires subscription checks; PPV watch invitations
   are rejected. Playback still starts through the existing playback API.
7. Host position/pause/seek updates carry increasing control sequences. Stale
   sequences are ignored. Guests receive extrapolated position and paused state
   over the private channel, with baseline recovery and minute heartbeats.
8. Counts distinguish unique joined invitees from recently streaming invitees;
   both exclude the creator. Streaming uses stream identity and a 150-second
   participant lease. Replaced/stopped host streams invalidate watch sessions.

### Unread and lifecycle

Unread counts exclude own, blocked, deleted and expired messages. A separate
socket observes authorized account/context/private channels without joining
room presence. Clients apply deduplicated deltas and account-private read
snapshots, buffer events across baseline requests, and periodically reconcile
counts (five minutes while connected; around one minute disconnected).

Visible messages advance monotonic per-account/per-room read cursors, shared
between devices. Read retries are stored locally per account. Background/logout
and screen disposal close connections; hidden player chat can keep its watch
control subscription. Portrait/landscape player integration and spoiler,
report/block, invitation and retry UI paths are present in both mobile sources.

## Operations and capacity considerations

- Required components: primary database, Redis/cache, Reverb behind TLS,
  supervised `chat:deliver`, Laravel queue worker for WhatsApp, and scheduler.
- `CHAT_ENABLED` defaults false; `CHAT_WHATSAPP_ENABLED` is separate. Deploy all
  chat/watch/read/badge migrations before apps and restart long-lived workers.
- Redis read failures generally fall back to DB. Mutation throttle/send failures
  fail closed; presence can return zero. This preserves service boundaries but
  means Redis availability directly affects chat writes.
- Outbox publication processes batches sequentially and can spend up to the HTTP
  timeout per failed event. One event per message fans out to many history fetches
  from viewers. Cache reduces DB reads, but HTTP/socket capacity and queue delay
  require a real load test; no capacity number is established by this audit.
- Private room lists and unread candidate lists are unpaginated. Invitation/room
  metadata and undelivered outbox rows have no broad age-based cleanup in the
  inspected prune command. Plan cleanup and observability before large growth.
- Docs have drift: `CHAT_MOBILE_PARITY.md` still describes ten-second unread polls
  and no pause/play sync; `CHAT_MOBILE.md` still describes events as containing
  only kind/message ID and a connected 30-second transcript reconciliation.
  Current code implements richer badge/watch events and disconnected fallback.

## Verification and limits

- Ran `php artisan test --filter='Chat(Api|QueueMigration)Test'`: **68 tests,
  679 assertions, no failures**. Runner classified tests as deprecated because
  of PHP 8.5/framework/config deprecations.
- Ran five isolated audit probes in `/private/tmp/ChatInspectionProbeTest.php`:
  **5 tests, 21 assertions**, confirming findings 2–6. They assert observed
  behavior, not corrected behavior. These are outside the application test suite.
- Probes reused the existing in-memory SQLite fixture. Its feature tests mostly
  bypass token/admin middleware; existing explicit auth tests cover those routes.
  Redis concurrency, MySQL locking and real delivery were not exercised by probes.
- Attempted Swift SDK `ChatClientTests` with temporary build/cache directories.
  Execution failed before tests due to local module-cache permissions and a
  reported Swift compiler/SDK version mismatch. No Swift test pass is claimed.
- Android tests/build, Vue production build, device rendering/accessibility,
  live two-account sockets, WhatsApp/FCM delivery and production deployment
  settings were not run/verified in this inspection.

Recommended next work: fix lifecycle/recovery and erasure first, centralize
invitation/feature-flag checks, fix admin titles, then verify both mobile apps
with a stalled delivery worker, reconnects, cross-device reads and long-running
watch sessions. Keep production deployment separate from these source findings.


## Fix follow-up — 27 October 2026

Implemented source fixes for all seven findings:

- Android/iOS connected transcript reconciliation and access-denial cleanup.
- Shared live-watch-aware lifecycle checks for discovery, unread, numeric/link
  invitation handling and WhatsApp delivery.
- Transactional account erasure covering hosted rooms, watch sessions,
  invitation identities, participants, watch-card JSON and related block caches.
- Transactional invitation responses with closed-room validation and consistent
  lock order shared with link responses and room membership changes.
- Central viewer feature-flag enforcement, retaining administrative moderation.
- Correct episode/numeric content titles, private-room names and Invite filters.
- Server-enforced leased publication channels with mobile renewal. Revocation
  of metadata is bounded to six minutes; it is not instantaneous socket eviction.

The earlier findings above describe the inspected pre-fix behavior. Deployment
compatibility and verification instructions are in `docs/deploy/CHAT.md` and
`docs/CHAT_MOBILE.md`. API/worker and both updated mobile builds must be released
together; old permanent-channel SDKs cannot receive the leased broadcast stream.


### Follow-up validation results

- Chat/queue-migration/WhatsApp template tests: 77 tests, 764 assertions, no failures.
- V4 authentication/route/account-deletion entry-point regressions: 18 tests,
  502 assertions, no failures. Combined backend verification: 95 tests and
  1,266 assertions. PHP 8.5 deprecations remain unrelated to these fixes.
- Android SDK: 12 tests across ChatRoomSwitchTest and ChatListPositionerTest,
  no failures. Includes lease rotation, stale-frame rejection and connected
  reconciliation/access-denial behavior.
- Android UI: 22 tests across ChatHistoryTest and ChatUnreadTrackerTest, no
  failures. Includes a new recovery test for missed creation/deletion events.
  Robolectric animations now use paused finite frames, with explicit frame
  advancement before layout/dialog assertions, preventing infinite idle loops.
- Swift SDK build passes; the Apple chat screen passes syntax parsing. Five
  existing Swift chat test methods also pass through a temporary standalone
  assertion runner. Full XCTest cannot run because this command-line developer
  installation has no XCTest module. No full iOS app/device test pass is claimed.
- Vue admin production build passes (temporary output outside the source tree).
- Diff whitespace checks pass in all three affected repositories.

No production changes, migration execution or app release were performed.
Actual devices, live Reverb/Redis/MySQL concurrency and provider delivery still
need staging verification using the deployment guide. Load capacity is not
established by these unit/feature tests.
