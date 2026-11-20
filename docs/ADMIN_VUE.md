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

Open **Audience → Activation QR** (`/admin/activation-qr`) to issue a PPV content
or subscription grant. Subscription cards need no plan selection: each provides
30 days of new access or extends an active subscription by 30 days. PPV cards
support a published movie, full season or single episode. Choose **Movie** or
**Series → Season → Full season only / One episode only**. Only published,
enabled PPV items appear. A season must be sold as PPV to issue a full-season
pass; individually sold episodes remain selectable even if their season is not
sold as a package. Rental days default to seven and begin at successful
activation. No checkout occurs when claiming these grants.

Add a label, choose **1–20 A4 pages** and an optional QR expiry. Each page contains
**12 subscription cards (3 × 4)** or **8 PPV tickets (2 × 4)**. PPV tickets use a
separate cinema design with a movie/season poster, title, rental days and exact
season/episode scope alongside the QR. Subscription card layout stays unchanged.
Generate the batch,
preview the sheets in the PDF dialog, then choose **Print / Save PDF**. New
batches open that dialog automatically. **A4 PDF**, card **PDF**, and **View QR**
actions also open dialogs, keeping the history list in place. The QR dialog
offers PNG download. Close previews using the close button, Escape or the
backdrop; focus returns to the opening control. Print at A4 portrait, 100%
scale, with browser headers/footers disabled. Cards have cut guides, a 29–30 mm QR,
an activation instruction and an individual reference number. Open **A4 batch**
from history to print the same cards again; this never creates new vouchers.
Redeemed, cancelled and expired cards are visibly marked when reprinting. Old
PPV batches are reflowed to eight tickets per page without changing their codes;
their print page count can therefore increase.

**QR history** shows one row per label, combining batches that share that label.
The row summarizes total cards and available/used/expired/cancelled counts.
Choose **View list** to expand a compact list ordered by the exact reference
number printed on the PDF (`#0001`, etc.). Labels and their card lists paginate
independently (25 per page), and cards load only when a label is opened.
Cancel, view a single QR or reopen its PDF from the expanded list. Status filters
on the label list select matching labels while retaining their full counts;
the expanded list has its own card filter.

The signed-in account owner scans the QR in the mobile app and chooses one of
their registered devices. The backend resolves the matching plan after device
selection: retain an active subscription's plan for Extend. Add New always
selects an active **30-day plan** matching the device type (lowest price, then
ID if multiple plans match). It never reuses a longer expired plan or falls
back to another duration. New/expired subscriptions start today for **30 days**;
active subscriptions extend by **30 days** from their existing expiry. A device
with no active 30-day plan cannot use Add New or consume the QR. Previously issued plan-bound
codes keep their original plan and duration rules. Existing subscription sharing
rules still apply; extending a subscription also extends access for devices
already sharing that subscription. PPV grants bind to the exact selected device.
Full-season grants cover only episodes belonging to that season, including
premium episodes not individually sold as PPV. An episode-only grant covers
only that episode. Playback verifies the stored content type and actual parent
season, preventing identical IDs or a supplied season ID from widening access.

Scanning does not consume a code. Successful activation marks it redeemed in
the same database transaction as the entitlement and history. History displays
the account, device and activation time. Unused codes can be cancelled; used
codes cannot be reissued or cancelled. Treat unused PNGs as redeemable vouchers.

Deploy the redemption codes migration and
`2026_11_20_000002_add_print_batches_to_redemption_codes.php` and
`2026_11_20_000003_add_ppv_content_type_to_redemption_codes.php`, then build the Vue
assets before using the page. The Android and iOS scanner implementations
recognize `zostream://redeem/{token}` and present device selection. Existing
login/payment QR handling remains unchanged. Roll out updated mobile builds
before distributing activation QR codes.

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
