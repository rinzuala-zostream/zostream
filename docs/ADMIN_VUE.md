# Zo Stream Admin (Vue)

The production admin workspace is part of the Laravel application and is served at `/admin`.
The imported `admin/` directory is retained as the original Next.js reference; Laravel does not execute it.

## Architecture

- `resources/js/admin/router.js` owns admin client routes with the `/admin` history base.
- `resources/js/admin/lib/api.js` provides the API v4 adapter, auth headers, token refresh, and envelope normalization.
- `resources/js/admin/lib/resources.js` defines reusable CRUD schemas.
- `resources/js/admin/pages` contains dashboard, authentication, resource, analytics, and operations screens.
- `app/Http/Controllers/Api/V4/AdminRealtimeConfigController.php` keeps Firebase Admin credentials on the server for warning, ticker, and official-client configuration.
- `routes/web.php` sends all `/admin/*` browser routes to the Vue entry point.

## Local development

Run Laravel and Vite as usual:

```bash
php artisan serve
npm run dev
```

Then open `http://127.0.0.1:8000/admin`.

Admin login depends on the existing `ADMIN_WHATSAPP_ALLOWED_NUMBERS`, `ADMIN_QR_ALLOWED_UIDS`, Firebase, and API v4 configuration.

## Single-use activation QR codes

Open **Audience → Activation QR** (`/admin/activation-qr`) to issue a PPV movie
or subscription grant. Choose a published PPV movie or active plan, add a label
and optional QR expiry, then download the PNG. PPV rental days default to seven
and begin at successful activation. No checkout occurs when claiming these grants.

The signed-in account owner scans the QR in the mobile app and chooses one of
their registered devices. Subscription plans must match the device type. A new
or expired subscription starts today using the selected plan's duration. An
active subscription on the selected device extends by **30 days** from its
existing expiry, only when the plan matches. Existing subscription sharing
rules still apply; extending a subscription also extends access for devices
already sharing that subscription. PPV grants bind to the exact selected device.

Scanning does not consume a code. Successful activation marks it redeemed in
the same database transaction as the entitlement and history. History displays
the account, device and activation time. Unused codes can be cancelled; used
codes cannot be reissued or cancelled. Treat unused PNGs as redeemable vouchers.

Deploy the migration `2026_11_20_000001_create_redemption_codes_table.php` and build
the Vue assets before using the page. Mobile scanner support is a separate
client change; existing login/payment QR handling remains unchanged.

## Desktop release installers

In **App releases**, select **Mac (macOS)** or **Windows**, choose an installer,
and click **Upload installer**. The returned permanent HTTPS URL fills **Download URL**;
it can be copied or opened for a download check. Click **Save release** to publish
the version and URL through the existing Firebase release configuration. Uploading
alone does not change the currently published release. Manual download URLs remain supported.

- Mac accepts `.dmg`, `.pkg`, and `.zip`.
- Windows accepts `.exe`, `.msi`, `.msix`, `.msixbundle`, `.appx`, `.appxbundle`, and `.zip`.
- Files must be between 1 KB and 512 MB. Each upload receives a unique object path,
  so uploading the same filename does not overwrite older installers.
- `POST /api/v4/admin/app-releases/{platform}/upload` requires the same signed-in,
  whitelisted administrator access as saving app release configuration.

Files use the existing R2 disk under `app-releases/macos/` or `app-releases/windows/`.
The existing `R2_URL` must be a public HTTPS download domain for that bucket;
R2 access keys remain on the server. Installers are served as download attachments.

The web server and PHP must also allow the intended installer size. To support
the full 512 MB application limit, set PHP `upload_max_filesize` to at least `512M`,
PHP `post_max_size` to at least `520M`, and the reverse proxy request-body limit
to at least 520 MB (for example, Nginx `client_max_body_size 520m`). Smaller server
limits still apply and are reported as upload errors in the form.
