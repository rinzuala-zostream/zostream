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

Admin routes require a bearer token with administrator access:

| Method | Path under `/api/v4` | Purpose |
| --- | --- | --- |
| GET | `/admin/redemption-codes/options` | Published PPV movies and active subscription plans |
| GET | `/admin/redemption-codes?page=1&status=available` | Paginated history; status may also be `redeemed`, `cancelled`, or `expired` |
| POST | `/admin/redemption-codes` | Create a single-use code |
| POST | `/admin/redemption-codes/{id}/cancel` | Cancel an unused code |

Create a PPV code with `{"label":"Movie gift","type":"ppv","movie_id":"<movie.id>","rental_days":7}`.
Create a subscription code with `{"label":"Plan gift","type":"subscription","plan_id":1}`.
Either accepts optional `expires_at` as a future ISO timestamp. PPV rental days
range from 1 to 365 and default to 7. Movie identifiers use `movie.id`, not `num`.
The response includes `qr_payload: "zostream://redeem/<64 lowercase hex characters>"`.
QR images should encode that exact string, keeping this namespace distinct from
legacy session tokens. Do not send these codes to the login/payment verify route.

Mobile integration (next client change):

1. Recognize the exact `zostream://redeem/{token}` payload and validate the token
   as 64 lowercase hexadecimal characters.
2. `GET /api/v4/redemptions/{token}` with the authenticated owner's bearer token.
   It returns the target, validity, and only that account's non-blocked devices.
   Subscription devices are filtered by plan device type. Each has `eligible`,
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
existing rental authorization. New/expired subscriptions use the plan duration;
active subscriptions require the same plan and extend by exactly **30 days**
from their existing expiry. Existing subscription sharing semantics apply to
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

Deployment requires the redemption codes migration and updated admin assets.
The mobile scanner is not changed by this backend implementation.

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
