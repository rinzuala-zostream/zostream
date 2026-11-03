# Windows and Mac clients

Desktop apps use the existing v4 API and Android TV's authentication, catalog,
subscription/rental and playback flow. They have their own device category:

| Field | Windows | Mac |
| --- | --- | --- |
| `device_type` / `X-Device-Type` | `desktop` | `desktop` |
| `platform` / `X-Client-Platform` | `windows` | `macos` |
| `X-Client-Version` | App version | App version |
| `device_id` | Persistent installation UUID | Persistent installation UUID |

Keep the installation UUID across app restarts and sign-outs. The device's OS
is stored in nullable `n_devices.platform` on OTP/QR login. Windows and Mac share
the desktop device limit and owner category; they are separate from mobile,
browser and TV. Existing devices and plans are not converted.

## Database rollout

Deploy and run migration `2026_11_02_000003_add_desktop_device_support.php` before
enabling desktop logins. It expands the existing device enums and adds the
nullable device platform column. Existing varchar plan columns stay unchanged;
enum plan columns are expanded while retaining their current values/default.
The migration does not create plans, set prices, assign subscriptions, or rewrite
existing device types. Its rollback refuses to run while desktop records remain.

Payment history needs the follow-up migration
`2026_11_03_000001_add_desktop_to_payment_history_device_type.php`. The first
desktop migration did not cover legacy `n_payment_histories.device_type` enums;
manual Desktop subscription creation could therefore fail with MySQL warning
1265 / "Data truncated for column 'device_type'" while inserting payment history.
The failed transaction rolls back subscription creation and device changes.

Deploy the new migration file, then run from the VPS Laravel project directory:

```sh
php artisan migrate --path=database/migrations/2026_11_03_000001_add_desktop_to_payment_history_device_type.php --force
```

This expands only the existing payment-history device enum, preserving its
current values, default, nullability, collation, comment, rows and indexes.
Existing varchar columns already accept desktop and are left unchanged. Repeated
execution is safe. Rollback intentionally retains the expanded enum so historical
Desktop payments remain valid. No Vue rebuild is required for this database fix.
Retry the admin subscription form after the migration completes.

Regression coverage reproduces the enum failure and transaction rollback, then
verifies successful Desktop subscription/payment creation and real device renewal
after migration. The migration and playback regression suites pass (42 tests,
259 assertions); PHP 8.5 emits existing framework/test-runner deprecations. Tests
execute schema changes on isolated SQLite databases and compile the MySQL ALTER
statement with preserved enum attributes. A live VPS migration has not been run
from this session.

## Authentication and access

Use `POST /api/v4/auth/otp/request` followed by
`POST /api/v4/auth/otp/verify`. Include `device_id`, `device_name`,
`device_type: desktop` and either `platform` or `X-Client-Platform` on verification.
Access/refresh tokens and the `Device-Token` header retain their existing contract.

Alternatively create `POST /api/v4/qr-sessions` with `type: login`, `device_id`,
`device_name`, `device_type: desktop` and the platform header. On approval, desktop
identity comes from that session, not the scanning phone. Existing TV QR behavior
is unchanged. Payment uses the existing owner-only QR payment flow, with
`device_type: desktop`.

Create and price desktop plans explicitly through the existing admin Plans form
or API (`device_type: desktop`). Then use:

- `GET /api/v4/billing/plans/device/desktop`
- `GET /api/v4/account/subscriptions?device_type=desktop`
- Existing content details and PPV status endpoints with `device_type=desktop`
- Existing playback start/heartbeat/stop endpoints with desktop identity

Mobile/browser/TV subscriptions do not grant desktop access. Owner checks, rental
device locks, device limits, token refresh/revocation and watch-position units
(milliseconds) retain the existing behavior. Desktop does not introduce a new
payment gateway or change heartbeat/session policy.

Analytics accepts `windows` and `macos` in context/platform headers and report
filters, including online presence. Official client configuration can use the
existing generic admin configuration API with platform `windows` or `macos`;
release URLs and signing identities must be provisioned for the desktop builds.

## Vue admin release controls

Tools → App releases now supports Windows (`windows_update`) and Mac/macOS
(`macos_update`). Both store a string `version` such as `0.2.0`, an HTTPS `url`,
`enabled`, and `force`. Existing mobile/TV field names and numeric version rules
are unchanged. New desktop entries start with an empty form when not configured;
reading a missing entry does not create or copy another platform's settings.

Enabled controls whether a newer-version prompt is shown. Force requires an HTTPS
download URL and blocks the desktop app until updated. Desktop checks on startup;
optional releases can be deferred, and downloads open the admin-configured URL.
Installer execution remains manual. Official Client platform suggestions include
`windows` and `macos`, retaining the existing free-text platform support.

Deploy the API/admin assets and save the desired platform settings to Firebase
through the authorized admin account. No remote entries were created by this
code change. Restart desktop development to pick up the new startup check;
previously generated preview installers need rebuilding for this integration.

## Desktop admin coverage

The Vue dashboard and subscription chart include Desktop (Mac / Windows).
Plans, subscriptions and devices can be filtered by device category; device
lists and user search also support Mac/Windows platform filters on the server.
Device rows display the reported OS. Editing a device preserves that OS unless
its category changes away from desktop. The OS field in the Vue editor is read-only.
Tools → Operations can clear all desktop devices or only one desktop OS for a
user, stopping only the matching active streams.

Analytics and chat metrics filters include `macos` and `windows`. Warnings can
target either OS, and payment statistics include the desktop category. Desktop
release nodes have the same public read-only Firebase rules as existing release
nodes; deploy `Library/firebase/database.rules.json` to publish those reads.
Saving warning/release settings still uses the authorized admin API.

Native Mac playback that opts into HLS also requests HLS-compatible video ads.
DASH ads are converted through the existing Apple HLS path before delivery;
failed conversions consume no delivery/billing quota. Mac clients without HLS
opt-in and Windows clients retain their existing media format. Manifest URLs
remain on the CDN because the byte proxy does not rewrite relative segments.

Local verification (no live Firebase/database changes):

```sh
npm run build
node --test tests/desktop-admin-contract.test.mjs
php vendor/bin/phpunit --filter 'DesktopAdminSupportTest|DesktopWarningConfigTest|DesktopDeviceMigrationTest|DesktopQrSessionTest|DesktopAppReleaseTest|V4PlaybackSecurityTest|CustomerDashboardTest|AnalyticsDashboardReportTest|PlanAdPolicyTest|AdminSpaTest|AdSubmissionWorkflowTest'
```

For the browser smoke test, run Vite on `127.0.0.1:5184`, then run
`node tests/browser/desktop-admin.mjs` with Playwright installed (or set
`PLAYWRIGHT_MODULE` to its module path). The fixture intercepts every API call;
it does not authenticate or change real admin settings/devices.

## Native Mac Razorpay checkout

The Mac payment sheet has QR Code and Razorpay tabs. Direct checkout uses the
existing owner-only subscription-order API or validated PPV pending-purchase API,
then the existing Razorpay verify API. The native client retains `desktop` and
its installation token when creating and confirming purchases.

Deploy `GET/POST /api/v4/billing/payments/razorpay/desktop-return` with the Mac
update. Razorpay Hosted Checkout posts its result to this public HTML bridge;
GET represents cancellation. The page has no database or gateway side effects
and never claims a payment is verified. It escapes callback fields, disables
caching and sends them to the native WebKit handler. The Mac checks the exact
main-frame callback URL and matching order before authenticated server verification.
Do not replace this URL with the Razorpay webhook URL.

`DesktopCheckoutReturnTest` covers success-result transport, cancellation/failure,
injection escaping and malformed input. Existing billing auth/owner protections
remain in place. No real payment or production settings were changed.
