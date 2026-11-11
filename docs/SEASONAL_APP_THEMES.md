# Seasonal themes — Use, Add schedule, Edit

The Vue admin at `/admin/themes` provides twelve ready-made presets and editable
custom themes. Administrators use **Use**, **Add schedule** and **Edit**. There
is no preview approval, publication button or mandatory reason in this flow.

## Deploy

Deploy Laravel and the rebuilt Vue assets together. The initial theme-table
migration must already exist; new installations run it first. This update adds
persistent theme selection and preset identity:

```sh
php artisan migrate --path=database/migrations/2026_11_11_000002_add_theme_selection.php
npm ci
npm run build
php artisan optimize:clear
```

The development checkout has an empty `node_modules/.bin/vite` launcher. Its
installed Vite package can be invoked directly:

```sh
node node_modules/vite/bin/vite.js build
```

Do not run the initial table migration again if it has already been applied.
The new migration backfills preset identity for existing generated preset keys,
retains their settings/schedules and leaves the initial selection at Default.
Existing scheduled versions and timed overrides remain valid. Deployments use
the existing application cache (including Redis) and R2 disk.

## Admin behavior

- **Use** selects the saved preset as the persistent base theme, without an
  expiry or publication step. Use also clears any legacy timed override.
  Active schedules take precedence; after their windows end the base returns.
  The UI explains this precedence and reports the theme actually resolved for
  the selected platform. Clients refresh at their next normal refresh.
- **Add schedule** opens that preset's existing settings, adds an IST time window
  and focuses its date fields. Fixed holidays suggest the ongoing/next window;
  movable festivals and Anniversary require verified dates. **Save changes**
  enables the schedule directly. Removing a window and saving disables it.
- **Edit** opens the saved settings, retaining previous colors, assets, card
  decorations and schedules. **Save changes** updates selected/scheduled
  appearances directly. **Use this theme** saves pending edits first, then
  selects the theme. Editing without saving changes no client output.
- Presets reuse their existing saved instance rather than creating a duplicate
  whenever Use/Edit/Add schedule is pressed. Custom themes remain supported.
- The active preset has a colored frame and **✓ In use** badge, derived from
  the API's actual resolved theme, not the editor selection. A different base
  selection is marked **Selected between schedules**. Highlight state refreshes
  after operations, platform changes and every minute while the page is visible.
- Preview remains optional. Saved version history and actor audit records remain
  available. Versions are stored internally for consistency; no approval action
  is required to create or use them. Revision conflicts return HTTP 409.

Default, Christmas, New Year, Valentine, Chapchar Kut, Easter, Independence Day,
Diwali, Halloween, Pawl Kut, Anniversary and Mim Kut are built in. All preset
settings remain editable; editing one saved instance does not mutate the catalog.

## API

All admin endpoints require the existing authenticated administrator bearer token
and are rate limited. Trusted actor identity comes from auth middleware.

| Method | Path under `/api/v4/admin` | Behavior |
| --- | --- | --- |
| GET | `/themes?platform=macos` | Presets, saved themes, active config, selected_theme_id |
| POST | `/themes` | Create preset/custom settings; reuse_preset reuses preset identity |
| GET | `/themes/{id}` | Settings, assets, saved versions, audit |
| PUT | `/themes/{id}/settings` | Save full name/document with current revision, including schedules |
| POST | `/themes/{id}/use` | Select saved theme with current revision; no approval/reason/expiry |
| POST | `/themes/{id}/preview` | Optional saved-config platform preview |
| POST | `/themes/assets` | Multipart theme_id, asset_key, image |
| GET | `/themes/audit?page=1` | Paginated operational audit |

`POST /themes` with `{"preset_key":"christmas","reuse_preset":true}` returns
an existing instance or creates one with automatic name/key and no enabled
schedule. Scheduling is explicit through Add schedule and Save changes. Without
reuse_preset, legacy custom-draft creation behavior is retained. Optional explicit
name/key/document can override creation defaults.

The existing PATCH draft, publish/unpublish/rollback and expiring override routes
remain for API compatibility. The current Vue interface does not require them.
An outstanding legacy override can be cleared with **Resume schedules**; Use also
clears it. Saving a theme used by a legacy override updates its pinned version.

## Resolution, caching and offline use

`GET /api/v4/app/theme` is public and uses `X-Platform` or `X-Client-Platform`.
Platforms: web, android, ios, android-tv, webos, tizen, windows, macos. Unknown
platforms safely receive bundled Default.

Precedence is: active legacy timed override, highest-priority eligible scheduled
version, persistent selected theme for that platform, bundled Default. Scheduled
ties use latest saved-version timestamp then ID. Dates are `[start,end)` and
stored in UTC using an explicit cast despite the app's IST timezone. Keep server
clocks synchronized. No cron is needed for activation.

The v4 envelope contains schema_version=1, theme_key, version, platform, tokens,
effects, assets, cards, decoration_scope, valid_until, next_refresh_at,
poll_interval_seconds=60 and a bundled fallback. Only known tokens, effect
presets and asset slots are accepted. Tokens are six-digit hex colors; arbitrary
CSS, HTML and remote executable layouts are rejected.

A selected seasonal theme with no upcoming transition receives a renewable
24-hour offline lease. This is a client cache bound, not an expiry on the stored
selection. Scheduled configurations expire at the next relevant boundary;
clients refresh on foreground, at the transition and at the polling interval.
Invalid or expired offline config uses bundled Default. Initial UI must not
wait on theme requests.

Responses include weak ETag, Cache-Control requiring revalidation, platform Vary,
X-Server-Time and meta.server_time. Clients handle HTTP 304 and use the server
clock reference. Resolved configurations are cached per platform and mutation
generation for at most 60 seconds, never beyond a known transition. Mutations
advance that generation inside the transaction. Resolution failures return
Default and emit a Seasonal theme fallback warning.

## Decorations and assets

`cards` is optional for older clients/documents:

```json
{"enabled":true,"preset":"snow","opacity":0.8,"movie_cards":true,"episode_cards":true}
```

Motifs: none, snow, stars, hearts, woven, petals, tricolor, lights, pumpkin, grain,
confetti, maize. Movie and episode switches are independent. Old documents
without cards normalize to disabled; unsupported client motifs remain static or
disabled. Native Mac supports these corner/frame overlays on Home, browse,
recommendation and episode cards, retaining artwork, metadata, access badges,
layout and interaction. Home effects retain their Home-only scope. Player and
payment controls are unaffected. TV motion is disabled initially; particle caps
are 25 mobile/Mac, 35 web and 10 TV. Respect Reduce Motion/power saving and pause
motion behind Details/player and in the background.

R2 PNG/JPEG/WebP images are limited to 2 MB and 4096² pixels, validated on upload,
UUID-versioned and served with immutable headers and SHA-256 integrity metadata.
The disk URL must be the approved CDN. Arbitrary asset URLs and SVG uploads are
not accepted. Saved/use configs check asset existence. Runtime CDN failures must
also fall back safely on clients. Mac permits HTTPS zostream.in subdomains and
checks decoded size and checksum. Cultural motifs are simple geometric samples;
custom festival artwork should be reviewed separately.

## Verification

```sh
php vendor/bin/phpunit tests/Feature/AppThemeTest.php tests/Feature/AdminSpaTest.php tests/Feature/AdminConfigWhitelistTest.php tests/Feature/V4ApiTest.php tests/Feature/LegalPageApiTest.php
node --test tests/js/ThemesPage.test.mjs
node node_modules/vite/bin/vite.js build
```

Feature tests use in-memory SQLite and fake R2, including migrations, UTC/IST
boundaries, conflicts, selection persistence, direct saves, schedule fallback,
cache invalidation, assets and trusted auth. Vue tests execute the real setup
script with Vue reactivity and intercepted requests. They verify direct Use,
schedule editing/saving, preset reuse, active highlighting and optional preview.
Real VPS migration/deployment and real CDN delivery are separate deployment steps.
