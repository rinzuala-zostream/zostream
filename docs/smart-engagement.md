# Smart engagement notifications

Smart engagement is an optional backend feature. Existing movie/episode, manual,
birthday and expiry notifications retain their triggers and delivery paths.
Their successful sends only record a short cache marker; they never wait for a
smart-notification queue, limit or quiet-hours check. A smart push waits when an
existing notification was sent recently, including a subscription WhatsApp reminder.

## Implemented rules

| Priority | Rule | Earliest eligibility |
| --- | --- | --- |
| 1 | Continue watching | 24 hours after an observed unfinished playback |
| 2 | Watchlist | 3 days after adding an unwatched movie |
| 3 | Unused active subscription | 3 days after the plan starts, with no observed viewing since its start |
| 4 | Comeback | Latest applicable 7/14/30-day inactivity milestone; no replay of missed milestones |
| 5 | Search interest | 12 hours after selecting a search result without watching it |
| 6 | Post-watch discovery | 24 hours after completing a movie |

Eligibility is followed by the next suitable send slot, not immediate delivery.
The default slot is 18:00 IST. After at least three observed viewing sessions,
the most frequent viewing hour minus one hour is used, clamped to 09:00–20:00.
Quiet hours are **21:00 inclusive to 09:00 exclusive, Asia/Kolkata**. A worker
that runs late rechecks quiet hours and postpones. Smart Android, APNs and web
pushes expire after at most one hour and no later than 21:00 to avoid retaining
an offline-device push overnight. FCM acceptance is recorded as `sent`; it does
not prove device delivery. OS display timing is ultimately controlled by the device.

Only one rule wins per user. The minimum gap between smart pushes is 48 hours,
which also enforces at most one per day. Two unopened sends increase the gap
to seven days; five stop automation until a valid notification-open event resets
the counter. Existing pushes defer smart pushes by at least six hours. Active
playback also postpones a smart reminder.

## Data and load

- New, accepted authenticated analytics events update one compact user summary
  per event batch. Playback summaries update per-user/per-type/per-content progress.
  Stale, duplicate analytics revisions and far-future timestamps do not overwrite
  newer progress. These optional writes are isolated from playback/analytics errors.
- Trusted V4 progress and authenticated stream stops also update this separate
  progress store. Legacy unauthenticated progress submissions cannot enroll users.
  The legacy `watch_position` schema, timestamps and matching semantics are preserved;
  smart reminders use their own server-observed timestamps and typed content keys.
- No global user or analytics-history scan/backfill occurs. Only users with observed
  activity enter the system. Comeback additionally requires an observed app-open or
  foreground event; playback alone is insufficient evidence of app inactivity.
- The scheduler checks at most 100 due summaries every five minutes. A summary
  is normally revisited after six hours, or after the send cooldown. Due deliveries
  are dispatched in batches of the same size.
- A separate `engagement` queue and global 10-per-minute cap bound optional send
  traffic. Smart delivery never executes inline on an app or payment request.
- Recommendations use a cached pool of up to 50 eligible popular/genre-related
  titles; they do not spawn Python or retrain the existing recommender. Legacy
  watch positions and the new progress store suppress watched suggestions.
- Catalogue status, account/token, subscription, PPV, age restrictions, Mizo-only
  policy, opt-out, source activity and current rule relevance are checked again
  by the worker. PPV and age-restricted content are excluded. Unknown profile mode
  defaults to the kids catalogue until the authenticated app supplies its mode.
- New tables have due-work and user/content indexes. The second migration adds
  missing user-prefix lookup indexes to existing tables, skipping existing ones.
  Index builds can take time on large production tables; apply migrations during
  an appropriate maintenance window and review the database's DDL behavior.

The optional summary writes add some database work; this has been tested locally,
not load-tested against production traffic. Start with one dedicated worker and
the conservative defaults, then monitor queue age, planner duration and DB latency.

## Rollout

Both switches default to false. No production configuration or database is changed
by adding the implementation to the repository.

1. Deploy the code and apply the engagement migrations using the normal deployment
   process. The new tables use the primary DB; no analytics-history migration is needed.
2. Use a shared cache supporting atomic locks (the existing Redis cache is suitable).
   All web, scheduler and worker instances must share it.
3. Enable only `ENGAGEMENT_TRACKING_ENABLED=true`, leave `ENGAGEMENT_ENABLED=false`,
   then refresh cached configuration. Verify real analytics/progress coverage before
   drawing conclusions about inactivity or unused subscriptions.
4. Preview without creating deliveries or queue jobs:

   ```bash
   php artisan engagement:queue --dry-run --limit=25
   ```

   Output contains hashed user IDs, selected rule, content ID and IST send slot.
   A dry run may populate the bounded catalogue cache but does not alter deliveries
   or summary scheduling.
5. Start a dedicated supervised worker, using a queue connection with `retry_after`
   greater than the 60-second worker timeout:

   ```bash
   php artisan queue:work database --queue=engagement --sleep=3 --tries=5 --timeout=60 --max-time=3600
   ```

   The existing Laravel scheduler entry must be running. Set
   `ENGAGEMENT_QUEUE_CONNECTION` to match the worker connection; do not use `sync`.
6. Set `ENGAGEMENT_USER_ALLOWLIST` to comma-separated internal test-user UIDs and
   `ENGAGEMENT_ENABLED=true`. Refresh config and restart workers through the normal
   deployment process. Review pending/cancelled/sent/opened/played outcomes, then
   expand the allowlist or clear it to include all tracked users.

`ENGAGEMENT_BATCH_SIZE` (maximum 500 per command) and
`ENGAGEMENT_PUSHES_PER_MINUTE` tune load. Set `ENGAGEMENT_ENABLED=false` and restart
workers to stop smart delivery while leaving existing notifications running.
Tracking can be disabled separately. Already accepted pushes cannot be recalled.
Normal model-based account deletion erases the new user summary, content progress
and delivery records even when engagement switches are off.

The unique journey key and per-user cache lock prevent duplicate planned sends.
Jobs recheck persisted state after locking. Queued jobs older than 30 minutes can
be recovered by the planner. A row in `sending` after a crash represents uncertain
external delivery and is deliberately not automatically resent. Inspect it using
FCM/provider evidence before resolving it. A failed FCM attempt is also not retried
blindly; invalid/expired tokens must be refreshed through the existing push-token API.

## Vue admin controls

Open **Engagement → Smart notifications** (`/admin/notifications/smart`). Each of
six rules has an enabled switch, notification title and message template. Use
`{title}` to insert the actual recommended movie/episode title. Empty templates
and unknown placeholders are rejected. Defaults remain available before the new
`2026_11_22_000001_create_engagement_rules_table` migration is applied; saving
settings requires that migration. Workers read saved templates without a restart.

Disabling a rule cancels pending/queued deliveries for that rule. Enabling it again
allows still-eligible, unsent cancelled journeys to be planned on their next check.
Sent, failed and uncertain sends are never automatically retried. The global server
rollout switches, quiet hours, recipient preferences and cooldowns still apply.

The page includes a live text preview, per-rule accepted/opened/played counts for
rows created within the last 30 days, and a test action using a saved template.
Tests require a published movie public ID and an explicit device FCM token; they
cannot broadcast and do not create engagement deliveries or change smart counters.
An accepted test push still counts as existing push activity for that device.
Test pushes are marked `smart_engagement_test`, without a real notification ID.

Admin-authenticated routes:

```text
GET  /api/v4/admin/notifications/smart
PUT  /api/v4/admin/notifications/smart/{rule}
POST /api/v4/admin/notifications/smart/test
```

Movie pushes include the legacy `key` and explicit `movie_id` for the movie/series
public ID. Episode reminders route to their parent series details, rather than
mistaking an episode ID for a movie ID. Android retains that destination through
startup verification and login; Apple supports both payload formats. Both mobile
clients report smart `notification_id` with notification-open analytics. Deploy the
updated app builds for those client changes to take effect. Playback access and
profile checks continue through the normal details flow.

## App contract

Authenticated V4 routes:

```text
GET /api/v4/account/engagement-preferences
PUT /api/v4/account/engagement-preferences
```

PUT accepts `enabled` (boolean) and/or `content_mode` (`kids` or `adult`). The
authenticated session always determines the user. Opt-out cancels pending smart
pushes and leaves all existing push types untouched. Quiet hours cannot be bypassed.

Apps using the existing analytics API should send:

- `app_opened` / `app_foregrounded` with real occurrence times.
- `search_result_selected` properties `content_id` and `content_type: "movie"`.
  Raw search queries remain excluded by the existing sanitizer.
- `notification_opened` with the smart payload's `notification_id` property.
- Existing playback session summaries with typed content IDs and completion data.

Smart FCM data includes the existing movie-routing `key`, plus `type:
smart_engagement`, `rule`, `notification_id`, `content_id`, `content_type`,
`movie_id`, and (for an episode) `episode_id`. Client support must be verified for
episode resume routing, preferences and notification-open analytics. A matching
playback within seven days is recorded as `played_at`; this is attribution, not
proof of incremental engagement. A/B holdouts and causal lift reporting are future work.

This implementation covers the six core behavioral rules. Weekend campaigns,
actor/rating preferences, achievements and automated experiments remain later
rollout phases. It deliberately does not invent activity for old/instrumentation-free
clients or alter the existing recommender's public response.

## Verification

`tests/Feature/SmartEngagementTest.php` covers timing boundaries, progress ordering,
rule selection, cooldowns, dry-run behavior, queue dedupe, send-time cancellation,
active playback, opt-out, content filters, uncertain-send behavior, expiry payloads,
and open/play ownership. Existing analytics, V4 playback, movie/episode notification
and subscription maintenance tests provide regression coverage.
