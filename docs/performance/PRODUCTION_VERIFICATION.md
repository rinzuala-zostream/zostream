# Production verification and rollout checklist

This checklist is for a later approved staging/canary release. Nothing in this document was run against production.

## Before rollout

- Capture a fixed interval of Nginx route/method/status/request time/upstream time/body bytes, PHP-FPM busy workers and sampled traces, host CPU/memory, and DB query stats.
- For PostgreSQL, capture stats reset time, table/index sizes, current indexes and `pg_stat_statements` deltas. For the actual database engine, collect plans for WhatsApp summary SQL. Do not apply speculative indexes.
- Compare WhatsApp summary response values/order against the prior implementation on a production-shaped copy, including null timestamps/names, tied timestamps, media previews, inbound unread and large contact history.
- Confirm the effective cache driver supports shared atomic locks between all application instances and that lock expiry exceeds worst-case Firebase timeout (10s lock vs 4s HTTP timeout in this change).
- Validate Firebase connect/total timeout behavior against a controlled test endpoint or SDK mock; verify admin toggles still update the same cache key and exceptions still fail closed.

## Canary monitoring

Monitor at least one normal peak and one quieter period. Compare like-for-like route volume and data size.

- Analytics: Firebase reads per cache expiry, cache hit/miss, lock wait/timeouts, endpoint p50/p95/errors, observed stale/fail-closed behavior.
- WhatsApp inbox: endpoint p50/p95/p99, SQL execution/buffers/temp work, conversations returned, PHP memory and response bytes. Diff response JSON fields/order against old behavior.
- Service health: host CPU, PHP-FPM saturation and DB connection utilization.
- Do not interpret lower request volume or different data distribution as a code improvement; normalize by calls and returned records.

## Rollback

- Roll back the application release if the WhatsApp query plan or endpoint latency worsens, JSON compatibility differs, or Firebase lock errors cause unexpected fail-closed behavior.
- No schema migration is part of this change. No production configuration or cache key was changed.
- Rollback restores the previous PHP implementation; keep existing cache values until their 15-second TTL expires. Do not flush shared caches as a rollback step.
- Record release IDs, metric windows, error examples (with secrets/PII removed), and the rollback time.

## Go/no-go evidence

Proceed beyond canary only after the above checks show compatible responses, effective shared locking, bounded Firebase calls, and no material p95/CPU/DB regression. Report measured values and workload conditions; do not claim a CPU reduction based only on fewer hydrated rows or request frequency.
