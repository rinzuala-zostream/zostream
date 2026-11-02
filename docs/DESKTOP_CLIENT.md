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

Test this migration on a staging copy of the deployment database before release.
Local migration regression tests use SQLite; a live MySQL migration has not been
applied as part of this change.

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
