# Server CPU root cause analysis

**Scope:** source inspection correlated with the supplied 100,000-request Nginx sample, PostgreSQL counters and 30 PHP-FPM slow traces. The supplied aggregates are evidence of workload and elapsed latency; they are not CPU profiles. No production host, Nginx log lines, PostgreSQL session, `pg_stat_statements`, EXPLAIN plans or PHP-FPM trace files were available in this workspace. Findings below distinguish what the source proves from what needs production measurement.

## Executive assessment

The clearest source-level CPU candidate is `AlsoLikeController::fetchMovies()`: it loads every qualifying movie as full Eloquent models, converts them all to arrays (including casts/date serialization), then runs repeated PHP string similarity and genre/category passes. This is a strong per-request work multiplier consistent with the slow traces, but total CPU contribution remains unmeasured. The sample says recommendations had 506 requests; the 55,000 message GETs and other chat calls may be more important in aggregate if their per-request work or client polling rate is high.

The chat implementation already has cursor pagination, a configured page cap, idempotency keys for message creation, Redis-backed presence and short-lived caches. It is therefore inaccurate to conclude that every chat request reloads unbounded history. Conversely, message fetches can still execute interaction/reply/watch hydration work, unread snapshots aggregate message rows, and invite listing has an unbounded result set. The November 26 Reverb refusal is a confirmed availability incident and plausible source of reconnect/REST amplification, but the evidence does not establish that it caused CPU saturation.

PostgreSQL sequential-scan counters alone do not establish missing indexes. The initial chat migration defines `(room_id,id)` on messages and `room_key` unique on rooms; reactions have a primary key beginning in `message_id`; room reads have unique `(user_id,room_id)`; pins have unique `(room_id,message_id)` plus `(room_id,created_at)`. Counters are cumulative and may span restarts or resets. Check actual indexes, sizes, reset timestamp, SQL and plans before proposing DDL.

## Evidence and unresolved questions

| Evidence | What it supports | What it does not establish |
|---|---|---|
| 55,000 `/chat/rooms/1/messages` requests in a 100k sample | Endpoint dominates request count; investigate request origin and interval | CPU cost, users, duplicate polls, status, bytes or duration |
| 11,157 unread; 4,051 invite rooms; 2,266 pins; 791 joins | Significant chat request volume | Per-request query count/cost or whether calls are user-visible |
| 272,277 room seq scans / 1.469B tuples; 223,932 message seq scans / 166M tuples | Worth examining table access patterns | Missing indexes; counters may predate current deployment/reset and tuple counts do not prove expensive current plans |
| 30 timestamped slow traces: catalog/recommendations 14, auth 11, WhatsApp 3, analytics 2 | These routes have elapsed-time outliers | CPU attribution: waits, external I/O and serialization time are included |
| Reverb refused connections at 2026-11-26 06:01:13 UTC | Laravel endpoint was not accepting connections at that instant | Duration, cause, reconnect volume, or causal link to all CPU saturation |

For the Nginx sample, collect method, status, `$request_time`, `$upstream_response_time`, `$body_bytes_sent`, timestamp, request ID and a privacy-safe user/device identifier. Group by route + method + status and report p50/p95/p99, bytes, unique users, requests/user/minute, repeated identical queries and time-window distribution. Compare Nginx request IDs with Laravel traces and database query logs. Do not retain raw phone numbers or bearer tokens in diagnostic dimensions.

For PostgreSQL, collect `pg_stat_database.stats_reset`, server restart time, table sizes (`pg_total_relation_size`), `pg_stat_user_tables` snapshots as deltas over a known interval, `pg_indexes`, `pg_stat_statements` calls/mean/max execution/rows/buffers/temp blocks, and representative `EXPLAIN (ANALYZE, BUFFERS)` for the exact production SQL. Use a safe read-only replica or carefully sampled plans where production execution cost is a concern. Capture separate main and chat database configuration: `ChatDatabase` can use a separate connection.

## Source-confirmed findings

### P0 — recommendation serialization and generation

`app/Http/Controllers/New/AlsoLikeController.php`, `alsoLike()`, `fetchMovies()`, `findBestMatch()`, `filterSequels()`, `filterGenreAndCategory()` and `getRecommendations()`:

- `fetchMovies()` does an unbounded `get()->toArray()` over all enabled, published rows with only optional age/kids/Mizo predicates. This hydrates full `MovieModel` instances then serializes all columns; `toArray()` invokes casts/date serialization.
- Recommendation matching scans all candidates with `similar_text`; it scans again for sequel matching and again for genre/category, then may shuffle and scan the full collection for fallback. This is O(N) passes with relatively expensive string comparison.
- Result list is capped at 20 only after the full collection work. Transformation removes playback URLs only after retrieval and still sends other model fields. This makes result size smaller than work and may leave excess payload fields.
- `app/Http/Controllers/Api/V4/CatalogController.php`, `recommendations()` first retrieves a full model by id/num, then delegates to this code. The `orWhere(num)` lookup should be reviewed against indexes and grouping/uniqueness; no query plan was available.
- Current logic includes user/Mizo selection, age restriction, kids mode, category/ranking and randomized fill; any replacement must retain those semantics and response compatibility.

Recommended implementation: first measure catalog row count and response size. Add a dedicated narrow `select()` containing fields needed for ranking/output (preserve exact public JSON shape through an explicit serializer). Cache the eligible candidate ID/feature set by content revision and filter dimensions (age, kids, Mizo); avoid caching user-specific entitlements. Prefer SQL/category candidate narrowing where semantics permit, then rank in PHP over a bounded candidate set. Cache recommendation IDs/results keyed by source movie and audience dimensions with explicit invalidation on catalog edits. Use stale-while-revalidate or a lock/single-flight regeneration to prevent stampedes. Keep random fill behavior if contract requires it. Verify the `num` index before recommending one.

### P0 — chat amplification, query and write paths

`app/Http/Controllers/Api/V4/ChatController.php`:

- `messages()` calls room authorization, loads block list (60s cache in `ChatService`), and on `initial` reads the room cursor plus first unread ID. It loads at most `chat.page_size + 1` rows and returns a `has_more` flag. The default/latest snapshot may use `ChatService::recent()`; delta and before-cursor queries use the `(room_id,id)` index defined by `2026_10_24_000001_create_chat_tables.php`. It then hydrates private watch/invitation metadata and decorates interactions; cost depends on those helper query patterns and page size.
- `unread()` calls `ChatReadService::summary()`: it obtains a global outbox max cursor, discovers candidate rooms, checks access, reads cursors and counts unread messages grouped by room. Its range predicates can inspect unread rows after each user's cursor; measure rows/buffers and user room fanout. The code comments explicitly favor bounded last-ID lookups over history-wide max/group scans.
- `inviteRooms()` queries active invite rooms and accepted memberships, applies lifecycle filtering and returns all matches; no page/limit is visible in this method. Large active membership sets can make payload and PHP filtering grow.
- `pins()` filters live retained messages and decorates all pinned rows; no explicit limit is visible. Pin cardinality should be measured.
- `join()` upserts/reads a room, checks room access, then updates presence. `heartbeat()` checks access and writes a Redis sorted-set score, prunes stale members, returns cardinality; it does not write SQL in the shown presence helper. Repeated heartbeats still consume app/Redis work.
- `ChatService::room()` caches the room for 30 seconds; bans for 15 seconds, playback authorization for 5 seconds, blocks for 60 seconds. Cache failures fall back to the database. `presence()` uses Redis and returns 0 on errors.
- Message writes include a unique `(user_id,request_id)` idempotency key. Outbox writes are part of event delivery; inspect worker claim/update frequency and retries before attributing outbox tuple churn.

Schema evidence: `chat_messages` has unique `(user_id,request_id)`, `(room_id,id)` and `created_at`; `chat_rooms.room_key` is unique; `chat_reactions` primary key is `(message_id,user_id,emoji)` and has `user_id`; `chat_pins` unique `(room_id,message_id)` and `(room_id,created_at)`; `chat_room_reads` unique `(user_id,room_id)`; `chat_outbox` has `(delivered_at,available_at,id)`, with later claim columns. These are migration-declared indexes, not proof of production presence or adequacy. `chat_rooms` estimated 365 live tuples makes an occasional sequential scan potentially cheaper than an index lookup; validate with plans.

Client evidence: `docs/CHAT_MOBILE.md` documents removal of the 10-second unread poll, local unread updates from frames, reconciliation on initial load/open/reconnect, a five-minute integrity snapshot and disconnected fallback. This argues against assuming current mobile always polls every ten seconds. Inspect the actual deployed Android/iOS SDK builds and web/Vue client (including background lifecycle and duplicate timers) against the doc. The per-room message request's observed 55% share still needs measured request/user/minute and request body/query pattern. Existing docs indicate a reconnect recovery path; capture WebSocket close/error, reconnect attempts and REST fallback events per app version.

Client actions if deployed clients diverge: one shared connection/timer per account+screen, cancel on background/room exit, exponential reconnect backoff with full jitter and a cap, avoid simultaneous REST snapshots on reconnect, resume from last message/event cursor, use conditional requests or delta cursors, and back off REST fallback when Reverb is unavailable. Do not poll while backgrounded. Preserve a bounded recovery snapshot for clients that cannot use sockets.

Backend actions: retain cursor pagination; instrument query count/rows/bytes per route. Consider bounded pagination for invite rooms and pins only with explicit compatibility review; avoid query-by-query cache patches without invalidation semantics. For unread, consider per-room unread counters or a safely maintained summary only after benchmarks show aggregation is a material cost and deletion/block/retention/read semantics are covered. Keep idempotent send behavior. Outbox retry/backoff and worker batch/claim queries need measurement.

### P0 — Firebase analytics configuration

`app/Support/Analytics/AnalyticsCollectionSetting.php`: `enabled()` uses `Cache::remember()` with a 15-second TTL and a SHA-256 key incorporating `config('firebase.database_url')`; cache exceptions are reported and treated as disabled. A cache miss synchronously constructs a Firebase database client and calls `getValue()` without visible timeout/retry options in this class. `publish()` updates the same key. This is a cache-stampede and external latency hypothesis, not evidence every request calls Firebase.

`config/cache.php` defaults to `CACHE_STORE` with fallback `database`; `.env.example` sets `CACHE_STORE=database`. This does not reveal production's effective driver. Verify production environment and whether all app instances share it. `AnalyticsIngestionController::config()` and `analyticsEnabled()` call the setting; the supplied traces observed Guzzle `curl_exec()`. Configure bounded connect/read timeouts and retries in Firebase transport configuration after verifying SDK support. Consider a shared Redis cache with lock/single-flight refresh and a last-known-good value plus explicit freshness/error policy; preserve admin enable/disable publication semantics. Async refresh is appropriate only if endpoint behavior can return the last known value without violating required policy. Test cache failure and Firebase outage behavior because current fail-closed behavior disables collection.

### P1 — home catalog serialization

`app/Http/Controllers/New/MovieController.php`, `getMovies()` (around line 1442) implements platform/kids/Mizo/category filters in PHP after its query setup and response construction; inspect the remainder of this method when measuring because the initial excerpt alone does not prove all rows are loaded. `docs`/trace points identify line 1778 serialization and V4 `CatalogController::home()` delegates directly to `getMovies()`. Measure query count, selected columns, collection cardinality, `toArray()`/JSON duration, Carbon cast count and response bytes. Narrow selected fields, prefilter in SQL where equivalence is proven, and cache public catalog payloads by platform/mode/catalog revision. Preserve filtering and response fields.

### P1 — OTP hashing and request amplification

`app/Http/Controllers/OTPController.php`, `send()` generates an OTP and calls `Hash::make()` once per accepted request, stores/replaces an unverified request and performs a synchronous WhatsApp send. `verify()` loads the latest OTP, checks expiry then `Hash::check()` once for a normal request; this is intentionally expensive password hashing. The evidence does not show duplicate requests, failed attempt volume or hash algorithm/cost. Routes `/otp/request` and `/otp/verify` have named `throttle:otp-request` and `throttle:otp-verify` middleware (`routes/api_v4.php`), but inspect limiter definitions and production sharing/keys to assess effective per-phone/IP limits. Do not lower hash cost or relax expiry/attempt controls. Measure hash time, queue external send if contract allows, deduplicate repeated OTP send intents with a short server-side idempotency/cooldown policy that preserves resend UX, and verify distributed throttles cover phone/account and IP against enumeration/brute force.

### P1 — admin WhatsApp inbox

`app/Http/Controllers/Api/V4/AdminWhatsAppInboxController.php`, `conversations()` retrieves every `WhatsAppMessage`, groups the entire history in PHP by phone, then derives latest preview, total count and unread count per conversation. No pagination or SQL aggregation is visible: likely memory/CPU growth as history grows. `messages()` retrieves all messages for a phone with no limit. `markRead()` is a set-based update. The supplied 73 endpoint requests and three slow traces justify investigation but don't establish total CPU share. Use an aggregate query for latest/count/unread and paginate message history by `(message_at,id)`; verify/add suitable indexes only after `pg_indexes`/plans. Preserve exact response fields and ordering.

### P1 — Reverb incident

The supplied Nginx timestamp confirms refused connections to `127.0.0.1:8080`; source cannot establish whether Reverb exited, was restarting, was overloaded, or had a listener/config issue. Check systemd/Supervisor status and restart history, Reverb logs, host OOM/kernel logs, listener backlog, deployment events, Nginx upstream config, and WebSocket connection/reconnect rates around the incident. Add process supervision/health checks, bounded graceful restart and alerting. Client reconnects need exponential backoff + jitter and a cap; REST fallback needs its own rate/circuit behavior during outages. Never infer this event explains all CPU saturation.

## Database query/index review

Before any index change, capture production `pg_indexes`, table/index sizes and representative plans. Compare current definitions with the migration declarations above and check whether the chat database is PostgreSQL or another configured driver. Candidate index investigations (not migration recommendations yet):

- `chat_messages(room_id,id)` for room cursor retrieval; check planner behavior with retention and `user_id NOT IN` predicates. A wider composite index may add write/storage cost and should only follow measured plan evidence.
- `chat_reactions(message_id,user_id,emoji)` supports message reaction lookup; check reverse user-centric reads against separate `user_id` index.
- `chat_pins(room_id,message_id)` and `(room_id,created_at)`; compare pin query plan/order by joined message creation time.
- `chat_room_reads(user_id,room_id)` supports per-user read cursor lookup.
- `chat_outbox(delivered_at,available_at,id)` supports pending work, but worker claim columns/filters may warrant a partial index only if the exact pending query plan shows a scan.
- `chat_rooms` is small in the supplied estimate; sequential scans can be optimal.
- For WhatsApp inbox, discover actual table/index definitions and plan. Likely query patterns are contact + chronological cursor and global latest/group aggregate.

`ANALYZE` freshness, table bloat, autovacuum and stats reset/restart context matter. High sequential scan counts may come from repeated cheap scans of small relations. Use deltas over a fixed window and buffer reads/time from `pg_stat_statements`, not lifetime totals alone.

## Safe caching recommendations

1. Recommendations: cache eligible catalog feature rows and/or ranked IDs keyed by catalog revision and audience filters; use shared cache/lock or stale-while-revalidate to suppress simultaneous regeneration. Invalidate on catalog edits. Keep entitlement checks out of shared payload cache.
2. Home catalog: cache public serialized slices with all response-affecting dimensions in key, revision-based invalidation and bounded TTL.
3. Analytics setting: make cache backend explicitly shared in production; short refresh lock, last-known-good value and remote timeout. Preserve toggle propagation and fail behavior as a deliberate policy.
4. Chat: existing short-lived authorization caches are present. Do not cache unread counts or messages without invalidation for sends, deletions, blocks, reads and retention. Redis is already used for presence; verify `chat.cache_store` in production.
5. WhatsApp conversation summaries: precompute or query aggregates rather than caching full unbounded histories; invalidate/update transactionally on message insert/read.

## Immediate mitigation options

- Confirm Reverb process/listener health and restart supervision; review the UTC incident window and reconnects before changing client fallback behavior.
- Temporarily disable or rate-limit only demonstrably abusive/high-cost routes through reviewed controls; retain OTP security and chat functionality. Prefer per-user/account controls with metrics over global blanket throttles.
- If recommendations are driving latency/CPU, temporarily serve a previously computed compatible result or bounded feature candidate set only after validating platform/kids/age/Mizo filters and cache freshness.
- Turn on low-overhead route timing/query-count/response-byte metrics and capture short, sampled slow traces. Avoid full request/body logging of credentials or phone numbers.
- Do not run speculative migrations or change the production cache driver until effective settings, capacity and rollback have been checked.

## Prioritized implementation and verification plan

| Priority | Work | Regression coverage | Before/after measurement |
|---|---|---|---|
| 0 | Capture 15–30 minute aligned Nginx, PHP-FPM, Reverb, DB and host metrics; establish stats reset and request dimensions | Diagnostic data handling review | Route QPS, p50/p95/p99, CPU seconds/request, DB time/query count, bytes, active workers, reconnect rate |
| 1 | Bound recommendation candidate work; narrow fields; cache with revision and stampede protection | Ranking fixtures for platform, kids, age, Mizo, exact/miss title, random fill, JSON schema | CPU ms/request, hydrated rows, query rows, serialized bytes, cache hit/miss, p95; compare identical catalog snapshot |
| 1 | Diagnose chat message/unread/invite/pin query cost and deployed-client polling/socket behavior | Cursor ordering/no gaps/duplicates, block/delete/retention/read semantics, idempotent send, reconnect recovery | Requests/user/min, DB queries/request, rows/buffers, Redis ops, CPU ms, reconnect and fallback rate |
| 1 | Protect analytics config refresh from external latency/stampede | Toggle propagation, cache miss concurrency, Firebase timeout/outage, last-good freshness/fail policy | Firebase call rate/latency, cache miss concurrency, analytics endpoint p95/errors |
| 2 | Replace WhatsApp full-history grouping and unbounded message reads with aggregate/keyset queries | Same counts/latest/name/preview/unread/order and pagination boundaries | Rows hydrated, DB buffers/time, PHP memory, response bytes/p95 |
| 2 | Measure home serialization and narrow/cache equivalent output | Snapshot compatibility across kids/platform/Mizo/age/entitlement | Query count, model count, JSON time/bytes, p95 and CPU |
| 2 | Assess OTP failed attempts and duplicate sends; tune limiter configuration only from evidence | Expiry, single-use, brute-force, enumeration, resend and distributed limiter tests | Requests per account/IP, hash CPU share, WhatsApp calls, verify failures |
| 3 | Add only plan-validated indexes and observe write cost | Migration up/down and query-result equivalence | Plan buffers/time, index size, write latency, bloat, vacuum cost |

### Performance measurement method

Use the same release/data shape and representative request mix before and after. Warm caches separately from cold-cache runs; report both. Collect route-level p50/p95/p99 latency, CPU seconds (or sampled CPU time) per route, PHP worker busy time, queries and DB time per request, rows read/returned, buffer hits/reads, response bytes, cache hit/miss/lock wait, Redis operations, and error/status rates. For client amplification, use synthetic foreground/background/poor-network/WebSocket-disconnect scenarios and count REST calls per user-minute. For a proposed index, compare `EXPLAIN (ANALYZE, BUFFERS)` on equivalent data and monitor writes and index size. No performance test has been run as part of this investigation-only task.

## Deployment and rollback plan (for later approved implementation)

1. Land code/config changes behind a reversible feature flag where practical; deploy to staging with production-like data volume and run the regression cases above.
2. Deploy application changes in a canary, watch route p95/CPU, payload compatibility, cache errors, Firebase freshness, chat event gaps and WebSocket reconnect/fallback rates.
3. Roll back by disabling the flag or reverting the application release. Cache key versioning permits old and new entries to coexist; avoid destructive cache flushes.
4. Database indexes, if later justified, should use the database's supported online/concurrent index procedure, capacity checks and an explicit rollback/drop plan. The current task did not run migrations or change production configuration.
5. Keep sampled before/after measurements and release identifiers together so load changes are not mistaken for code improvements.

## Conclusion

The strongest confirmed code hotspot is unbounded full-model recommendation candidate loading plus repeated PHP matching/serialization. The admin WhatsApp inbox also hydrates full message history without pagination. Chat dominates request count but has existing pagination, Redis presence and client-side realtime behavior documented; its aggregate CPU share remains unknown and needs correlated per-user/request/query measurements. Firebase calls can block on a 15-second cache miss and have no timeout visible in the setting class. OTP work is deliberately costly and routes have named throttles; verify effective limiter behavior rather than weakening hashes or expiry. PostgreSQL index conclusions and global CPU root cause remain hypotheses pending reset timestamps, current indexes, query plans, and aligned production measurements.
