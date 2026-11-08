# Last-month Top 10 deployment

Home responses read precomputed Top 10 cards from cache. They never aggregate
watch history, including on cache misses. Ranking and audience filtering remain
unchanged; episode activity is combined with its parent series.

After deploying, run:

```sh
php artisan home:warm-monthly-top-ten
```

Keep the Laravel scheduler running (`php artisan schedule:run` every minute).
It refreshes all adult/kids and age-restriction variants hourly. The scheduler
and web processes must use the same persistent cache store; an array cache will
not share the warmed results. With multiple web servers, use a shared store.
The existing `2026_09_29_000002_index_watch_position_for_monthly_rankings`
migration provides the date index used by the background aggregation.

Cache entries retain the last successful result for 35 days if a refresh fails,
with separate keys per ranked month. A cold cache or new month returns an empty
Top 10 shelf until warming finishes; other home sections still load normally.
After clearing the application cache, rerun the warm command. Monitor scheduler
failures because requests deliberately do not rebuild missing rankings.
