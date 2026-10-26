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

Recipients opt in using Chat options → WhatsApp invitations. Default is off. App invitations continue regardless of this preference. The job rechecks consent, pending state, expiry and room availability at delivery, retries provider failures and skips invitations already marked sent. Notification failures do not roll back room creation. Existing invitations created before this migration remain available in the app, but do not receive new link tokens or WhatsApp messages retroactively.

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
- Enable WhatsApp consent on the recipient, create a new invitation and verify four body variables and URL suffix using the approved template. Disable consent and verify only the app invitation remains.

No live WhatsApp sends or device installation are part of local automated tests.
