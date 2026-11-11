# Seasonal app themes — backend and Vue admin

The Laravel API and the Vue admin at `/admin/themes` implement the first release of the seasonal theme system. The standalone React admin directory is not involved. Default Zo Stream is bundled in code; Christmas is an editable starting template. No production theme is created or activated automatically by installing this feature.

## Installation

Deploy the code, run the new migration against the intended environment, and build the frontend:

```sh
php artisan migrate --path=database/migrations/2026_11_11_000001_create_app_theme_tables.php
npm run build
```

The development checkout currently has an empty `node_modules/.bin/vite` launcher. To build with its installed Vite package directly:

```sh
node node_modules/vite/bin/vite.js build
```

Use the existing configured application cache (Redis is supported) and `r2` disk. Its public URL must point to the approved CDN; the API never accepts arbitrary asset URLs. Uploaded PNG/JPEG/WebP images are limited to 2 MB and 4096 × 4096 pixels, use UUID filenames and immutable cache headers, and include SHA-256 integrity metadata. Configure the CDN to serve their actual image Content-Type. SVG/HTML uploads are rejected. Published assets are not overwritten or deleted by the manager.

## Admin workflow

1. Open **Engagement → Seasonal themes** and create a draft with a unique key such as `christmas_2026`.
2. Set colors, priority, platforms, Home-only effects and optional uploaded assets.
3. Enter each schedule in **Asia/Kolkata (IST)**. Christmas 2026 is December 1, 2026 00:00 through January 1, 2027 00:00, with the end excluded. Dates are explicit yearly windows; automatic annual recurrence and movable-holiday calculations are not implemented in this release. With no schedule, a published theme is available only to manual overrides.
4. Save, preview the saved draft on the desired platforms and approve it. The preview illustrates palette/assets and reports normalized effects; it does not reproduce each client's renderer or animate effects.
5. Enter an operational reason and confirm publication. A server-generated token binds the approval to the saved revision. Edits invalidate approval; conflicting revisions return HTTP 409.
6. Use **Restore Default** for an immediate global override, or select a published theme for a manual override. Overrides start immediately, pin the selected published version, require a reason and expire within 31 days. A new override replaces the old one. **Resume automatic schedules** cancels the override early.

Draft edits never change the live version. Unpublish removes automatic eligibility and cancels overrides for that theme. Rollback republishes a selected historical document as a new version, retaining its original schedules; inspect those schedules before confirming. Historical publication documents remain intact. All operations record the trusted authenticated actor. Existing `auth.token` and `admin.token` middleware protect every admin endpoint; publication, override and uploads are rate limited.

## Public API and client contract

`GET /api/v4/app/theme` is public. Send `X-Platform` or the existing `X-Client-Platform`; supported values are `web`, `android`, `ios`, `android-tv`, `webos`, `tizen`, `windows` and `macos`. Unknown platforms get Default.

The normal v4 response envelope contains:

- `data.schema_version` (currently 1), `theme_key`, immutable published `version`, `platform`.
- `tokens`: background, primary, secondary, accent, each a six-digit hex color.
- `effects.snowfall`: enabled and max_particles; `effects.animation`: enabled and allowlisted preset.
- `assets`: slot → {url, checksum, width, height}. An empty asset map is `{}`.
- `decoration_scope`: `home`.
- `valid_until`, `next_refresh_at`: the next relevant schedule boundary or override expiry, in UTC; null if there is no future scheduled change.
- `poll_interval_seconds`: 60. Polling also detects newly published themes and emergency overrides that were not known at the previous fetch.
- `fallback`: complete Default theme config, for client-side recovery.

Responses include a weak ETag, `Vary: X-Platform, X-Client-Platform`, `X-Server-Time` and `meta.server_time`. Send `If-None-Match` to get HTTP 304 when theme data is unchanged. Read the server clock header on 304 too. HTTP caches must revalidate; server-side resolved configurations are cached per platform and global publication generation for at most 60 seconds, never beyond the next known transition. Draft updates, publication, rollback, unpublish and override controls advance the generation transactionally, making the old cache inaccessible immediately after commit.

An active override wins, including a Default override. Otherwise the highest-priority eligible published theme wins; ties use latest publication time then version ID. Overlapping windows within one theme are harmless. Time windows are `[start, end)` and stored in UTC through an explicit cast, independent of the application's IST default. Resolution uses the application server clock; keep deployment clocks synchronized. No cron task is needed for activation.

Clients still need their own theme renderer. Fetch asynchronously without blocking startup, retain a bundled Default, refresh on foreground and at the supplied interval/boundary, and stop using an expired cached seasonal config while offline. Validate schema/tokens/presets; ignore unsupported optional fields, and fall back for failed asset downloads or invalid config. Use server time to account for device clock skew. Theme settings must not change artwork, player controls or payment screens. Decorative overlays must not capture touch, scroll or focus. Respect Reduce Motion, background state and power saving. TV responses disable motion initially; mobile particles are capped at 25, TV at 10, web at 35. Exact rendering and performance require client QA.

Schema or database/cache resolution failures return bundled Default and emit a `Seasonal theme fallback` warning. Missing R2 objects block publication; runtime CDN failures must also be handled by clients. Real R2 delivery and browser/device visual QA have not been exercised by the automated tests.

## Admin API

All paths below are under `/api/v4/admin` and require a valid administrator bearer token.

| Method | Path | Purpose |
| --- | --- | --- |
| GET / POST | `/themes` | Library/config templates or create draft |
| GET / PATCH | `/themes/{id}` | Detail/history/assets or save draft |
| POST | `/themes/{id}/preview` | Validate saved revision and return platform preview/token |
| POST | `/themes/{id}/publish` | Publish with revision, approval, preview token, reason |
| POST | `/themes/{id}/unpublish` | Unpublish with revision and reason |
| POST | `/themes/{id}/rollback` | Republish version_id with revision, approval and reason |
| POST | `/themes/assets` | Multipart theme_id, asset_key, image |
| GET | `/themes/audit?page=1` | Paginated operational audit log |
| POST | `/theme-overrides` | theme_id (null = Default), expires_at, reason |
| DELETE | `/theme-overrides` | Resume schedules with reason |

The draft `document` contains `priority`, `platforms`, `tokens`, `effects`, asset slot IDs and `schedules`. Every schedule requires offset-qualified ISO start_at/end_at values. PATCH requires the full document, name and current revision. Asset IDs must belong to that theme and slot. Only known keys/presets are accepted; remote executable code, CSS expressions and arbitrary layouts are not accepted.

## Verification

```sh
php vendor/bin/phpunit tests/Feature/AppThemeTest.php tests/Feature/AdminSpaTest.php tests/Feature/AdminConfigWhitelistTest.php tests/Feature/V4ApiTest.php tests/Feature/LegalPageApiTest.php
node --test tests/js/ThemesPage.test.mjs
node node_modules/vite/bin/vite.js build
```

Feature tests use an isolated in-memory SQLite database and fake R2 storage. They cover IST boundaries, cache invalidation, overlap/tie resolution, platform filtering, preview/revision conflicts, immutable rollback history, emergency expiry, validation, asset ownership/integrity, safe fallback and real auth middleware identity protection. Vue tests execute the actual setup script with Vue reactivity and intercepted API calls to exercise edit/save/preview/publish/override without touching live services.

## Ready-made presets and card decoration

The manager now lists twelve built-in presets: Default, Christmas, New Year,
Valentine, Chapchar Kut, Easter, Independence Day, Diwali, Halloween, Pawl Kut,
Zo Stream Anniversary and Mim Kut. **Use preset** creates an editable draft with
an automatic unique key and display name. There is no need to enter colors or
upload art first. Catalog defaults never mutate when a draft is edited. A preset
never publishes itself. Fixed holiday windows are filled with the ongoing or
next occurrence; festivals with movable/verified dates and Anniversary require
an explicit schedule (or an expiring manual override).

`GET /api/v4/admin/themes` adds `theme_presets` (key → name, description,
requires_dates, document) and `card_presets`. `POST /api/v4/admin/themes` accepts
`{"preset_key":"christmas"}` without name/key/document; optional explicit fields
can override that starting draft. Existing custom-draft requests still work.

Drafts and public payloads add optional `cards`:

```json
{"enabled":true,"preset":"snow","opacity":0.8,"movie_cards":true,"episode_cards":true}
```

Allowed motifs: none, snow, stars, hearts, woven, petals, tricolor, lights,
pumpkin, grain, confetti, maize. The API validates all keys, opacity (0–1) and
booleans. Existing documents without `cards` normalize to disabled, preserving
their previous appearance. Schema version remains 1: older clients ignore this
optional field. New Mac clients treat unsupported motifs as disabled.

Mac movie and episode cards render static, pointer-transparent corner motifs
and thin frames. These also apply to recommendation and episode shelves on
Details; Home decorations retain their separate Home-only scope. This extends
the initial document's artwork boundary as requested: source posters/thumbnails
are retained, while card presentation now has a decorative overlay. Access
badges, metadata, card dimensions, selection and playback/payment controls keep
their existing behavior. Admin preview shows movie and episode examples with
independent toggles. Woven/harvest motifs use simple geometric illustrations;
review any custom festival artwork separately.

No additional database migration is needed for presets/cards; deploy backend
and rebuild Vue together. Deploy the updated Mac renderer to display cards.
