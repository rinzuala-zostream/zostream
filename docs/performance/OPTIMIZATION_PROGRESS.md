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
- [x] **Firebase cache-miss serialization and timeout — implemented, but rollout verification pending.** `AnalyticsCollectionSetting::enabled()` now uses the default cache store lock and rechecks after acquiring it. `readRemote()` has a 2s connect timeout and 4s total HTTP timeout. Cache key, 15s TTL, admin publish behavior and fail-closed handling are retained.
- [ ] **Firebase effective production cache — partially verified.** Repository config defaults to the database cache and a migration creates `cache_locks`; the effective production store and whether it is shared across all app/worker hosts are unknown. Lock contention and Firebase timeout behavior have not been tested across processes.
- [x] **WhatsApp conversation summaries — implemented, but production query validation pending.** Counts are aggregated per contact in SQL; only the latest message row per conversation is hydrated for payload casts and preview formatting. Existing response fields and sort order remain. No cache or index migration was added.
- [ ] **WhatsApp production plan/index validation — pending.** Migration evidence shows indexes on phone, direction, status and message time, but the deployed DB engine/index state and plan for correlated latest-row queries are unknown.
- [ ] **WhatsApp message history pagination — pending.** The endpoint remains unbounded to avoid truncating responses for old clients; add an opt-in/versioned pagination contract only after client compatibility review.
- [ ] **Home catalog — pending.** Existing code applies many category/age/kids/Mizo filters in SQL and applies bounded per-section limits, but serialization and repeated section queries have not been changed. No safe response-wide cache key/invalidation has been established, particularly for personalized watch lists.

### Phase 2 chat and clients

- [ ] **Chat backend — pending.** No changes were made in the reviewed implementation. Existing cursor pagination, idempotency, Redis presence and short-lived room/ban/playback/block caches remain. More work is gated on production query/worker measurements and exact cache invalidation semantics.
- [x] **Client investigation plan — documented, not verified in deployed builds.** `CLIENT_OPTIMIZATION_PLAN.md` lists polling, duplicate connection/timer, background lifecycle, jittered reconnect, cursor sync, REST fallback and recovery checks. Mobile repository documentation is evidence of intended behavior only; deployed Android/iOS/Web behavior is unknown.
- [ ] **Client telemetry/deployed behavior — pending.** No per-version requests/user/minute, socket retry, background state or REST fallback telemetry was supplied.

## Files modified by the reviewed implementation

- `app/Support/Analytics/AnalyticsCollectionSetting.php` — cache lock and Firebase HTTP timeouts.
- `app/Http/Controllers/Api/V4/AdminWhatsAppInboxController.php` — SQL conversation aggregation and latest-message hydration.
- `app/Http/Controllers/New/AlsoLikeController.php` — bounded response hydration and ranking-index use.
- `app/Http/Controllers/Api/V4/CatalogController.php` — narrow recommendation source lookup.
- `app/Models/MovieModel.php` — revision invalidation model hooks.
- `app/Console/Commands/MovieSchedule.php` — revision invalidation around bulk publication.
- `app/Support/Catalog/MovieCatalogRevision.php` — revision read/write helper.
- `app/Support/Catalog/MovieRecommendationIndex.php` — narrow candidate and ranking cache.
- `database/migrations/2026_11_26_000001_create_movie_catalog_revisions_table.php` — revision table migration (not executed).
- `tests/Feature/AlsoLikeRecommendationTest.php` — recommendation compatibility, invalidation and local benchmark coverage.
- `tests/Feature/WhatsAppWebhookTest.php` — regression coverage for latest preview/name and aggregate counts.
- `docs/performance/IMPLEMENTATION_REPORT.md` — implementation/deferred-scope notes.
- `docs/performance/CLIENT_OPTIMIZATION_PLAN.md` — client requirements and evidence limits.
- `docs/performance/PRODUCTION_VERIFICATION.md` — canary and rollback checklist.
- `docs/performance/IMPLEMENTATION_REPORT.md` — recommendation implementation and synthetic benchmark results.
- `docs/performance/SERVER_CPU_ROOT_CAUSE_ANALYSIS.md` — prior investigation report; unchanged in this review.

An unrelated untracked `docs/.DS_Store` was present and was not modified. The current performance edits remain uncommitted; no unrelated tracked changes were overwritten.

## Regression review

- **Analytics fail-closed/security behavior:** retained. `enabled()` still returns `false` and reports thrown cache/Firebase errors. No authentication, OTP or analytics authorization behavior changed.
- **Analytics cache consistency:** `publish()` still writes the same key/TTL. A read concurrent with a Firebase admin toggle can observe the prior state for that in-flight request, as with the previous implementation; publish then replaces the cached value. The next request uses the published setting. This race was not found to be a new regression.
- **Lock behavior:** code relies on the effective cache driver implementing atomic locks. The repo has a cache lock table migration, but production driver/topology is unverified. A lock wait timeout fails closed by design and may temporarily reject analytics collection requests under lock trouble; monitor before rollout.
- **WhatsApp response behavior:** correlated latest row uses `message_at DESC, id DESC`, latest non-empty contact name uses the same ordering, and the response retains the old keys and conversation ordering. Latest message remains an Eloquent model for date/JSON/payload casts. Added coverage verifies representative latest/name/count behavior. Null/tied timestamp edge cases and production SQL plans still need a representative DB check.
- **WhatsApp memory/query tradeoff:** PHP now hydrates only one message per conversation, but the SQL must aggregate message history and run correlated latest-row lookups. No production plan or benchmark establishes the overall DB-cost change. No speculative index was added.
- **No confirmed regression found.** No code fix was needed during this review.

## Tests run

Commands:

```text
php artisan test tests/Feature/AlsoLikeRecommendationTest.php tests/Unit/AnalyticsCollectionSettingTest.php tests/Feature/AnalyticsApiTest.php tests/Feature/AdminConfigWhitelistTest.php tests/Feature/WhatsAppWebhookTest.php tests/Feature/V4ApiTest.php tests/Feature/HomeSeriesOrderingTest.php tests/Feature/NewMovieNotificationTest.php
git diff --check
```

Result: **70 tests, 851 assertions passed**. Laravel/PHP 8.5 deprecation notices appeared for existing `PDO::MYSQL_ATTR_SSL_CA` constants and Collision's `ReflectionMethod::setAccessible()` call; they did not fail tests. PHP syntax checks passed for all changed PHP files and `git diff --check` passed.

The recommendation test's single synthetic SQLite run with 250 rows measured: legacy **17.25 ms / 1 SQL query / 250 Eloquent models hydrated / 9,941 response bytes / 1,620,512 bytes incremental PHP peak**; optimized cold **2.30 ms / 3 queries / 20 models / 9,941 bytes / 459,224 bytes peak**; optimized warm **2.12 ms / 2 queries / 20 models / 9,941 bytes / 174,800 bytes peak**. The cold candidate index still reads 250 compact raw rows. Timing and memory figures are local test observations, not production estimates; the SQL statement count increases because the revision is checked separately and final response rows are fetched separately. A cache lock timeout fails the request rather than bypassing the lock and triggering another rebuild.

## Remaining risks and next evidence

1. Apply the new movie catalog revision migration before deploying the code. Confirm any external imports/direct SQL movie writers increment the revision.
2. Measure recommendation latency, query plans, cache hit rate, candidate rows, hydrated rows and PHP memory with production-shaped catalog data. The synthetic cold path issues more SQL statements despite reducing hydration.
3. Validate Firebase cache store and lock sharing across web and queue workers. Add a multi-process/simulated contention test and verify remote timeout bounds.
4. Compare WhatsApp summary JSON against the previous implementation on representative data including timestamp ties/nulls, image/document previews, many messages per contact and unread/read transitions. Capture the deployed engine's query plan and response p95/DB buffers.
5. Continue home catalog work only after a response-wide cache key/invalidation plan is established. Collect request dimensions and DB plans before prioritizing chat/client changes.
6. Verify deployed clients against `CLIENT_OPTIMIZATION_PLAN.md` with versioned telemetry before claiming or changing client polling behavior.

No deployment, migration execution, production configuration change or service restart was performed. Applying the new migration is required before the recommendation optimization can serve requests successfully.
