# My Zo Stream customer dashboard

The existing `/account/stats` page now has **Overview**, **Watching** and
**Account** tabs. Both the mobile app's one-use web-session bridge and browser
token login use the same dashboard. No native app update is required for these
page changes; users open the existing My Zo Stream link.

## User flow

- Overview shows the customer's name and username, watch time, distinct titles,
  recorded data usage, current subscription, Continue Watching, activity and most
  watched titles. Continue Watching covers recent unfinished titles across all
  dates; its Open in app link uses the existing title deep link. The app chooses
  playback/resume from its own saved progress.
- Watching supports title search, movie/episode filtering, latest/watch-time/data
  sorting, expandable details and pagination.
- Account shows current and past plans, subscription payment history, status and
  device filters, paid PDF receipts, CSV export and linked devices. Current
  subscription status is separate from payment status.
- Today, last seven days, this month, last month and custom date ranges are
  available. Custom ranges are limited to one year per request, can be in earlier
  years, and cannot end in the future. Account date filtering applies to payment
  history; current plans and devices remain current.
- Each section has its own loading/error/retry state. Date/search/page changes
  keep existing content visible while updating and discard stale responses.

## Data and access

New read-only routes exist beneath both `/account/stats` (server-side bridge
session) and `/api/v4/account/stats` (`auth.token` with the v4 response envelope):

| GET suffix | Result |
| --- | --- |
| `/overview` | Profile summary, viewing metrics, daily activity, Continue Watching |
| `/watching` | Date-filtered/searchable/sorted title records and pagination |
| `/account` | Sanitized subscriptions and devices, without device tokens |
| `/payments` | Subscription payments for the selected dates/status/device |
| `/payments/export` | Every matching payment as CSV, across all result pages |
| `/payments/{id}/receipt` | A paid, account-owned payment receipt PDF |

Date query: `period=today|week|month|last_month|custom`, with `from=YYYY-MM-DD`
and `to=YYYY-MM-DD` for custom. Watching accepts `q`, `type=all|movie|episode`,
`sort=latest|watch_time|data`, `page` and `per_page` (maximum 20). Payments accept
`status=all|paid|pending|failed|refunded`, `device=all|mobile|tv|browser` and
pagination. The old `/account/stats/data` and `/api/v4/account/stats` routes remain
available.

The server determines identity. Browser query parameters cannot override the
bridge session, and API identity is verified by `auth.token`. Other accounts'
payment IDs return 404. Pending, failed and refunded records have no receipt
download. PPV payments are outside the subscription-history query. Private
payment metadata and device tokens are never sent to the dashboard.

Receipt numbering follows the existing payment invoice reference. Amount,
currency, transaction and validity come from the payment record, including the
existing WiFi amount convention where applicable. Historical expiry is never
replaced with a renewed subscription's current expiry. Missing details are marked
as not recorded. The document is a payment receipt, not a tax invoice. PDF uses
the local Zo Stream logo and the existing Dompdf dependency; remote assets are
disabled. CSV neutralizes spreadsheet formula prefixes and includes UTF-8 BOM.

Viewing prefers legacy watch records when present for a title, with analytics
fallback for titles without recorded watch time. This avoids counting the same
viewing twice. Daily activity follows the same source selection. Data usage
prefers measured analytics bytes per title and falls back to legacy transfer
records. Zero measured bytes and missing telemetry remain distinct. This page
does not claim to measure all device/network traffic.

Viewing aggregates are cached server-side for 30 seconds per account and resolved
date range. Overview, Watching, searches and pagination reuse the same aggregate;
filters still apply independently. Newly recorded viewing may take up to 30
seconds to appear. Payments, current subscriptions and devices are not cached by
this optimization. Continue Watching reads unfinished positions in batches of
32 and stops once four visible titles are found, rather than loading the entire
history before pagination. The existing mobile history endpoint keeps its full
pagination behavior.

## Verification

```bash
php vendor/bin/phpunit tests/Feature/CustomerDashboardTest.php tests/Feature/PublicPagesTest.php tests/Feature/V4ApiTest.php tests/Feature/V4MobileAdapterTest.php
node --test tests/js/CustomerStats.test.js tests/js/PublicAuth.test.js tests/js/NexframeDates.test.js
npm run build
```

The browser test uses mocked data only. With Playwright/Chromium installed, run
the isolated preview and the test in separate terminals:

```bash
node node_modules/vite/bin/vite.js --config tests/browser/customer-stats.vite.config.js
node tests/browser/customer-stats.mjs
STATS_BROWSER=webkit node tests/browser/customer-stats.mjs
```

For an external Playwright install, set `PLAYWRIGHT_MODULE` to its `index.mjs`
and `PLAYWRIGHT_BROWSERS_PATH` to its browser cache. The test checks tabs, section
retry, search, custom dates, payment filtering, PDF/CSV downloads, and mobile
overflow. Mock screenshots are written to the OS temporary directory.

The browser checks also cover app bridge sign-in with a stale browser token and
with localStorage blocked. Storage access failures must not stop the initial
cookie-authenticated stats requests. Browser OTP sessions and device IDs fall
back to memory for the current tab when storage is unavailable. The stats HTML
includes loading/reload content so failed JavaScript downloads leave a usable
retry link.

## Deployment

Deploy the backend files, new receipt view and compiled frontend together. Use
the existing deployment process to install dependencies/build assets and clear
cached routes/views:

```bash
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
```

The two `2026_11_02` migrations add user/date indexes on `watch_sessions`,
`bandwidth_logs` and analytics `playback_sessions`. They skip missing tables and
equivalent existing indexes. Use the configured shared cache store for reuse
across requests; the `array` store is intended for tests and does not provide
cross-request reuse. Index creation time depends on table size, so schedule the
migrations through the normal production database rollout process. These changes
do not alter payment/subscription records. Verify one real account and its payment receipt
after deployment, including the app bridge and Safari/iOS download behavior.
