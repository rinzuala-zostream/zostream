# Chat invitation links and WhatsApp

Invitation URLs use `https://app.zostream.in/invite/{token}`. Tokens contain 64 random alphanumeric characters; only SHA-256 hashes are stored in the invitation table. Each token belongs to one invitee. Public link previews reveal no room or recipient details, and never accept an invitation. Authenticated link resolution and POST responses check the current account, invitation state, room closure/expiry and content availability. Accepted invitations can reopen an active room after the original pending invitation expiry.

## Deploy

Run the new migration after the previous chat migrations:

```sh
php artisan migrate --force --path=database/migrations/2026_10_26_000004_add_chat_invitation_links.php
php artisan migrate --force --path=database/migrations/2026_10_26_000005_ensure_chat_delivery_queue_tables.php
```

Configure the approved template and existing Cloud API credentials:

```dotenv
CHAT_WHATSAPP_ENABLED=true
CHAT_WHATSAPP_TEMPLATE=zostream_room_invitation
CHAT_WHATSAPP_LANGUAGE=en_US
CHAT_INVITATION_URL=https://app.zostream.in/invite
CHAT_IOS_APP_IDS=5N48USNJ2K.com.buannel.pvt.ltd.zostream.Zo-Stream
CHAT_ANDROID_SHA256_FINGERPRINTS=YOUR_PLAY_APP_SIGNING_SHA256_FINGERPRINT
```

The queue table migration creates missing `jobs` and `failed_jobs` tables, and preserves existing queue data on repeat execution or rollback.

Use the actual approved template name and language. Reuse existing `WHATSAPP_PHONE_ID` and `WHATSAPP_TOKEN`; do not add them to mobile clients. Use a persistent queue and a running queue worker (not `sync`) for notification delivery. Refresh cached configuration and restart workers using your normal deployment procedure. The encrypted queue payload contains the raw invitation token, so preserve the application encryption key across web/worker processes.

Template body parameters, in order: recipient name, inviter name, room name, detail sentence. Normal chat detail: `Join the conversation in a private chat room.` Watch Together detail: `Watch {title} together and chat while you watch.` Dynamic URL button index 0 receives only the token suffix. Template button: `View Invitation`; template URL: `https://app.zostream.in/invite/{{1}}`.

When `CHAT_WHATSAPP_ENABLED=true`, new room invitations are automatically sent to the recipient’s registered WhatsApp number. There is no mobile ON/OFF preference. Legacy `chat_invitation_preferences` records are ignored; keep the existing table to preserve migration history. App invitations continue independently. The job rechecks pending state, expiry and room availability at delivery, retries provider failures and skips invitations already marked sent. Notification failures do not roll back room creation. Existing invitations created before this migration remain available in the app, but do not receive new link tokens or WhatsApp messages retroactively.

## Domain association

`app.zostream.in` must serve `/invite/*` and both `/.well-known/assetlinks.json` and `/.well-known/apple-app-site-association` from this application, or publish equivalent content on that domain's actual hosting. Routing on an API hostname alone does not associate the link domain with the apps. Serve association documents as JSON over HTTPS without redirects or authentication. Merge with existing domain association entries rather than replacing other apps.

The included association routes keep `/watch/*` and `/listen/*`, and add `/invite/*`. Confirm the Apple application identifier against the distribution signing entitlement; the default above comes from the current app target. Supply the **Play app signing** SHA-256 certificate fingerprint (not merely an upload/debug certificate). Multiple fingerprints/app IDs can be comma separated.

The web fallback contains an Open in Zo Stream link and a download link. Android/iOS have to install an updated app version for `/invite/*` support. If a browser keeps the user on the web page, its Open in app action or reopening the original invitation is the fallback. Installation does not automatically carry the pending invitation into the app; reopen the original WhatsApp link after installing.

## Verification

- Open a pending invitation in the intended logged-in account: loader, room details, Accept/Decline.
- Forward it to another account: clear account error, no room details or access.
- Open while logged out, log in, and continue the same invitation.
- Accept normal chat: open that private room with Global/room tabs.
- Accept Watch Together, including episodes: entitlement checks, stream start and room chat, with host position.
- Reopen an accepted link, decline, expire an invitation, end/idle-expire a room and remove content.
- Disconnect during loading/responding: clear Retry/Close state without duplicate acceptance.
- With server delivery enabled, create a new invitation and verify four body variables and URL suffix using the approved template. Verify delivery without a preference record and with an old disabled preference. Restart queue workers after deploying this change.

No live WhatsApp sends or device installation are part of local automated tests.

## Legacy template URL placeholder

Links containing `/invite/%7B%7B1%7D%7D{token}` have a literal `{{1}}` prefix. The web route redirects these to `/invite/{token}` only when the remaining token is exactly 64 alphanumeric characters. Updated native clients also normalize this prefix before validating/resolving it. Neither redirect nor normalization accepts an invitation; authenticated recipient verification still applies. Configure the template URL placeholder as an actual dynamic variable rather than encoded literal text. Deploy the updated routes and clear/rebuild route caches after this change.

## Browser fallback and association caches

The invitation landing page uses an explicit Android HTTPS intent addressed to the Zo Stream package on user tap. Its browser fallback points to the download page. On iOS, its Open button uses `zostream://invite/{token}`, registered in the updated app, to avoid reopening the same HTTPS page in Safari. HTTPS Universal Links remain the primary link in WhatsApp. Install the updated iOS build for the custom-scheme fallback, and deploy the updated Blade view on the backend.

The origin AASA and Apple CDN AASA can differ temporarily. Compare `https://app.zostream.in/.well-known/apple-app-site-association` with `https://app-site-association.cdn-apple.com/a/v1/app.zostream.in`; both must include `/invite/*` for normal invitation Universal Links. Server cache-clearing commands do not force Apple CDN or device caches to refresh. Browser fallback still performs the same authenticated invitation verification; it does not grant membership by itself.


## Room invitations do not arrive, but OTP/invoice messages work

Room invitations use an encrypted Laravel queue job, independently of the
`chat:deliver` WebSocket worker. Deploy the latest code and inspect the effective
configuration without exposing tokens or sending any messages:

```sh
php artisan optimize:clear
php artisan chat:whatsapp-status
# Optional: inspect one invitation rather than the latest one.
php artisan chat:whatsapp-status --invite=123
```

The command checks the room WhatsApp switch, credential presence, template and
language, migration tables, database queue backlog and the invitation's current
state. It prints neither phone numbers nor invitation tokens. It cannot confirm
whether a worker is running or whether Meta approved the template. If delivery
is disabled, set `CHAT_WHATSAPP_ENABLED=true` and rebuild the normal deployment
config cache. The template name/language must exactly match your approved
four-variable template and dynamic URL button described above.

Run a Laravel queue worker under Supervisor as well as `chat:deliver` (adjust
paths and user to your server). Existing managed queue workers can be reused;
do not add a duplicate worker unnecessarily:

```ini
[program:zostream-queue]
command=php /var/www/zostream/current/artisan queue:work --sleep=1 --tries=3 --timeout=45
directory=/var/www/zostream/current
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/zostream-queue.log
stopwaitsecs=60
```

After changing code/config, restart existing queue workers using your process
manager or `php artisan queue:restart`. The restart command does not start a
missing worker. Use `php artisan queue:failed` to identify failed jobs; inspect
only the relevant invitation failures rather than retrying all application jobs.

The invitation sender now uses the same explicit +91 fallback as account lookup
for legacy ten-digit Indian phone numbers without `country_code`. It also
normalizes explicit `+`/`00` international prefixes and national trunk zeros.
Previously such numbers were either silently skipped or sent with an incorrect
prefix. Skipped jobs now log `Chat WhatsApp invitation skipped` with the invite
ID and a reason; provider failures log the numeric provider code and template
name/language without the recipient number or link token. Existing invitations
whose jobs already completed as skipped are not automatically sent again: create
a new invitation after deploying and checking the worker. Live provider delivery
has not been verified by local tests.
