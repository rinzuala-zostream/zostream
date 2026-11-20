# Zo Stream API v4

`/api/v4` is the canonical API for every actively maintained Zo Stream client:

- Android (`ZoStreamNew`)
- iOS
- Android TV
- Web/PWA
- LG webOS and Samsung Tizen
- Admin

The older `/api/*` and `/api/v3.0/*` contracts are migration-only. New client
code must not add dependencies on them.

## Single-use activation QR grants

These routes are separate from existing login and payment `/qr-sessions`.
Administrator-created grants activate PPV or a subscription without checkout.

Admin routes require a bearer token with administrator access. Admin sessions
are revoked after seven days without authenticated admin API activity.
Authenticated requests reset that idle deadline; active sessions retain the
existing rolling 30-day refresh-token lifetime.

| Method | Path under `/api/v4` | Purpose |
| --- | --- | --- |
| GET | `/admin/redemption-codes/options` | Published PPV movies, series/seasons/episodes and active subscription plans |
| GET | `/admin/redemption-codes?page=1&status=available` | Paginated history; status may also be `redeemed`, `cancelled`, or `expired` |
| GET | `/admin/redemption-codes/history?page=1&status=available` | One row per label, with full card/status counts; filter selects labels containing a matching card |
| GET | `/admin/redemption-codes/history/codes?label=Campaign&page=1` | Cards under an exact label, sorted by PDF reference; optional card `status` filter |
| POST | `/admin/redemption-codes` | Create a single-use code |
| POST | `/admin/redemption-codes/batches` | Create/recover A4 sheets using a client-generated batch UUID |
| GET | `/admin/redemption-codes/batches/{batchId}` | Reopen a batch with current code statuses |
| POST | `/admin/redemption-codes/{id}/cancel` | Cancel an unused code |

Create a PPV code with `{"label":"Movie gift","type":"ppv","movie_id":"<movie.id>","rental_days":7}`.
For series, include `content_type: "season"` or `"episode"`, and put the selected
`season.id` or `episode.id` in the existing `movie_id` field. Omitted
`content_type` defaults to `movie`, keeping old codes compatible. Example:
`{"label":"Season pass","type":"ppv","content_type":"season","movie_id":"<season.id>"}`.
The options response has `movies` plus `series`, each containing its `seasons`
with `is_pay_per_view`, poster and individually purchasable `episodes`. Never
use a parent series ID as a substitute for a selected season or episode.
Create a subscription code with `{"label":"30-day gift","type":"subscription"}`.
Omit `plan_id` for new subscription codes. The backend resolves the selected
device's plan and grants 30 days; old plan-bound codes remain supported.
Either accepts optional `expires_at` as a future ISO timestamp. PPV rental days
range from 1 to 365 and default to 7. Movie identifiers use `movie.id`, not `num`.
The response includes `qr_payload: "zostream://redeem/<64 lowercase hex characters>"`.
QR images should encode that exact string, keeping this namespace distinct from
legacy session tokens. Do not send these codes to the login/payment verify route.

For A4 sheets, POST the same code fields plus `batch_id` (a client-generated UUID)
and `page_count` (integer 1–20) to `/admin/redemption-codes/batches`. Each sheet
contains **12 subscription codes** or **8 PPV codes**. The response has `batch_id`, `page_count`,
`codes_per_page`, `label`, `type`, `target_name` and `codes`. Creation is atomic.
Retry with the exact same batch UUID and body after a lost response to recover
the same codes. Reusing that UUID for different parameters or another admin
returns 409. The print UI keeps an uncertain request in session storage and
reuses it on retry. Reopening/printing a batch never generates new codes.
PPV batches also return `content_type`, `content_id`, `poster_url`, `series_title`,
`scope_label`, `season_number`, `episode_number` and `display_name`. The PPV
print layout has two columns/four rows with posters and scope details. Old PPV
batches retain every issued code but reflow to eight tickets per page; the
returned page count reflects the current print layout.

The Vue history uses the grouped `/history` endpoint (25 labels per page).
Labels shared across batches appear once. Each summary includes `code_count`,
`batch_count`, `single_count`, `type`, `type_count`, `latest_at`, and
`available_count`, `redeemed_count`, `expired_count`, `cancelled_count`. Filtering
labels by status does not narrow these counts. Summary rows contain no QR token
or payload. Opening a label requests `/history/codes` (25 cards per page) and
shows `qr_number`, the exact zero-padded ID printed on the PDF. Existing flat
history and batch printing endpoints remain supported; no schema change is
required for the grouped history.

Mobile integration (implemented in Android and iOS):

1. Recognize the exact `zostream://redeem/{token}` payload and validate the token
   as 64 lowercase hexadecimal characters.
2. `GET /api/v4/redemptions/{token}` with the authenticated owner's bearer token.
   It returns the target, validity, and only that account's non-blocked devices.
   Generic subscription codes list all device types; old plan-bound codes filter
   devices by their plan type. Each has `eligible`,
   `reason`, and `action` (`ppv`, `add_new`, or `extend`). Scanning does not reserve
   or consume the QR. Do not activate on scan alone.
3. After the user selects an eligible device, immediately call
   `POST /api/v4/redemptions/{token}/redeem` with `{"device_id":<numeric n_devices.id>}`.
   The account identity comes from the access token. Both customer routes require
   the account owner device and are rate limited to 30 requests per minute.
4. On success, refresh device/subscription/rental data. The result contains
   `type`, `action`, `device_id`, `subscription_id`, `movie_id`, `expires_at`,
   `payment_history_id`, and `already_redeemed`. These fields are inside `data`
   in the standard v4 response envelope.

PPV access starts immediately and binds to the selected device token using the
existing rental authorization. An episode grant authorizes only that episode;
a season grant authorizes episodes whose actual parent is the granted season,
including premium episodes not individually priced as PPV. Stored content type
prevents movie/season/episode ID collisions from granting wider access. The
inspect response's `target.name` includes series and scope so existing mobile
clients show the right content without changing the redemption request.
Generic subscription codes resolve a plan after
device selection. An active subscription keeps its plan and extends by exactly
**30 days** from existing expiry. New/expired subscriptions start **30 days**
today, selecting an active plan of exactly 30 days matching the device type
(lowest price, then ID if several match). Longer expired plans are not reused;
there is no fallback to another duration. If no active 30-day plan exists,
inspection marks the device ineligible and redemption returns 422 without
consuming the QR. An active subscription can
keep a retired plan; a new subscription requires an active plan. Previously
issued plan-bound codes keep the old plan-duration/same-plan rules.
`new_subscription_days` and `extension_days` remain 30 for generic codes, so the
existing Android and iOS device selection screens need no contract change.
Existing subscription sharing semantics apply to
devices already linked to that subscription. Activation writes a successful
zero-amount payment history with gateway `admin_grant` and QR metadata.

Only a successful transaction consumes the QR. Row locks serialize redemption
and cancellation. A retry by the same account for the same device returns the
original result without extending again, even after the QR expiry. Another
account/device receives 409. An expired unused QR returns 410; an invalid or
unowned device returns 404; blocked/unsupported devices or unavailable targets
cannot activate. Validation failures return 422. Keep the same token and device
when retrying after an uncertain network response. An already used QR cannot
be inspected again via GET; retry the original POST to recover its result.

Deployment requires all three redemption migrations (codes, print batches,
PPV content type) and updated admin assets.
Updated Android and iOS builds recognize the activation namespace before the
legacy session route. They persist an in-flight device selection and offer a
same-device POST retry after an uncertain network result, including when the
sheet is reopened by scanning the same QR. A definitive 4xx rejection (except
408/429) clears the pending selection; successful activation refreshes the home
data. Deploy the migration/backend before distributing the updated clients.

## Contract

Every request should send:

```http
Accept: application/json
X-Client-Platform: android|android-tv|ios|web|webos|tizen|admin
X-Client-Version: <application version>
X-Device-Type: mobile|tv|browser
X-Request-ID: <optional caller-generated correlation id>
Authorization: Bearer <access token>
```

Authentication is required only for customer and administrator routes. The
server always returns `X-Request-ID` and `X-API-Version: 4`.

Successful JSON responses use:

```json
{
  "success": true,
  "data": {},
  "message": null,
  "meta": {
    "request_id": "d3f...",
    "api_version": "4",
    "client": {
      "platform": "android",
      "version": "36.3.0",
      "device_type": "mobile"
    }
  },
  "error": null
}
```

Errors use:

```json
{
  "success": false,
  "data": null,
  "message": "The submitted data is invalid.",
  "meta": {
    "request_id": "d3f...",
    "api_version": "4"
  },
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The submitted data is invalid.",
    "details": {}
  }
}
```

Clients must branch on `error.code`, not on human-readable messages.

## Boundaries

| Boundary | Purpose | Authentication |
|---|---|---|
| Public | OTP, releases, public catalog, banners, plans, QR creation/status | none, rate-limited where applicable |
| Customer | account, devices, library, playback, billing, QR approval, support | bearer token |
| Admin | users, catalog mutation, subscriptions, devices, notifications | bearer token plus admin authorization |
| Webhook | payment gateway callbacks | gateway signature verification |

Customer-scoped IDs are derived from the bearer token. A caller-provided
`user_id`, `uid` or route user ID is never trusted for account, library,
playback, billing or channel-subscription operations.

## Modules

| Prefix | Module |
|---|---|
| `/auth` | OTP, tokens and logout |
| `/account` | profile, phone, devices and current subscriptions |
| `/catalog` | home, search, items, seasons, episodes and recommendations |
| `/channels` | channel discovery and subscription |
| `/library` | wishlist, history and playback progress |
| `/playback` | authorized playback lifecycle |
| `/billing` | plans, subscriptions, PPV and verified payments |
| `/qr-sessions` | TV login and cross-device payment sessions |
| `/support` | customer-support tickets |
| `/admin` | protected operational APIs |
| `/webhooks` | signed provider callbacks |

Channel discovery, membership state and administrator operations are documented
in [CHANNEL_API_V4.md](CHANNEL_API_V4.md). Channel responses now use native V4
`data` payloads. New channel purchases remain disabled until verified channel
checkout is integrated; main Zo Stream billing is unaffected.

## Playback lifecycle

Playback uses one authenticated lifecycle across Android, iOS and Web:

1. `POST /playback/sessions`
2. `POST /playback/sessions/stop`

`POST /playback/sessions/heartbeat` remains temporarily available as a
deprecated compatibility no-op for released app versions. It returns success,
but new clients must not schedule or depend on it.

Every request requires both a bearer token and `Device-Token`. When the access
token was issued for a known device, its device ID must match `Device-Token`.
The server derives the user from the bearer token, verifies subscription and
device ownership, and scopes stop operations to that exact device.

`watch_position`, `duration` and the returned `watch_position` are integers in
milliseconds on every platform. Playback sessions remain active until the
client stops them or a newer playback attempt replaces the device's session.
Stop is idempotent and persists watch progress for repeated stop requests.

Device entitlement is separate from the live stream row. The owner device is
always active and counts toward the plan's per-device-type `device_limit`.
Other authenticated devices begin inactive, become active on their first
allowed playback, and remain active after playback stops. Playback
stop never frees an entitled device slot.

A successful subscription renewal (manual or confirmed payment) starts a new
sharing cycle for that subscription's `device_type`: the owner device is kept
active, while all non-owner devices of that type and their active playback
sessions are revoked. Those devices must sign in again before the owner can
share the renewed subscription with them.

Pending, failed or refunded manual payment records do not trigger that sharing
reset. Logout revokes the authentication session and stops current playback,
but does not release or deactivate the device entitlement.

The route implementation lives in `routes/api_v4.php`. V4-specific adapters
live under `app/Http/Controllers/Api/V4`; shared business behavior remains in
domain controllers/services while it is extracted from the v3 implementation.

## Migration rule

1. Add or change the v4 contract.
2. Add backend tests.
3. Migrate all active clients in local/staging builds.
4. Verify old production clients still use the migration routes.
5. Deploy v4 before publishing clients.
6. Measure legacy endpoint usage.
7. Remove legacy routes only after supported client usage reaches zero.

Database changes follow expand-and-contract: add, backfill, switch reads/writes,
observe, then remove. Destructive schema changes must not be deployed in the
same release that introduces their replacements.

## Client migration status

| Client | Canonical client adapter | Local verification |
|---|---|---|
| Web/PWA | v4 envelope-aware server API client | TypeScript and production build pass |
| Admin | v4 envelope-aware server API client; no API-key fallback | TypeScript pass; build requires Firebase Admin environment |
| Android (`ZoStreamNew`) | Retrofit v4 paths and centralized envelope adapter | Debug Kotlin/Java compile pass |
| iOS | v4 endpoint router and centralized envelope decoder | Swift parse pass; full build requires Xcode |
| Android TV | Retrofit v4 paths and centralized envelope adapter | Debug Kotlin/Java compile pass |
| webOS/Tizen | v4 paths, envelope-aware fetch client and platform headers | Typecheck, lint and production build pass |

The browser-facing `/api/*` routes inside the Next applications are BFF routes,
not legacy backend endpoints. Their backend services call `/api/v4`.

## Legacy retirement gate

Do not remove `/api/v3.0/*` or the older root routes merely because the source
clients have migrated. Removal requires all of the following:

1. v4 is deployed and health/contract smoke tests pass;
2. every supported client release using v4 is available;
3. the minimum supported versions are enforced through app-release policy;
4. legacy request metrics are zero for the agreed observation window;
5. rollback artifacts for the last compatible API release are retained.

Until then, legacy routes are compatibility shims only: no new features or
client dependencies may be added to them.
