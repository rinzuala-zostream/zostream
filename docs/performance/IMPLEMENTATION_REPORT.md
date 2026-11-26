# Performance optimization implementation report

**Status:** Recommendation engine, Firebase configuration refresh and WhatsApp conversation summary code changes are implemented. Home catalog optimization remains deferred pending response-wide cache invalidation and compatibility evidence. Phase 2 chat implementation has not started. No migration was executed, production configuration changed, service restarted or deployment performed.

## Baseline and safety

- Git working tree was inspected before changes. The prior investigation report directory was already untracked from the preceding task; an unrelated untracked `docs/.DS_Store` was left untouched.
- The supplied production aggregates do not contain request durations, response sizes, query plans, stats reset timestamps or host CPU attribution. No local production-like DB dataset or staging measurements were available. Synthetic measurements are reported below and must not be treated as production results.
- Existing targeted tests were inspected and run: `AnalyticsCollectionSettingTest` and `WhatsAppWebhookTest`. The project emits PHP 8.5 deprecation notices for PDO SSL constants and Collision reflection; these did not fail the tests.
- API compatibility for WhatsApp conversation summary fields/order is kept. WhatsApp message history remains unpaginated to avoid silently truncating the existing response for older admin clients.

## Implemented change: analytics configuration refresh

**Original problem:** `AnalyticsCollectionSetting::enabled()` used a 15-second `Cache::remember()` around a synchronous Firebase read. Concurrent misses could all call Firebase; the class did not set explicit HTTP timeouts.

**Files/functions:** `app/Support/Analytics/AnalyticsCollectionSetting.php`, `enabled()` and `readRemote()`.

**Technical solution:** use the configured default cache store's lock to single-flight a cache miss, recheck the cached value after lock acquisition, and preserve the existing 15-second TTL and key. Firebase HTTP connect timeout is 2 seconds and total timeout is 4 seconds. Exceptions still report and return `false`, preserving fail-closed behavior. Admin `publish()` still writes the same cache key.

**Compatibility/security:** cache key and return type are unchanged; no analytics policy, authentication, API response or admin toggle behavior was intentionally changed. This relies on the production cache store supporting atomic locks and being shared between app workers. A lock timeout remains fail-closed, as required by the current policy.

**Invalidation:** `publish()` continues to replace the same value and TTL. Expiration refreshes from Firebase under the lock. No asynchronous refresh or last-known-good policy was introduced.

**Regression tests:** existing `tests/Unit/AnalyticsCollectionSettingTest.php` verifies cache reuse, publish replacement and expiry. It passed. Multi-process lock behavior and actual production cache configuration remain to be verified.

**Performance results:** no Firebase call-rate or latency benchmark was available. Expected effect is one remote call per cache expiry across workers when using a shared lock-capable store; this is an implementation expectation, not a measured result.

**Deployment/rollback:** deploy as an application release after confirming the configured cache driver has a working lock backend/table. Roll back the application commit to restore `Cache::remember()` behavior. No cache flush is required.

## Implemented change: WhatsApp conversation summaries

**Original problem:** `AdminWhatsAppInboxController::conversations()` hydrated the full WhatsApp message history, grouped all rows in PHP, and calculated counts and latest previews from those collections.

**Files/functions:** `app/Http/Controllers/Api/V4/AdminWhatsAppInboxController.php`, `conversations()`.

**Technical solution:** aggregate message and unread counts per phone in SQL, select the latest message and latest non-empty contact name with ordered correlated subqueries, and hydrate only the latest message row per contact for existing payload casts and preview formatting.

**Compatibility:** keeps every existing conversation response field, latest-message ordering, latest non-empty name selection, preview behavior and full conversations list. `messages()` remains unchanged and unpaginated because imposing a limit would truncate old-client responses.

**Index/cache strategy:** no migration or cache was added. Existing `whatsapp_messages` migration has indexes on `contact_phone`, `direction`, `status` and `message_at`; production `pg_indexes`/table engine must still be checked. The aggregate query should be benchmarked against production data and plan before rollout.

**Regression tests:** existing test checks all 55 conversations and unread counts. Added coverage checks latest preview/direction/status, latest non-empty name and counts. `WhatsAppWebhookTest` passed.

**Performance results:** no representative dataset/query plan was available. The code reduces PHP hydration from all messages to one per conversation; database scan/aggregate cost and actual latency improvement are unmeasured.

**Deployment/rollback:** deploy the application release and compare summary values against the old endpoint on a copy of production data. Roll back the release if plans or p95 worsen; no schema rollback is needed.

## Recommendation engine — implemented

`AlsoLikeController::alsoLike()` now reads a compact, shared candidate index (numeric key, title, genre and ranking flags) through the query builder, avoiding Eloquent hydration/date serialization for the candidate set. Full Eloquent models are fetched and serialized only for the final 20 IDs, preserving all existing response fields/casts while removing playback URLs as before. `MovieRecommendationIndex` preserves existing `similar_text` thresholds, winner selection, sequel/category ordering and random shuffles; it combines sequel and related classification into one pass and skips similarity calculations when the mathematically maximum possible percentage cannot pass the current threshold. The revisioned index and canonical-title ranking results use shared cache locks. Arbitrary legacy query titles are not stored as title-specific cache entries, limiting cache-key growth.

`MovieCatalogRevision` is a database-backed generation with a new `movie_catalog_revisions` migration. `MovieModel` bumps it before and after creates, deletes and changes to recommendation-affecting columns. The scheduled movie bulk status update also bumps around its query-builder write. View-count-only changes do not invalidate ranking indexes, and selected response rows are fetched fresh so their current views and other display fields are returned. The app-level movie mutation paths were searched; episode parent `updated_at` writes do not affect candidate membership/ranking. External SQL writers/imports must increment this revision themselves.

**Deployment order:** apply `2026_11_26_000001_create_movie_catalog_revisions_table` before enabling this code. The endpoint intentionally fails if the revision row is absent; it does not silently use a non-invalidating cache. Roll back the application first. The revision table can remain safely for rollback and may be dropped only after the old code is restored and cache keys have expired.

**Compatibility:** age restriction, kids mode, guest/special-user Mizo filtering and the pre-existing lack of platform-specific filtering are unchanged. Selection remains at most 20; fallback paths continue to shuffle per request. A primary-key ordering makes previously unspecified database tie order deterministic. Verify this against production if equal fuzzy scores are common.

**Regression/performance test:** `tests/Feature/AlsoLikeRecommendationTest.php` uses 250 synthetic SQLite rows, compares a copied legacy path with cold and warm optimized paths, checks response byte equality, output size, Eloquent hydration, cache revision invalidation, ranking/kids/Mizo/age behavior and date/playback-field serialization.

Single local run (PHP/SQLite in-memory; not production-representative): legacy **17.25 ms, 1 query, 250 Eloquent rows hydrated, 9,941 response bytes, 1,620,512-byte incremental PHP peak**; optimized cold **2.30 ms, 3 queries, 20 models hydrated, 9,941 bytes, 459,224-byte incremental peak**; optimized warm **2.12 ms, 2 queries, 20 models hydrated, 9,941 bytes, 174,800-byte incremental peak**. The compact candidate query still reads 250 rows on a cold revision/audience miss; these are raw narrow rows, not Eloquent models. This synthetic workload shows a higher SQL statement count but much less model hydration, PHP peak growth and elapsed time. Production catalog size, database plans and cache topology need measurement.

If cache lock acquisition times out while a candidate/ranking key is being rebuilt, the request fails instead of starting another cold rebuild. Cache backend errors other than lock timeout fall back to the uncached equivalent algorithm. This favors avoiding a stampede; monitor lock timeouts and ensure the 30-second lease comfortably exceeds rebuild time.

## Deferred Phase 1 item

### Home catalog

`MovieController::getMovies()` already pushes many category, kids, age and Mizo filters into bounded SQL queries and returns `movie.*` through legacy transformation. Full section requests execute one bounded query per section (plus optional watch list). Safe column reduction must account for `transformMovie()` and `trailerRecord`; cache keys must include user/watch-list state and audience filters. No caching or serialization change was made without a response snapshot and catalog mutation invalidation plan.

## Phase 2

No chat code was changed. Current implementation already has cursor-based message pages, idempotency keys, Redis presence and short-lived authorization caches. Proposed query/index changes remain gated on production plans. Client requirements are documented separately in `CLIENT_OPTIMIZATION_PLAN.md`.
