# Server CPU root cause analysis

**Scope:** source inspection correlated with the supplied 100,000-request Nginx sample, PostgreSQL counters and 30 PHP-FPM slow traces. The supplied aggregates are evidence of workload and elapsed latency; they are not CPU profiles. No production host, Nginx log lines, PostgreSQL session, `pg_stat_statements`, EXPLAIN plans or PHP-FPM trace files were available in this workspace. Findings below distinguish what the source proves from what needs production measurement.

## Executive assessment



## Evidence and unresolved questions

| Evidence | What it supports | What it does not establish |
|---|---|---|
| 30 timestamped slow traces: catalog/recommendations 14, auth 11, WhatsApp 3, analytics 2 | These routes have elapsed-time outliers | CPU attribution: waits, external I/O and serialization time are included |

For the Nginx sample, collect method, status, `$request_time`, `$upstream_response_time`, `$body_bytes_sent`, timestamp, request ID and a privacy-safe user/device identifier. Group by route + method + status and report p50/p95/p99, bytes, unique users, requests/user/minute, repeated identical queries and time-window distribution. Compare Nginx request IDs with Laravel traces and database query logs. Do not retain raw phone numbers or bearer tokens in diagnostic dimensions.

For PostgreSQL, collect `pg_stat_database.stats_reset`, server restart time, table sizes (`pg_total_relation_size`), `pg_stat_user_tables` snapshots as deltas over a known interval, `pg_indexes`, `pg_stat_statements` calls/mean/max execution/rows/buffers/temp blocks, and representative `EXPLAIN (ANALYZE, BUFFERS)` for the exact production SQL. Use a safe read-only replica or carefully sampled plans where production execution cost is a concern.

## Source-confirmed findings

### P0 — recommendation serialization and generation

`app/Http/Controllers/New/AlsoLikeController.php`, `alsoLike()`, `fetchMovies()`, `findBestMatch()`, `filterSequels()`, `filterGenreAndCategory()` and `getRecommendations()`:

- `fetchMovies()` does an unbounded `get()->toArray()` over all enabled, published rows with only optional age/kids/Mizo predicates. This hydrates full `MovieModel` instances then serializes all columns; `toArray()` invokes casts/date serialization.
- Recommendation matching scans all candidates with `similar_text`; it scans again for sequel matching and again for genre/category, then may shuffle and scan the full collection for fallback. This is O(N) passes with relatively expensive string comparison.
- Result list is capped at 20 only after the full collection work. Transformation removes playback URLs only after retrieval and still sends other model fields. This makes result size smaller than work and may leave excess payload fields.
- `app/Http/Controllers/Api/V4/CatalogController.php`, `recommendations()` first retrieves a full model by id/num, then delegates to this code. The `orWhere(num)` lookup should be reviewed against indexes and grouping/uniqueness; no query plan was available.
- Current logic includes user/Mizo selection, age restriction, kids mode, category/ranking and randomized fill; any replacement must retain those semantics and response compatibility.

Recommended implementation: first measure catalog row count and response size. Add a dedicated narrow `select()` containing fields needed for ranking/output (preserve exact public JSON shape through an explicit serializer). Cache the eligible candidate ID/feature set by content revision and filter dimensions (age, kids, Mizo); avoid caching user-specific entitlements. Prefer SQL/category candidate narrowing where semantics permit, then rank in PHP over a bounded candidate set. Cache recommendation IDs/results keyed by source movie and audience dimensions with explicit invalidation on catalog edits. Use stale-while-revalidate or a lock/single-flight regeneration to prevent stampedes. Keep random fill behavior if contract requires it. Verify the `num` index before recommending one.

### P0 — Firebase analytics configuration

`app/Support/Analytics/AnalyticsCollectionSetting.php`: `enabled()` uses `Cache::remember()` with a 15-second TTL and a SHA-256 key incorporating `config('firebase.database_url')`; cache exceptions are reported and treated as disabled. A cache miss synchronously constructs a Firebase database client and calls `getValue()` without visible timeout/retry options in this class. `publish()` updates the same key. This is a cache-stampede and external latency hypothesis, not evidence every request calls Firebase.

`config/cache.php` defaults to `CACHE_STORE` with fallback `database`; `.env.example` sets `CACHE_STORE=database`. This does not reveal production's effective driver. Verify production environment and whether all app instances share it. `AnalyticsIngestionController::config()` and `analyticsEnabled()` call the setting; the supplied traces observed Guzzle `curl_exec()`. Configure bounded connect/read timeouts and retries in Firebase transport configuration after verifying SDK support. Consider a shared Redis cache with lock/single-flight refresh and a last-known-good value plus explicit freshness/error policy; preserve admin enable/disable publication semantics. Async refresh is appropriate only if endpoint behavior can return the last known value without violating required policy. Test cache failure and Firebase outage behavior because current fail-closed behavior disables collection.

### P1 — home catalog serialization

`app/Http/Controllers/New/MovieController.php`, `getMovies()` (around line 1442) implements platform/kids/Mizo/category filters in PHP after its query setup and response construction; inspect the remainder of this method when measuring because the initial excerpt alone does not prove all rows are loaded. `docs`/trace points identify line 1778 serialization and V4 `CatalogController::home()` delegates directly to `getMovies()`. Measure query count, selected columns, collection cardinality, `toArray()`/JSON duration, Carbon cast count and response bytes. Narrow selected fields, prefilter in SQL where equivalence is proven, and cache public catalog payloads by platform/mode/catalog revision. Preserve filtering and response fields.

### P1 — OTP hashing and request amplification

`app/Http/Controllers/OTPController.php`, `send()` generates an OTP and calls `Hash::make()` once per accepted request, stores/replaces an unverified request and performs a synchronous WhatsApp send. `verify()` loads the latest OTP, checks expiry then `Hash::check()` once for a normal request; this is intentionally expensive password hashing. The evidence does not show duplicate requests, failed attempt volume or hash algorithm/cost. Routes `/otp/request` and `/otp/verify` have named `throttle:otp-request` and `throttle:otp-verify` middleware (`routes/api_v4.php`), but inspect limiter definitions and production sharing/keys to assess effective per-phone/IP limits. Do not lower hash cost or relax expiry/attempt controls. Measure hash time, queue external send if contract allows, deduplicate repeated OTP send intents with a short server-side idempotency/cooldown policy that preserves resend UX, and verify distributed throttles cover phone/account and IP against enumeration/brute force.

### P1 — admin WhatsApp inbox

`app/Http/Controllers/Api/V4/AdminWhatsAppInboxController.php`, `conversations()` retrieves every `WhatsAppMessage`, groups the entire history in PHP by phone, then derives latest preview, total count and unread count per conversation. No pagination or SQL aggregation is visible: likely memory/CPU growth as history grows. `messages()` retrieves all messages for a phone with no limit. `markRead()` is a set-based update. The supplied 73 endpoint requests and three slow traces justify investigation but don't establish total CPU share. Use an aggregate query for latest/count/unread and paginate message history by `(message_at,id)`; verify/add suitable indexes only after `pg_indexes`/plans. Preserve exact response fields and ordering.

## Database query/index review


- For WhatsApp inbox, discover actual table/index definitions and plan. Likely query patterns are contact + chronological cursor and global latest/group aggregate.

`ANALYZE` freshness, table bloat, autovacuum and stats reset/restart context matter. High sequential scan counts may come from repeated cheap scans of small relations. Use deltas over a fixed window and buffer reads/time from `pg_stat_statements`, not lifetime totals alone.

## Safe caching recommendations

1. Recommendations: cache eligible catalog feature rows and/or ranked IDs keyed by catalog revision and audience filters; use shared cache/lock or stale-while-revalidate to suppress simultaneous regeneration. Invalidate on catalog edits. Keep entitlement checks out of shared payload cache.
2. Home catalog: cache public serialized slices with all response-affecting dimensions in key, revision-based invalidation and bounded TTL.
3. Analytics setting: make cache backend explicitly shared in production; short refresh lock, last-known-good value and remote timeout. Preserve toggle propagation and fail behavior as a deliberate policy.
5. WhatsApp conversation summaries: precompute or query aggregates rather than caching full unbounded histories; invalidate/update transactionally on message insert/read.

## Immediate mitigation options

- If recommendations are driving latency/CPU, temporarily serve a previously computed compatible result or bounded feature candidate set only after validating platform/kids/age/Mizo filters and cache freshness.
- Turn on low-overhead route timing/query-count/response-byte metrics and capture short, sampled slow traces. Avoid full request/body logging of credentials or phone numbers.
- Do not run speculative migrations or change the production cache driver until effective settings, capacity and rollback have been checked.

## Prioritized implementation and verification plan

| Priority | Work | Regression coverage | Before/after measurement |
|---|---|---|---|
| 1 | Bound recommendation candidate work; narrow fields; cache with revision and stampede protection | Ranking fixtures for platform, kids, age, Mizo, exact/miss title, random fill, JSON schema | CPU ms/request, hydrated rows, query rows, serialized bytes, cache hit/miss, p95; compare identical catalog snapshot |
| 1 | Protect analytics config refresh from external latency/stampede | Toggle propagation, cache miss concurrency, Firebase timeout/outage, last-good freshness/fail policy | Firebase call rate/latency, cache miss concurrency, analytics endpoint p95/errors |
| 2 | Replace WhatsApp full-history grouping and unbounded message reads with aggregate/keyset queries | Same counts/latest/name/preview/unread/order and pagination boundaries | Rows hydrated, DB buffers/time, PHP memory, response bytes/p95 |
| 2 | Measure home serialization and narrow/cache equivalent output | Snapshot compatibility across kids/platform/Mizo/age/entitlement | Query count, model count, JSON time/bytes, p95 and CPU |
| 2 | Assess OTP failed attempts and duplicate sends; tune limiter configuration only from evidence | Expiry, single-use, brute-force, enumeration, resend and distributed limiter tests | Requests per account/IP, hash CPU share, WhatsApp calls, verify failures |
| 3 | Add only plan-validated indexes and observe write cost | Migration up/down and query-result equivalence | Plan buffers/time, index size, write latency, bloat, vacuum cost |

### Performance measurement method


## Deployment and rollback plan (for later approved implementation)

1. Land code/config changes behind a reversible feature flag where practical; deploy to staging with production-like data volume and run the regression cases above.
3. Roll back by disabling the flag or reverting the application release. Cache key versioning permits old and new entries to coexist; avoid destructive cache flushes.
4. Database indexes, if later justified, should use the database's supported online/concurrent index procedure, capacity checks and an explicit rollback/drop plan. The current task did not run migrations or change production configuration.
5. Keep sampled before/after measurements and release identifiers together so load changes are not mistaken for code improvements.

## Conclusion
