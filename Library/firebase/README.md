# ZoAnalytics Realtime Database switch

ZoAnalytics uses this Firebase Realtime Database value as its live collection
kill switch:

```text
/config/analytics/enabled
```

The value is a native Boolean. `false` prevents collection, local persistence,
and analytics API uploads. The admin Analytics page creates and updates the
node, so Remote Config setup and template uploads are not required. The
authenticated analytics config endpoint reads this same value, and ingestion
requests are rejected while it is false or Firebase cannot be reached.

## Initial data

The admin switch defaults to `false` when the node does not exist. Turning the
switch on or off creates this structure automatically:

```json
{
  "config": {
    "analytics": {
      "enabled": false,
      "updated_at": "2026-10-06T00:00:00.000Z"
    }
  }
}
```

For a manual seed, open Realtime Database, select `/config/analytics`, and
import `analytics-config.seed.json`. Import only at that node so other database
content is not replaced.

## Security rules

Mobile apps only need read access to this non-sensitive flag. Admin writes use
the Firebase Admin SDK and bypass client security rules.

Vue admin configuration writes go through Laravel, using ZoStream bearer sessions
rather than Firebase Authentication. The `admin.config-whitelist` middleware
protects analytics, warning, scrolling text, app release, official client and
Amazon TV IAP write/delete endpoints, including the legacy V3 app update endpoint.
It accepts only a session user's UID in `ADMIN_QR_ALLOWED_UIDS` or their stored
`auth_phone` matching `ADMIN_WHATSAPP_ALLOWED_NUMBERS`. Submitted UID/phone fields
and membership in `admin_users` alone do not grant configuration write access.
Keep these allowlists in the server `.env`; do not copy ZoStream UIDs into
Firebase `auth.uid` rules. After deployment, rebuild cached configuration and
routes through the normal Laravel deployment process so allowlist and middleware
changes take effect. This protection applies to these Laravel endpoints; other
service-account holders and Firebase Console access remain governed by IAM.

`database.rules.json` is the complete ruleset for the Firebase paths used by the
current API, admin, Android, iOS and TV sources. It denies root reads and writes,
allows reads of public app configuration, and allows QR listeners only at
`/qr_sessions/<token>` (not the session collection). It retains the `order_id`
index used by the backend payment lookup. No frontend changes are required for
the inspected clients.

Android's existing QR payment flow directly updates `amount` with `movie_id` or
`plan_id` before calling the verification API. To preserve that flow, these
three fields remain writable on existing, unexpired `payment` sessions, with
type validation. Clients cannot create or delete sessions, change login or
payment status, write response tokens, or edit any other session field. The
expiry stored by the API is in seconds; rules compare it to `now` in milliseconds.

These compatibility writes are unauthenticated: knowing the QR token still
allows editing those three payment selection fields. Rules cannot compare the
API bearer token with a Firebase user, or verify prices against the SQL catalog.
The backend must validate ownership, catalog prices and payment confirmation.
Per-user Firebase authorization requires a client authentication change. QR
responses also remain readable to anyone who knows the session token, including
login credentials returned there; keep these tokens secret. Collection reads
are denied to prevent listing all tokens. No read expiry is imposed, so payment
webhook listeners can continue after the initial QR selection timeout.

To deploy, back up the current Firebase rules, then paste the entire contents of
`database.rules.json` into **Realtime Database → Rules** and publish. This file
is not deployed automatically. Before publishing, compare any additional
production-only paths or older app clients with this allowlist. Check QR login,
Android QR subscription/PPV selection, payment completion, analytics, app updates,
warnings, marquee, official client verification, and Amazon IAP configuration in
a staging database. Confirm that anonymous root/QR collection reads, session
creation/deletion, status/response changes, expired selection writes and config
writes are denied. Backend service-account operations must still succeed.

`analytics-config.rules-fragment.json` remains available for installations that
only need the analytics fragment. A parent `.read: true` or `.write: true`
overrides restrictions below it; the fragment cannot secure an open root.
See [Firebase rule cascading](https://firebase.google.com/docs/database/security/core-syntax)
and [Admin SDK rule bypass](https://firebase.googleblog.com/2021/01/code-review-security-rules.html).

## Mobile behavior

Attach one Realtime Database value listener when the app runtime starts. Begin
with collection disabled. On every value change, update the Boolean supplied to
ZoAnalytics and call `collectionStateDidChange()`.

The listener is event driven. It does not poll every second, and Firebase
automatically reconnects it after temporary network loss.
