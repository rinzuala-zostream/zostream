# Zo Stream chat architecture audit and cutover plan

Audit date: 2026-11-27

## Scope inspected

- Backend: `zostream-api/zostream` (Laravel API, chat migrations, routes, controllers, support services, queue commands, and Reverb configuration references).
- iOS mobile: `Zo Stream apple thar/Zo Stream` (SwiftUI chat, disk store, and chat API client).
- Android mobile: `ZoStreamNew` (native Kotlin chat, disk store, history sync, and Firebase Messaging service).
- Desktop: `Zo Stream Desktop` (macOS Swift and Windows .NET chat, disk stores, history sync, socket pools, and Watch Together sources).
- Separate Android TV and web/player repositories were identified but not modified. Their exact release compatibility and product support status need confirmation from repository ownership/release metadata before any cutover.

No production records, Firebase settings, or client source were changed during this audit or the initial implementation phase below.

## Current architecture

Chat is currently SQL-authoritative, with Laravel handling room authorization, message history and writes, reactions, typing, read state, moderation, and notification decisions. A SQL outbox feeds Reverb delivery and analytics/notification work. Clients authenticate using existing Zo Stream credentials, call the v4 API, and use Reverb for realtime chat events. Clients also keep account-scoped persistent disk caches and incrementally refresh history through the API; those caches are not the primary transactional database described by the target design.

The backend already uses Firebase Admin SDK in unrelated features (including app updates, payments, QR sessions, and realtime admin configuration). At audit time, no chat RTDB schema, client RTDB access, chat Security Rules, or safe chat membership mirror existed.

## Existing feature inventory

Observed backend routes/services and client UI include:

- Global, movie/episode, and private/invitation rooms; room discovery, creation, invitations and invitation links, invite responses, join/leave/end lifecycle, and membership restrictions.
- Text messages, replies/previews, reactions, edits/deletes where authorized, pin/unpin, pagination, room history, movie suggestions, and room invitation message cards.
- Read positions, unread summaries/badges, typing snapshots, blocking, reporting, bans/mutes, moderation, admin audit/report/metrics endpoints, and push notifications.
- Watch Together chat and associated watch sessions, participants, host/guest controls, playback state, watch-status events, and private watch endpoints.
- Client UI has local message history, older-page loading, cached unread state, identity/block filtering, reconnect and realtime lifecycle logic. Android/iOS/macOS/Windows each have a disk store/history sync implementation.
- Reverb is used for chat channel authorization, event delivery, and socket protocol/presence handling. A delivery worker/outbox and scheduled prune command also exist.

This inventory is based on the inspected API routes, Laravel chat service/migrations, and named native chat modules; it is not yet a line-by-line behavior matrix for every UI action and every client release.

## Important discrepancies and risks

1. **Presence exists in the current backend.** `ChatService::presence()` maintains a Redis per-room heartbeat counter, room join returns `online_count`, and routes expose room heartbeat/leave behavior. This contradicts the request's assertion that online/offline presence is not implemented. It is currently internal room occupancy behavior; removing or reclassifying it could change current UX and is not included in this first phase.
2. **Authorization is relational and context-sensitive.** Access may depend on SQL membership/invitation state, bans, playback tokens and active streams, subscription status, room closure/expiry, and admin status. Direct RTDB rules cannot call Laravel or MySQL. A secure RTDB rollout needs a carefully maintained authorization projection, including prompt revocation, with trusted-only writes and tests for all these conditions.
3. **Stable identity mapping is straightforward; session revocation is not.** Zo Stream user IDs are already available to authenticated API middleware. A Firebase UID can be derived from a stable, opaque mapping, but issuing a token alone does not revoke already exchanged Firebase ID tokens. Revocation behavior must be represented in rules-accessible state and tested across logout/account switch.
4. **A uniform RTDB event cursor is not present.** The SQL outbox has event kinds including created/deleted/sync, reactions, typing, read, room lifecycle, watch state, and targeted badge updates. Client reconciliation also makes API calls for changes. The event payload and retention model must cover all cached-message mutations before replacing those refresh paths.
5. **Room identifiers are SQL numeric IDs and are used across clients and APIs.** Replacing them or creating fresh room configuration would affect invitations, app links, movie chats, analytics, and Watch Together. Keep these identifiers stable unless a separately reviewed compatibility migration says otherwise.
6. **Current cache is persistent but not a normalized transactional database.** Android, iOS/macOS, and Windows have different disk-store implementations. Adapting them to local-first UI observation is a multi-client change, not a backend-only cutover.
7. **Reverb has shared responsibilities.** Playback controls and Watch Together room state are present in chat-related outbox/event code. Reverb connection/subscription removal must be verified per client against Watch Together channels before any legacy chat subscription is disabled.
8. **Existing versions and rollout boundary are unknown.** No deployment/version policy was established in this code review. Since the new database intentionally has no historical messages, old and updated clients cannot silently use separate systems without fragmenting room conversations.
9. **Firebase cost cannot be guaranteed at USD 0–1.** It depends on the project's current plan, region, connections, downloads, notification infrastructure, and how the proposed event/membership indexes are maintained. No billing/usage baseline was available in source.

## Initial implementation plan

### Phase 0 — audit and contracts

- Complete a behavior matrix from each supported client's screens/actions and backend routes, including moderation, invitations, pins, notification preferences, and Watch Together.
- Confirm the supported release matrix and whether Android TV, tvOS, browser, and any older clients remain in service.
- Capture current production request/CPU/query/Reverb and Firebase billing baselines where observability access exists; otherwise label unavailable measures.
- Define the stable RTDB wire schema, local database migrations, event cursor/recovery semantics, message ID format, and old-client rollout policy.

### Phase 1 — trusted backend and Firebase security foundation

- Add a rate-limited, authenticated Custom Token endpoint with stable UID mapping only after choosing an explicit revocation/session model.
- Define a trusted authorization projection for rooms/members, with SQL as the source for privileged policy and a transactional/outbox mechanism to update RTDB mirrors. Clients must never write membership or event indexes directly.
- Add deny-by-default RTDB rules and emulator tests before enabling any client database access. Cover private room metadata, messages, reactions, reads, event writes, removed members, bans, and token revocation.
- Define server-side moderation and notification handling for direct Firebase message writes; do not bypass block/mute/slow-mode/report policy.

### Phase 2 — local data and synchronization protocol

- Specify normalized local tables and schema versioning independently for Android, iOS, macOS, and Windows, with per-account isolation and logout cleanup.
- Implement idempotent event application, revision/tombstone handling, cursor recovery, initial-history/listener race avoidance, and durable pending operations.
- Keep existing Laravel routes available during validation. No historical SQL messages are copied to RTDB, per the requirement.

### Phase 3 — supported clients and feature parity

- Integrate Firebase Custom Token auth and RTDB SDKs on Android/iOS and platform-appropriate desktop integration.
- Make local storage the UI source; move supported realtime chat features to RTDB while retaining Laravel for trusted room administration, moderation, and notifications.
- Preserve UI and Watch Together playback channels. Move Watch Together text messages only after parity and version compatibility checks.
- Run the feature and security regression matrix on each supported client; excluded clients get no architecture changes.

### Phase 4 — controlled rollout and retirement

- Release using an explicit minimum version or compatible feature flag strategy; avoid two independent histories for communicating users.
- Monitor SQL/API/Reverb load, RTDB bytes, storage, active connections, rejected writes, cursor recovery, and push delivery.
- Disable chat broadcasts only after adoption and parity criteria are met. Retain Reverb playback. Document and rehearse rollback before production cutover.

## Initial implementation progress (2026-11-27)

- Added a versioned `chat_v1` schema while retaining numeric SQL room IDs and starting with no historical-message migration.
- Added combined deny-by-default RTDB rules, preserving the pre-existing Firebase paths, plus an Emulator Suite test harness.
- Added a Laravel Custom Token endpoint for Android, iOS, Windows, and macOS, authenticated by the existing Zo Stream session and disabled by default (`CHAT_FIREBASE_ENABLED=false`). Session leases are capped at five minutes and logout attempts immediate revocation.
- Added a separate `client_access` rules gate. Both feature gates remain false by default. The projector sets a session-scoped grant only when trusted backend configuration allows it.
- Added an API feature test and emulator rules tests. These are foundation checks, not the complete cross-platform regression matrix.
- No client source, Firebase production configuration/data, production rules, Reverb delivery, or legacy API behavior was changed.

## Backend implementation status (2026-11-27)

- Added a session- and room-scoped projector that delegates room reads to the existing SQL policy and projects expiring rules-readable grants with trusted Admin SDK writes. Joins and token refreshes can renew leases; logout, leave, block, ban, room close, account deletion, and session expiry revoke or disable access. A reconciliation/retry policy still needs staging validation against real Firebase and deployed SQL migrations.
- Direct RTDB writes for messages and mutations remain denied. The existing Laravel API remains the trusted submission path, so accepted SQL messages retain slow-mode, moderation, reply, identity, and idempotency checks. The outbox allocates per-room cursors and retries trusted publication; the recent feed and event are atomically written to per-user paths after current SQL eligibility and block checks.
- Room-wide message reads remain denied because they cannot enforce per-sender blocks. Per-user message and event projections are bounded and retained for seven days. This trades bandwidth and storage proportional to eligible recipients for server-enforced visibility.
- Added bounded cursor sync and expiry recovery, including changed/hidden message reconciliation and latest-30 visible messages. Current event projection handles message feed create/delete, reactions, pins, reads, targeted invite status, and room-ended revocation. Full room metadata/discovery and membership badge parity remain on existing SQL APIs; no RTDB client cutover is represented as complete.
- Existing FCM notification delivery remains on the SQL accepted-message pipeline; the Firebase mirror does not send duplicate notifications.
- Added a database migration for revisions, cursors, delivery claims, and per-user pruning indices. It has not been applied to a live database. Deployments must apply `chat:migrate`/the repository's chat migration mechanism before enabling the worker.
- Both `CHAT_FIREBASE_ENABLED` and `CHAT_FIREBASE_CLIENT_ACCESS_ENABLED` remain false. No frontend source, production Firebase settings/data, or deployed rules have been changed.

## Remaining blockers before client integration or rollout

The existing four client families have not been updated and still use SQL/Reverb. A detailed behavior matrix, desktop SDK/transport validation, and a compatible release/version policy are still required before moving realtime consumption. The backend does not yet replace complete room discovery, unread badges, typing, or all room metadata mutations with Firebase projections; these remain on existing API/Reverb paths. Runtime Watch Together regression has not been performed. The new SQL migration and trusted delivery need integration testing in staging with a Firebase Admin identity, real authorization data, and failure/retry observation. Firebase cost cannot be estimated to the requested USD 0–1 target without audience and billing measurements.

## Backend feature parity matrix (2026-11-27)

| Existing feature | Current authoritative implementation | Firebase-compatible path in this phase | Client behavior required | Reverb after future cutover | Automated coverage / remaining blocker |
|---|---|---|---|---|---|
| Global/movie/episode/private rooms, discovery, invitations | Laravel SQL room/invite APIs and authorization | Session/room authorization projection; `user_rooms` is currently a joined-room cache only | Continue SQL discovery and join/leave; project only after successful join | Not replaced for discovery metadata | Existing ChatApiTest covers routes; projector policy parity needs Admin SDK integration tests |
| Text, replies, movie suggestions, room invitation cards, moments, Watch Together invitation cards | Laravel SQL message API; SQL validates request UUID, room rules, replies and structures | Trusted outbox projects accepted snapshots to eligible per-user feed | Submit through API; deduplicate by SQL ID/request UUID; merge by revision | Reverb remains current delivery; Watch Together remains required | ChatApiTest regression; no Firebase Admin pipeline fault tests yet |
| Message edits/deletes/tombstones | Laravel moderation/message mutations and SQL revision | Feed snapshot/tombstone and sync cursor; recent cache only | Apply greatest revision; reconcile all local IDs in batches of 100 | Reverb current; not disabled | Existing moderation/API tests; cursor expiry tests incomplete for >100 retained IDs |
| Reactions | Laravel API + SQL reaction tables | Cursor event; fetch/decorate canonical reaction through API | Apply event once and refresh message interactions | Reverb current | Existing ChatApiTest coverage; Admin publication untested |
| Pins | Laravel pin endpoints/SQL | `sync` event with message ID; bounded pins endpoint remains canonical | Refetch pins or reconcile after cursor expiry | Reverb current | Existing pins regression; event path not integration tested |
| Read positions/unread badges | Laravel read/unread SQL endpoints and targeted Reverb badge events | SQL read events plus per-room `unread_delta` for currently authorized recipients; no complete account-scoped global projection | Keep SQL unread summary baseline and existing API | Reverb still required for complete live badges | Chat API and Admin emulator message delivery; account-wide parity remains a blocker |
| Typing indicators | Laravel typing API/state and filtered Reverb events | Trusted, block-filtered per-recipient expiring `user_typing` nodes; coalescing remains in existing endpoint | Existing typing API with Firebase listener when enabled | Reverb can be removed for this feature after mobile listener integration | Admin SDK emulator verifies scoped delivery, expiry cap and stop cleanup; revoke/logout cleanup hooks added |
| Moderation, reports, bans/mutes, blocks | Laravel admin/moderation, block SQL state; revocation hooks for Firebase session/feed | Block clears user branch/disables reads; ban/close revokes room grant; message fan-out filters current blocks/bans | Continue moderation APIs; purge locally hidden IDs after sync | Reverb current notifications remain | Existing API tests + static rule tests; block/unblock/revoke races need integration tests |
| Room metadata, membership changes, invitation status, lifecycle | SQL/invite services and Reverb outbox | Ended room revocation and targeted invitation event partly supported; full metadata/member projection absent | Continue SQL discovery and invitation APIs | Reverb remains required for remaining changes | API regression exists; projection coverage incomplete |
| FCM notifications/preferences/deduplication | Existing accepted-message SQL outbox and notification services | Reused unchanged; Firebase mirror emits no FCM | Existing registration/preferences/navigation behavior | Independent of Reverb | Existing notification/API tests; no new duplicate path introduced |
| Watch Together playback, participants, host controls and room occupancy | Existing SQL/Redis/Reverb playback services | Only message cards appear in trusted Firebase recent feed | Keep playback transport/state on current path | Reverb remains required exclusively for Watch Together only after other rows complete | Existing API tests; runtime multi-client regression not run |

This matrix records partial backend compatibility, not client readiness. Feature coverage in legacy API tests does not prove equivalence of the new Firebase publication path.

## Verification status

- Repository/source audit: partial, across backend and primary iOS, Android, macOS, and Windows chat modules.
- Code changes: backend/schema/rules/test foundation only; no client changes.
- Verification after typing and unread-delta changes: Chat API + migration suites pass (163 tests, 1,536 assertions); Watch Together-related feature suites pass (193 tests, 1,621 assertions); the Admin SDK Database Emulator suite passes (4 tests, 27 assertions); Firebase Rules tests pass in the emulator (6 tests, including the cursor-start listener boundary). Full Laravel suite: 746 tests, 5,662 assertions, 1 failure, 4 skipped, 5 deprecation notices. The one failure is `RedemptionCodeTest::test_expired_cancelled_and_unpublished_codes_cannot_activate`: the third assertion expects 422 but receives 200. Code tracing found that `RedemptionService::grantToCategory()` validates PPV availability through `target()`, while `grantToDevice()` skips that target validation for PPV, allowing an existing device to redeem against a Draft/unpublished title. Recommended separate fix: call the same catalog validation in the device grant branch before creating payment/access state, and retain this regression test; this unrelated service was not changed in the chat task.
- Firebase rules static tests pass (5 passed; emulator case skipped unless the emulator host is configured). Firebase Database Emulator behavior run passed 6/6, including session-scoped chat authorization, trusted-write denial, preserved public warning reads, and QR payment amount write. This validates rules, not the Laravel Admin SDK publisher. Production rules not published or verified.
- Production performance/cost measurements: unavailable from source inspection.
- Watch Together compatibility: dependencies identified, not runtime-tested.

## Final readiness review (2026-11-27)

**NOT READY — BACKEND FIXES REQUIRED.** The trusted Kreait publication pipeline now has passing isolated RTDB emulator tests for accepted messages, per-recipient publication, tombstones/revisions, blocked recipients, session projection/renewal/revocation, and typing delivery/cleanup. Failure/retry/concurrency, room metadata/membership synchronization, full account-scoped unread projection, and actual request/SQL/cache/CPU load benchmarks remain incomplete. Ordinary chat still depends on Reverb for complete live unread badges, room metadata/discovery/membership and existing delivery during this compatibility phase; Watch Together remains on Reverb by design. Corrected ideal fan-out counts use `400 × ceil(recipients/100)` calls/day, but payload and throughput estimates remain modeled rather than measured. No production rules/config/data or Firebase gates were changed.

This backend-only phase added a lease renewal endpoint that does not mint a Custom Token, includes a pre-query history cursor to close the history/listener race, documents batched recovery for local caches above 100 messages, and adds compatibility assertions for existing public Firebase config and QR payment paths. Those changes do not close the blockers above.
