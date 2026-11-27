# Performance optimization implementation report

**Status:** Recommendation engine, Firebase configuration/ingestion, WhatsApp inbox, and home/movie listing optimizations are implemented. Production cache topology, indexes and query plans remain to be verified. No migration was executed, production configuration changed, service restarted or deployment performed.

## Baseline and safety

- Git working tree was inspected before changes. The prior investigation report directory was already untracked from the preceding task; an unrelated untracked `docs/.DS_Store` was left untouched.
- The supplied production aggregates do not contain request durations, response sizes, query plans, stats reset timestamps or host CPU attribution. No local production-like DB dataset or staging measurements were available. Synthetic measurements are reported below and must not be treated as production results.
- Existing targeted tests were inspected and run: `AnalyticsCollectionSettingTest` and `WhatsAppWebhookTest`. The project emits PHP 8.5 deprecation notices for PDO SSL constants and Collision reflection; these did not fail the tests.
- WhatsApp conversation summary fields/order remain stable. History pagination is opt-in; requests without pagination parameters retain the legacy full-array response.

## Implemented change: analytics configuration refresh

**Original problem:** `AnalyticsCollectionSetting::enabled()` used a 15-second `Cache::remember()` around a synchronous Firebase read. Concurrent misses could all call Firebase; the class did not set explicit HTTP timeouts.

**Files/functions:** `app/Support/Analytics/AnalyticsCollectionSetting.php`, `enabled()` and `readRemote()`.

**Technical solution:** use the configured default cache store's lock to single-flight a cache miss, recheck after acquiring it, and preserve the existing key and 15-second success TTL. Firebase calls have 2-second connect and 4-second total timeouts. Remote failures report, fail closed and cache `false` for 3 seconds to prevent repeated sequential synchronous calls. Admin `publish()` uses the same lock and replaces the cached state immediately. Admin config reads/writes also use the bounded Firebase HTTP timeouts.

**Compatibility/security:** cache key and return type are unchanged; no analytics policy, authentication, API response or admin toggle behavior was intentionally changed. This relies on the production cache store supporting atomic locks and being shared between app workers. A lock timeout remains fail-closed, as required by the current policy.

**Invalidation/retry:** `publish()` replaces the current cached setting under lock. Successful values refresh from Firebase after 15 seconds; failed reads are retried after the 3-second fail-closed cooldown. No stale last-known-good value is used, preserving fail-closed behavior.

**Regression tests:** `AnalyticsCollectionSettingTest` covers cache reuse, publish replacement, expiry, Firebase outage suppression, cache backend failure and a simulated overlapping cache miss. The simulated overlap is not a multi-process test; production cache topology and lock sharing remain to be verified.

**Performance results:** no Firebase call-rate or latency benchmark was available. Expected effect is one remote call per cache expiry across workers when using a shared lock-capable store; this is an implementation expectation, not a measured result.

**Deployment/rollback:** deploy as an application release after confirming the configured cache driver has a working lock backend/table. Roll back the application commit to the previous lock/timeout implementation if the cooldown or publish serialization causes issues. No cache flush is required.

## Implemented change: analytics ingestion and presence

`AnalyticsIngestionController::batchEvents()` sanitizes each properties object once, retains per-event `insertOrIgnore` results for exact duplicate handling, and commits all valid rows in one analytics database transaction. Only newly committed events reach `EngagementTracker`, using the same sanitized properties. A failed transaction leaves no partial batch; clients can retry safely with their existing stable event IDs. Privacy allow/block rules, payload validation, authentication, rate limits and response arrays are unchanged.

`AnalyticsPresenceStore::heartbeat()` pipelines the same eleven Redis commands in one network round-trip. User/device indexes, stale-member pruning, 150-second expiries and metadata remain intact. The 60-second client heartbeat contract and 8/minute endpoint rate limit are unchanged. No per-heartbeat SQL writes were present to remove.

Tests cover event idempotency, private search-field filtering, rollback followed by retry, presence command preservation and platform offline cleanup, plus Firebase lock/cache outages. The focused analytics and admin-whitelist suites passed 27 tests and 156 assertions. No production Redis/analytics DB latency measurements are available. The Redis pipeline uses the configured PhpRedis-compatible command API; confirm the effective production client before rollout.

## Implemented change: WhatsApp conversation summaries

**Original problem:** `AdminWhatsAppInboxController::conversations()` hydrated the full WhatsApp message history, grouped all rows in PHP, and calculated counts and latest previews from those collections.

**Files/functions:** `app/Http/Controllers/Api/V4/AdminWhatsAppInboxController.php`, `conversations()`.

**Technical solution:** aggregate message and unread counts per phone in SQL, select the latest message and latest non-empty contact name with ordered correlated subqueries, project only response/preview columns, hydrate only one lightweight message model per contact, and build each response in a single mapping pass. `messages()` accepts optional cursor pagination (`limit` 1–200, `before_at`, `before_id`), fetches newest-first within the bound and returns the page in legacy chronological order. It reports continuation through headers while retaining the raw array body.

**Compatibility:** keeps every existing conversation response field, latest-message ordering, latest non-empty name selection, preview behavior and full conversations list. History callers that do not opt in retain the previous unpaginated JSON array and chronological order; paginated callers get the same array shape plus `X-Has-More` and `X-Next-Before-*` headers. Pagination defaults to 50 rows when a cursor is supplied without a limit.

**Index/cache strategy:** no migration or cache was added. Existing migrations define single-column indexes on `contact_phone`, `direction`, `status`, `message_at` and `read_at`. Deployed DB engine/index state and the correlated aggregate plan remain unverified; no composite index is proposed without plan evidence.

**Regression tests:** coverage checks all 55 conversations/unread counts, latest preview/direction/status/name and counts, a 300-message/10-contact summary using one SELECT and 10 returned rows, and opt-in cursor traversal plus legacy full-array behavior. `WhatsAppWebhookTest` passed 11 tests and 39 assertions. The synthetic summary test asserts a JSON response under 10 KB; its 0.04s observed test time includes fixture inserts, so it is not an isolated request latency figure.

**Performance results:** synthetic coverage processes 300 stored messages into 10 summary rows using one SQL query and hydrates 10 summary models instead of all 300 messages. The database still scans/aggregates the matching history; query execution time, memory delta, and production p95 are unmeasured. Opt-in history retrieval is bounded to at most 200 rows; old clients remain unbounded for compatibility.

**Deployment/rollback:** deploy the application release and compare summary values against the old endpoint on a copy of production data. Roll back the release if plans or p95 worsen; no schema rollback is needed.

## Recommendation engine — implemented

`AlsoLikeController::alsoLike()` now reads a compact, shared candidate index (numeric key, title, genre and ranking flags) through the query builder, avoiding Eloquent hydration/date serialization for the candidate set. Full Eloquent models are fetched and serialized only for the final 20 IDs, preserving all existing response fields/casts while removing playback URLs as before. `MovieRecommendationIndex` preserves existing `similar_text` thresholds, winner selection, sequel/category ordering and random shuffles; it combines sequel and related classification into one pass and skips similarity calculations when the mathematically maximum possible percentage cannot pass the current threshold. The revisioned index and canonical-title ranking results use shared cache locks. Arbitrary legacy query titles are not stored as title-specific cache entries, limiting cache-key growth.

`MovieCatalogRevision` is a database-backed generation with a new `movie_catalog_revisions` migration. `MovieModel` bumps it before and after creates, deletes and changes to recommendation-affecting columns. The scheduled movie bulk status update also bumps around its query-builder write. View-count-only changes do not invalidate ranking indexes, and selected response rows are fetched fresh so their current views and other display fields are returned. The app-level movie mutation paths were searched; episode parent `updated_at` writes do not affect candidate membership/ranking. External SQL writers/imports must increment this revision themselves.

**Deployment order:** apply `2026_11_26_000001_create_movie_catalog_revisions_table` before enabling this code. The endpoint intentionally fails if the revision row is absent; it does not silently use a non-invalidating cache. Roll back the application first. The revision table can remain safely for rollback and may be dropped only after the old code is restored and cache keys have expired.

**Compatibility:** age restriction, kids mode, guest/special-user Mizo filtering and the pre-existing lack of platform-specific filtering are unchanged. Selection remains at most 20; fallback paths continue to shuffle per request. A primary-key ordering makes previously unspecified database tie order deterministic. Verify this against production if equal fuzzy scores are common.

**Regression/performance test:** `tests/Feature/AlsoLikeRecommendationTest.php` uses 250 synthetic SQLite rows, compares a copied legacy path with cold and warm optimized paths, checks response byte equality, output size, Eloquent hydration, cache revision invalidation, ranking/kids/Mizo/age behavior and date/playback-field serialization.

Single local run (PHP/SQLite in-memory; not production-representative): legacy **17.25 ms, 1 query, 250 Eloquent rows hydrated, 9,941 response bytes, 1,620,512-byte incremental PHP peak**; optimized cold **2.30 ms, 3 queries, 20 models hydrated, 9,941 bytes, 459,224-byte incremental peak**; optimized warm **2.12 ms, 2 queries, 20 models hydrated, 9,941 bytes, 174,800-byte incremental peak**. The compact candidate query still reads 250 rows on a cold revision/audience miss; these are raw narrow rows, not Eloquent models. This synthetic workload shows a higher SQL statement count but much less model hydration, PHP peak growth and elapsed time. Production catalog size, database plans and cache topology need measurement.

If cache lock acquisition times out while a candidate/ranking key is being rebuilt, the request fails instead of starting another cold rebuild. Cache backend errors other than lock timeout fall back to the uncached equivalent algorithm. This favors avoiding a stampede; monitor lock timeouts and ensure the 30-second lease comfortably exceeds rebuild time.

## Implemented: home catalog and movie listings

Legacy `App\Http\Controllers\MovieController::getMovies()` and New `App\Http\Controllers\New\MovieController::getMovies()` now cache serialized public response arrays through `PublicMovieCatalogCache`. V4 `CatalogController::home()` delegates to the New path. Cache identity includes the endpoint scope, ID/range/category/category type, platform query and header, kids mode, age restriction, enable mode and Mizo-only audience class. It does not include raw user IDs. New-home watch-list results are queried and serialized separately per request and are never stored in the public shared value.

The helper uses `MovieCatalogRevision`, a 30-second TTL and a shared cache lock. `MovieModel` bumps the generation before/after creates, all changed model attributes and deletes, so publish/disable/artwork/metadata changes invalidate public results. The admin trailer replacement transaction bumps it around relation-table deletion/insertion. Scheduled movie updates already bump around bulk status writes; episode publication now bumps around its direct parent `updated_at` bulk write. Cache or revision-backend failures fall back to the uncached DB path. Applying the existing catalog revision migration is required for cache hits.

List queries now eager-load only `trailerRecords.id`, `movie_id` and `url`, the relationship consumed by `transformMovie()`. This removes per-movie trailer reads while retaining complete movie columns required by the existing model-as-JSON contract. Public index/latest and filter listings receive the same eager-load fix. Genre listing preserves the legacy tokenization and `any`/`all` matching behavior by scanning compact `num,genre` rows, then hydrates only the selected page with trailers. The two home paths removed post-query collection filters after confirming the equivalent kids/Mizo/age predicates are applied in SQL. The personalized watch-list query also batches its trailer relation; a warm fixture request with two private items uses three SELECTs total (revision check, watch-list query, trailer query).

**Compatibility/privacy:** legacy response keys, shelf ordering and serialized movie fields remain. The synthetic test compares cold and warm response JSON byte-for-byte. The root legacy endpoint checks its API key before cache access. Personalized watch history is not cached; subscriptions and entitlements are not included in these shared values. Current platform behavior remains unchanged (the old home method did not apply platform-specific filtering), but platform still partitions cache entries for compatibility with all request dimensions.

**Regression coverage:** `HomeSeriesOrderingTest` checks shelf ordering, kids/Mizo/age audience separation, platform/category cache dimensions, per-user watch-list isolation, create/update/disable/delete invalidation, admin trailer invalidation, warm-cache DB query reduction, legacy root listing and genre token/page behavior. The home/catalog, recommendation, V4 API, playback security and movie notification regression group passed **99 tests and 1,042 assertions**.

**Synthetic cache result:** on the in-memory SQLite fixture with two movies repeated in five shelves, one observed cold home request took **51.56 ms / 18 SELECTs**; the warm request took **0.61 ms / 1 SELECT** (the catalog revision lookup). Both responses contained **10 shelf entries and 5,234 bytes** and were identical. The warm hit hydrates and serializes no movie rows. This was one local cold/warm comparison, not a direct replay of the previous endpoint or a production estimate. Memory and production query plans were not measured.

**Indexes/risks:** no index was added. The repository contains trailer-table index definition and movie-table alteration migrations but no authoritative base movie-table index schema; deployed DB indexes and plans still need inspection. The existing `movie_catalog_revisions` migration must be applied. With database cache configured by default, confirm `cache_locks` and shared cross-host locking in the production environment.

## Phase 2

No additional optimization work was included in Phase 2.
