# Zo Stream performance optimization progress

**Review date:** 2026-11-26  
**Scope:** backend implementation and regression review; production changes remain out of scope.

## Status checklist

### Investigation and baseline

- [x] Source findings and supplied production evidence recorded in `SERVER_CPU_ROOT_CAUSE_ANALYSIS.md`.
- [x] Current working tree and recent commit history inspected. The latest commits are opaque `update`/`sdf` messages; performance-relevant history includes commits `a8e8ae3f`, `0c8d4dcb`, `e5cb79db` and `936b727c` touching the analytics setting and WhatsApp inbox. The current optimization changes are uncommitted working-tree edits.
- [x] Existing targeted tests and response behavior inspected.
- [ ] Production request dimensions, query plans, current index definitions, stats reset timestamps, cache backend and before/after resource metrics collected. These are not available in the repository/session and remain required for measured conclusions.

### Phase 1 backend work

- [x] **Recommendation candidate retrieval — implemented.** `AlsoLikeController` uses a narrow query-builder candidate index and hydrates/serializes at most 20 selected Eloquent models per request. Exact response fields, casts and playback-field removal are preserved for selected results.
- [x] **Ranking work — implemented.** Sequel and genre/category classification share a pass; similarity calls that cannot meet the existing thresholds are skipped using a safe maximum-score bound. Canonical catalog title ranking results are cached; arbitrary user-supplied title text does not create unbounded title-specific cache keys. Random fallback and related-result shuffle remain per request.
- [x] **Recommendation cache/revision invalidation — implemented; migration required before app rollout.** Narrow candidate sets and canonical ranking data use shared cache locks and revision/dimension keys. Model create/delete and ranking-field updates bump the DB revision; scheduled movie publication query-builder updates bump around the write. External direct SQL writers must bump the revision too.
- [x] **CatalogController recommendations lookup — optimized.** `recommendations()` now fetches only `num`, `id`, and `title` via the query builder and groups the ID/num alternatives.
- [x] **Firebase config refresh — implemented; rollout verification pending.** `AnalyticsCollectionSetting::enabled()` uses a shared cache lock and rechecks after acquiring it. Firebase calls have 2s connect and 4s total timeouts. Outages fail closed and cache `false` for 3s to prevent sequential request stalls; successful values retain the 15s TTL. Admin publish now shares the refresh lock and immediately replaces the value. `AdminAnalyticsController` show/update calls also have bounded Firebase timeouts. No automatic Firebase transport retry was added; the short outage cache controls retry frequency.
- [x] **Analytics event batch writes — optimized with idempotency/privacy retained.** Properties are sanitized once, event rows still use individual `insertOrIgnore` results to identify exactly which events are new, and a batch is committed in one analytics DB transaction. Engagement tracking receives only committed newly accepted events and the already-sanitized properties. A failed batch rolls back; clients can safely retry by event ID.
- [x] **Analytics presence Redis writes — pipelined.** The same eleven index/metadata/expiry commands now execute in one Redis pipeline round trip. No presence index, TTL cleanup or metadata writes were removed. The API remains rate limited at 8 requests/minute and advertises a 60-second heartbeat interval.
- [x] **Presence platform indexes — completed.** Added accepted `tizen`, `webos` and `web` platforms to expiry cleanup and aggregate counts; offline requests now remove those platform indexes immediately too.
- [ ] **Firebase effective production cache — partially verified.** Repository config defaults to the database cache and a migration creates `cache_locks`; the effective production store and whether it is shared across all app/worker hosts are unknown. Lock contention and Firebase timeout behavior have not been tested across processes.
- [x] **WhatsApp conversation summaries — optimized and regression-covered; production plan validation pending.** SQL aggregates counts/unread counts per contact and selects latest message/name; only response-required columns and one message model per conversation are materialized. A single mapping pass builds the existing response fields/order.
- [ ] **WhatsApp production plan/index validation — pending.** Migration evidence shows indexes on phone, direction, status and message time, but the deployed DB engine/index state and plan for correlated latest-row queries are unknown.
- [x] **WhatsApp history pagination — implemented as opt-in.** Existing requests without pagination parameters retain the raw full-history array. Clients may request `limit` (1–200; default 50 when a cursor is supplied), then continue with `before_at` and `before_id`; returned page remains a chronological array with `X-Has-More` and next-cursor headers.
- [x] **Home/movie listing APIs — optimized.** Legacy and New `MovieController::getMovies()` paths plus V4 `CatalogController::home()` now use revisioned, shared public response caching. Cache keys partition route, ID/range/category/category type, platform query/header, kids mode, age restriction, enable flag and the effective Mizo-only visibility class. Cached values are serialized arrays. The New home watch list remains per-user and is queried/serialized outside the shared cache.
- [x] **Movie listing query/hydration work — optimized.** List resources eager-load only `trailerRecords.id/movie_id/url`, matching the relation used by serialization and removing per-movie trailer queries. V4 public index/latest and filter lists also eager-load trailers. Genre matching preserves its legacy JSON/comma/pipe/slash token rules using compact `num,genre` query rows, then hydrates only the requested response page. SQL kids/Mizo/age predicates remain; redundant post-query collection rejection was removed after behavior checks.
- [x] **Catalog cache invalidation — implemented; migration/runtime verification pending.** Existing `MovieCatalogRevision` now advances around all model-backed MovieModel creates, edits and deletes, and known bulk movie scheduling and admin trailer replacement writes. The cache expires after 30 seconds and uses the default shared cache store lock. The existing `2026_11_26_000001_create_movie_catalog_revisions_table` migration must be applied; without it the cache safely falls back to uncached queries.
- [ ] **Production cache/index/plan validation — pending.** `CACHE_STORE` defaults to `database` in repository config; production cache backend/topology, migration status and atomic lock behavior are unverified. No movie-table index was proposed: the repository does not contain its base movie-table creation/index migration, and no deployed query plan was available.

### Phase 2 clients

- [x] **Client investigation plan — documented, not verified in deployed builds.** `CLIENT_OPTIMIZATION_PLAN.md` lists polling, duplicate connection/timer, background lifecycle, jittered reconnect, cursor sync, REST fallback and recovery checks. Mobile repository documentation is evidence of intended behavior only; deployed Android/iOS/Web behavior is unknown.
- [ ] **Unread aggregation/caching — no counter or summary cache added.** Counts depend on read cursors, newly committed messages, blocks, deletions, retention and current room access. The existing SQL aggregation uses per-room `(room_id, id)` ranges and filters own/deleted/expired/blocked messages, while latest IDs use a correlated indexed lookup. Incremental counters or response caching need PostgreSQL workload evidence and complete invalidation/transaction proofs before adoption.
- [ ] **Client telemetry/deployed behavior — pending.** No per-version requests/user/minute, socket retry, background state or REST fallback telemetry was supplied.

## Files modified by the reviewed implementation

- `app/Support/Analytics/AnalyticsCollectionSetting.php` — cache lock and Firebase HTTP timeouts.
- `app/Http/Controllers/Api/V4/AdminAnalyticsController.php` — bounded Firebase admin read/write timeout.
- `app/Http/Controllers/Api/V4/AnalyticsIngestionController.php` — sanitized once and transactional event batch persistence.
- `app/Support/Analytics/AnalyticsPresenceStore.php` — pipelined Redis heartbeat updates.
- `tests/Unit/AnalyticsCollectionSettingTest.php` — Firebase outage suppression, fail-closed cache errors, simulated overlapping miss and config replacement.
- `tests/Unit/AnalyticsPresenceStoreTest.php` — validates all heartbeat commands are pipelined and web platform indexes are removed on offline.
- `tests/Feature/AnalyticsApiTest.php` — event batch rollback/retry, privacy and existing idempotency/API behavior.
- `app/Http/Controllers/Api/V4/AdminWhatsAppInboxController.php` — narrow SQL summary projection and opt-in history pagination.
- `app/Http/Controllers/MovieController.php` — legacy movie list caching and batched trailer eager loading.
- `app/Http/Controllers/New/MovieController.php` — public home/list caching, genre page hydration and trailer eager loading.
- `app/Support/Catalog/PublicMovieCatalogCache.php` — revision/dimension keying, shared lock, short TTL and uncached fallback.
- `app/Models/MovieModel.php` — broad catalog revision invalidation for creates, updates and deletes.
- `app/Console/Commands/MovieSchedule.php` — revision invalidation around the bulk movie timestamp write.
- `app/Http/Controllers/New/AlsoLikeController.php` — bounded response hydration and ranking-index use.
- `app/Http/Controllers/Api/V4/CatalogController.php` — narrow recommendation source lookup.
- `app/Models/MovieModel.php` — revision invalidation model hooks.
- `app/Console/Commands/MovieSchedule.php` — revision invalidation around bulk publication.
- `app/Support/Catalog/MovieCatalogRevision.php` — revision read/write helper.
- `app/Support/Catalog/MovieRecommendationIndex.php` — narrow candidate and ranking cache.
- `database/migrations/2026_11_26_000001_create_movie_catalog_revisions_table.php` — revision table migration (not executed).
- `tests/Feature/AlsoLikeRecommendationTest.php` — recommendation compatibility, invalidation and local benchmark coverage.
- `tests/Feature/WhatsAppWebhookTest.php` — regression coverage for latest preview/name and aggregate counts, one-query/10-row summaries from 300 messages, and backward-compatible history pagination.
- `docs/performance/IMPLEMENTATION_REPORT.md` — implementation/deferred-scope notes.
- `docs/performance/CLIENT_OPTIMIZATION_PLAN.md` — client requirements and evidence limits.
- `docs/performance/PRODUCTION_VERIFICATION.md` — canary and rollback checklist.
- `docs/performance/IMPLEMENTATION_REPORT.md` — recommendation implementation and synthetic benchmark results.
- `docs/performance/SERVER_CPU_ROOT_CAUSE_ANALYSIS.md` — prior investigation report; unchanged in this review.

An unrelated untracked `docs/.DS_Store` was present and was not modified. The current performance edits remain uncommitted; no unrelated tracked changes were overwritten.

## Regression review

- **Analytics fail-closed/security behavior:** retained. `enabled()` still returns `false` and reports cache/Firebase errors. Firebase failures now suppress repeat reads for 3 seconds; admin publish overwrites that value under the same lock. No authentication, OTP or analytics authorization behavior changed.
- **Analytics cache consistency:** `publish()` still writes the same key/TTL. A read concurrent with a Firebase admin toggle can observe the prior state for that in-flight request, as with the previous implementation; publish then replaces the cached value. The next request uses the published setting. This race was not found to be a new regression.
- **Lock behavior:** code relies on the effective cache driver implementing atomic locks. The repo has a cache lock table migration, but production driver/topology is unverified. A lock wait timeout fails closed by design and may temporarily reject analytics collection requests under lock trouble; monitor before rollout. Contention is simulated in a unit test; no multi-process test or production cache verification was available.
- **Presence/batch behavior:** pipelines retain every Redis command and its TTL; verify the deployed Redis client is the configured PhpRedis-compatible driver. Analytics event batch failures roll back the batch and depend on client retry using stable event IDs; the feature test verifies a failure followed by a successful retry stores all valid events once.
- **WhatsApp response behavior:** latest row and latest non-empty name retain `message_at DESC, id DESC`; fields, preview behavior and conversation ordering remain. Opt-in history pages return the same raw-array shape and chronological ordering; requests without pagination remain unchanged. Cursor ties use message ID.
- **WhatsApp memory/query tradeoff:** synthetic coverage inserts 300 history rows across 10 contacts and observes one summary SELECT returning 10 rows/models; the test asserts the response is below 10 KB. Its observed test runtime was about 0.04s including fixture inserts, so it is not isolated endpoint latency. Opt-in history pages retrieve at most 200 rows. SQL still aggregates the full matching history, and production plan/latency plus large legacy unpaginated histories remain to be measured. No index is proposed without query-plan evidence.
- **Home/catalog cache safety:** only shared public shelves/list arrays are cached. The New home `Watch List` is fetched per request and never enters a shared cache key/value. Mizo-only users share only with the same public visibility class; raw user IDs, subscriptions, entitlements and watch history are absent from cache values. Admin MovieModel writes advance the revision on both sides; trailer replacement and scheduled bulk updates are covered separately. The root legacy endpoint continues its existing API-key check before reading the cache.
- **Home/catalog performance:** synthetic SQLite fixture (2 movies appearing in 5 shelves) measured cold home **51.56 ms / 18 SELECTs**, warm home **0.61 ms / 1 SELECT** (catalog revision lookup), both **10 shelf entries / 5,234 response bytes** with identical JSON. This was one local test run; it is a cache cold/warm comparison, not a production estimate or a direct replay of the prior implementation. Warm cache hydrates/serializes no MovieModel rows. Peak memory and production cold-query plans were not measured.
- **Personal watch-list query:** a warm request with two private items uses three SELECTs (revision lookup, watch-list query and one eager trailer query), avoiding per-movie trailer reads.
- **WhatsApp pagination regression fixed.** The first cursor test exposed a timestamp format mismatch between ISO cursor headers and SQL datetime values; the controller now normalizes cursor timestamps before comparison. Repeated pages use `(message_at, id)` ordering and do not repeat the boundary row.

## Tests run

Commands:

```text
php artisan test tests/Feature/AlsoLikeRecommendationTest.php tests/Unit/AnalyticsCollectionSettingTest.php tests/Feature/AnalyticsApiTest.php tests/Feature/AdminConfigWhitelistTest.php tests/Feature/WhatsAppWebhookTest.php tests/Feature/V4ApiTest.php tests/Feature/HomeSeriesOrderingTest.php tests/Feature/NewMovieNotificationTest.php
php artisan test tests/Unit/AnalyticsCollectionSettingTest.php tests/Unit/AnalyticsPresenceStoreTest.php tests/Feature/AnalyticsApiTest.php
php artisan test tests/Feature/WhatsAppWebhookTest.php tests/Feature/V4ApiTest.php
git diff --check
```

Results: the previous cross-project suite passed **70 tests, 851 assertions**. The focused analytics suite plus admin whitelist coverage passed **27 tests, 156 assertions**. The WhatsApp webhook and V4 API suites passed **31 tests, 680 assertions**; the summary performance and tied-timestamp cursor tests passed again individually after final assertions/fixture changes. Laravel/PHP 8.5 deprecation notices appeared for existing `PDO::MYSQL_ATTR_SSL_CA` constants and Collision's `ReflectionMethod::setAccessible()` call; they did not fail tests. PHP syntax checks passed for changed analytics presence files and `git diff --check` passed.

The home/catalog, recommendation, V4 API, playback security and movie notification regression group passed **99 tests, 1,042 assertions**. Home cache fixture measured cold **18 SELECTs / 51.56 ms**, warm **1 SELECT / 0.61 ms**, and identical **5,234-byte** response payloads. PHP 8.5 emitted the same pre-existing PDO SSL constant and Collision reflection deprecations; no test failed.

The recommendation test's single synthetic SQLite run with 250 rows measured: legacy **17.25 ms / 1 SQL query / 250 Eloquent models hydrated / 9,941 response bytes / 1,620,512 bytes incremental PHP peak**; optimized cold **2.30 ms / 3 queries / 20 models / 9,941 bytes / 459,224 bytes peak**; optimized warm **2.12 ms / 2 queries / 20 models / 9,941 bytes / 174,800 bytes peak**. The cold candidate index still reads 250 compact raw rows. Timing and memory figures are local test observations, not production estimates; the SQL statement count increases because the revision is checked separately and final response rows are fetched separately. A cache lock timeout fails the request rather than bypassing the lock and triggering another rebuild.

## Remaining risks and next evidence

1. Apply the new movie catalog revision migration before deploying the code. Confirm any external imports/direct SQL movie writers increment the revision.
2. Measure recommendation latency, query plans, cache hit rate, candidate rows, hydrated rows and PHP memory with production-shaped catalog data. The synthetic cold path issues more SQL statements despite reducing hydration.
3. Validate Firebase cache store and lock sharing across web and queue workers; the repository test is simulated contention, not multi-process. Confirm effective Firebase timeout and 3-second outage retry policy against production cache topology.
4. Measure analytics event transaction duration and Redis pipeline latency on the deployed analytics DB/Redis versions. Verify event clients retry failed batches with the same event IDs.
5. Compare WhatsApp summary JSON against the previous implementation on production-shaped data including timestamp ties/nulls, image/document previews, many messages per contact and unread/read transitions. Capture the deployed engine's query plan and response p95/DB buffers; migration evidence shows single-column indexes on `contact_phone`, `direction`, `status`, `message_at`, and `read_at` only, so no composite index is proposed without plan evidence.
6. Apply the movie catalog revision migration before expecting public home cache hits. Verify the production cache store is shared and atomic-lock-capable across web hosts; monitor hit rate and cache fallback rate.
7. Measure home cold/warm query plans, p95, response bytes, and memory on production-shaped catalog data. The repository has no base movie-table index definition, so inspect deployed indexes/plans before any index change.
8. Continue other home/catalog work only after personalized watch-history and client cache consumers are reviewed. Verify deployed clients against `CLIENT_OPTIMIZATION_PLAN.md` with versioned telemetry before changing polling behavior.

No deployment, migration execution, production configuration change or service restart was performed. Applying the new migration is required before the recommendation optimization can serve requests successfully.
