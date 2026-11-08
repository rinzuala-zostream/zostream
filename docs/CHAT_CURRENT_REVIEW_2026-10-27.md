# Current chat implementation review — 27 October 2026

Reviewed the current working trees, including the existing uncommitted chat fixes.
This supplements `CHAT_INSPECTION_2026-10-27.md`: its original findings and
follow-up describe earlier states. This review changes documentation only.

## Findings still present

### 1. Access denial can be undone by an in-flight history result — medium

Android: `ZoStreamNew/app/src/main/java/com/buannel/studio/pvt/ltd/zostream/chat/ChatDialog.kt:816`
and `:777`; SDK `Library/android/zoanalytics/src/main/java/in/zostream/analytics/ZoChatClient.kt:80`.
Apple: `Zo Stream/Pages/ChatView.swift:812` and `:918`.

The access-denied handler clears the transcript but does not advance the UI's
request generation/history epoch or cancel the history operation. The Android
SDK advances its room revision on revocation, but successful generic request
callbacks are guarded by account identity, not that revision. An already
authorized history response can therefore repopulate the cleared transcript
after access is denied. This is stale response application; no new unauthorized
HTTP access was demonstrated.

An Android Robolectric probe held a successful history response, revoked the
room, confirmed the empty transcript, then released the response and observed
messages reappear. The analogous Apple generation guard is a source finding;
it was not reproduced on an Apple device.

Recommended change: advance/cancel the transcript generation on denial, clear
the selected room and reject results from the prior access generation. Preserve
account-level operations separately.

### 2. Recovery does not reconcile older cached messages — medium

Android: `ChatDialog.kt:1550` and `:1600`.
Apple: `ChatView.swift:878` and `:902`.

Connected reconciliation fetches new messages with `after`, followed by the
latest page. Cached messages older than that page's minimum ID remain untouched.
If an older loaded message is deleted while its socket event is missed, its
original text remains visible through subsequent reconciliations. `hasNewer`
also prevents ordinary sync while the user is paging through unread history.

An Android probe loaded older history, changed an older server message to a
tombstone, triggered connected reconciliation and observed the old body remain.
Apple uses the same merge boundary; that part is source-inspected.

Recommended change: reconcile visible/loaded ID ranges or introduce a bounded
change cursor that includes deletions, rather than relying on a latest-page
snapshot to refresh all cached history.

### 3. Admin restriction expiry is stored without timezone normalization — medium

`resources/js/admin/pages/ChatPage.vue:95`,
`app/Http/Controllers/Api/V4/ChatController.php:667`,
`app/Support/Chat/ChatService.php:31`, and `config/app.php:94`.

The UI sends `new Date(...).toISOString()` (UTC). The query-builder write stores
that string directly; no model date cast normalizes it. Access checks compare
the stored value against application-local `now()` (Asia/Kolkata).

An isolated SQLite probe froze local time at noon, submitted a ten-minute ban
using the UI's ISO UTC format, advanced eleven minutes and confirmed that the
ban still denied history. SQLite retains the ISO separator/timezone and its
comparison does not implement the intended instant. Production MySQL handling
of this exact input was not verified; it must be checked with the deployed SQL
mode rather than assuming SQLite's result carries over.

Recommended change: parse the validated expiry and persist a canonical database
datetime in the same timezone used for comparisons; verify a restriction before
and after its deadline on the production database engine.

### 4. Stopped-host dedicated watch rooms remain discoverable — medium

`app/Support/Chat/InviteRoomLifecycle.php:13`,
`app/Http/Controllers/Api/V4/ChatController.php:243`, and
`app/Support/Chat/ChatService.php:46`.

For dedicated content invite rooms, the lifecycle predicate accepts any
non-null `content_type` without checking host stream liveness. The room list
uses this predicate, while actual room authorization requires a fresh active
host stream. A stopped-host room remains in tabs although selecting it returns
403. Unread discovery excludes it through the stricter authorization check.

A backend probe reproduced this list/join/unread disagreement. This concerns
dedicated content invite rooms, separately from `/watch` sessions inside plain
private rooms.

Recommended change: share host-liveness eligibility with discovery, or expose
an explicit unavailable state and render it consistently in both mobile apps.

### 5. Muting a dedicated watch guest also blocks playback-state reads — medium

`app/Http/Controllers/Api/V4/ChatController.php:455`.

The position endpoint calls `room(..., true)` even when a guest sends an empty
body solely to read the host's playback position. This applies mute's write
restriction to a read. A probe confirmed that a muted guest can join and read
messages, but receives 403 fetching position. Initial synchronization and
missed-control recovery can consequently fail although chat access remains.

Recommended change: authorize read-only position requests as reads; retain
host-only write authorization when control fields are supplied.

### 6. Admin chat timestamps are labelled UTC without conversion — low

`resources/js/admin/pages/ChatPage.vue:25` and
`app/Support/Chat/ChatService.php:161`.

The formatter performs string substitutions and appends `UTC`. Message
timestamps include their actual offset, so a value ending `+05:30` is displayed
with that offset followed by `UTC`. Raw query-builder timestamps have no offset
and are also labelled UTC although the application writes Asia/Kolkata times.
Moderation times and expiry displays can therefore mislead operators.

Recommended change: use the existing timestamp utilities and format an actual
instant in one explicitly chosen timezone.

## Structure and functional coverage

| Layer | Current implementation and role |
| --- | --- |
| API boundary | `routes/api_v4.php`; token identity, admin boundary and per-action chat throttles |
| Core backend | `ChatController`, `ChatService`, `ChatReadService`, `InviteRoomLifecycle`; access, history, idempotent sends, invites, reads, reports and restrictions |
| Watch backend | `PrivateWatchService`, `WatchPlaybackState`; bound host stream, guest participation, sequenced pause/play/seek and heartbeat recovery |
| Delivery | Transactional message/outbox writes; `chat:deliver` publishes Reverb changes and separately retries analytics |
| Notifications | Hashed recipient invitation links; queued WhatsApp and best-effort push notification calls |
| Retention | `chat:prune`; bounded message cleanup with pending-report retention |
| Admin frontend | Vue `ChatPage` and `ChatAnalyticsPanel`; rooms, history, reports, moderation, audit and metrics |
| Android frontend | `ZoStreamNew/.../chat/`; chat dialog, launcher, unread tracker, invite links and player routing; local SDK module in this API repo |
| Apple frontend | `Zo Stream/Pages/ChatView.swift` plus player/home integration; local Swift SDK package in this API repo |

Normal flow: Home opens Global; online player opens content chat. Join validates
access, returns channel configuration, and the SDK authenticates a leased
private subscription. HTTP history supplies message bodies. Send uses a stable
request UUID and commits the message with an outbox event. Socket changes cause
authorized HTTP catch-up; visible messages advance shared monotonic read cursors.

Both mobile sources include room tabs, invite acceptance, retry, pagination,
spoiler masking, report/block UI, unread badges, embedded player chat and watch
controls. Plain private rooms expire after one hour without new messages unless
a live watch session preserves access. Dedicated content invite rooms require
the host's active playback. PPV watch invitations are rejected, and premium
participation is checked server-side.

The desktop web admin is a moderation UI. Searches found no chat integration
references in the supplied web/TV workspaces; no claim of web/TV chat support is
made. Large mobile chat files still combine transcript, invitation, read and
watch state, making request-lifecycle changes harder to review.

## Verification performed for this review

- Backend chat, queue-migration and WhatsApp template suite: 77 tests,
  764 assertions, no failures; PHP 8.5 deprecation notices remain.
- Four isolated backend observation probes: 4 tests, 23 assertions, no failures.
  Three use `/private/tmp/ChatPostFixInspectionTest.php`; the expiry probe uses
  `/private/tmp/ChatCurrentReviewExpiryTest.php`. They assert the problematic
  current behavior, not a corrected implementation. The third pre-existing
  probe also confirms a closed room's watch snapshot can still report active
  playback; room authorization itself denies access.
- Android: 36 selected tests, zero failures/errors/skips: 22 existing app tests,
  12 SDK tests and two observation probes from the temporary
  `ChatPostFixAuditTest` source. The probes deliberately confirm findings 1–2.
- Vue production build passed with output in
  `/private/tmp/zostream-chat-review-build-20261027`.
- Apple `ChatView.swift` syntax parsing and the Swift SDK package build passed.
  The package build used temporary Swift/Clang caches after the default cache
  path was denied by the sandbox. An existing analytics `Sendable` warning
  remains. Full iOS app compilation and XCTest were not run.

The backend probes use SQLite and the existing fixture bypasses token/admin
middleware. Device layout, accessibility, live two-account Reverb behavior,
Redis/MySQL concurrency, notification delivery and production configuration
were not established by these checks. No service was deployed or migration run.

Release compatibility still matters: the current API/worker publishes leased
channels, requiring the updated mobile SDKs. Metadata revocation remains bounded
by a five-minute lease plus sixty seconds of grace. Undelivered outbox rows and
old invitation/room metadata also need an explicit cleanup/operations policy.
