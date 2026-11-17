# Channel API v4: security and management foundation

This change hardens the existing channel module. It is not the complete Creator
Channel specification: staff DASH QA,
verified checkout, revenue split, wallet and payouts are separate work.

## Creator Studio (owner access)

Owners open `/creator` and sign in using the existing Zo Stream WhatsApp OTP
flow. Studio tokens use separate browser storage from the admin session; the
authentication ecosystem and user IDs are shared. The existing review-account
OTP shortcut is restricted to the review account; other accounts must verify
their own OTP.

To test, use **Admin → Content → Channels**, create/edit a channel and select
the intended user's UID as owner. Open **Creator Studio** from that page (or
visit `/creator`) and log in using that user's linked phone number. An
unassigned user sees their UID and an assignment message; signing in does not
create or assign a channel. No test accounts, production assignments or SMS/
WhatsApp messages are created by this code change itself.

Owners can edit their profile, list plans, see content/member counts, create
video metadata drafts, edit their metadata and withdraw their content. Pricing
is read-only until creator pricing policy limits are implemented. There are no owner playback URL, payment activation, banking or payout controls.
Owners can upload private MP4 sources and upload logo/banner/thumbnail images.

| Method | `/api/v4/creator` path | Operation |
|---|---|---|
| GET | `/channels` | Paginated channels assigned to authenticated UID |
| GET, PUT | `/channels/{channelId}` | Own profile + counts / allowed profile fields only |
| GET | `/channels/{channelId}/plans` | Read own plans |
| GET, POST | `/channels/{channelId}/contents` | List metadata / create private draft |
| PUT, DELETE | `/channels/{channelId}/contents/{contentId}` | Edit own metadata / withdraw |

All creator routes require `auth.token`, derive the owner from the verified
token, and scope both channel and content lookups. There is no admin bypass on
these routes. Owners cannot reassign themselves, verify or activate channels,
publish content, attach media, or read another owner's records. Editing
published metadata returns it to draft for staff review. Suspended channels are
read-only; deleted channels are unavailable. Writes lock the channel inside a
database transaction so suspension and ownership changes serialize with owner
mutations. The upload feature adds the migration described below.

## Contract and access

All `/api/v4` channel responses use the standard `success`, `data`, `message`,
`meta`, `error` envelope described in [API_V4.md](API_V4.md). Channel data is
directly inside `data`; a second legacy `status`/`data` wrapper is no longer used.
Paginated lists put Laravel pagination fields inside `data`, with rows in
`data.data`. Send the normal V4 client headers and bearer token where required.

Public responses contain active channels, active plans, and published content
whose release date is null or has arrived. Query parameters cannot override
these restrictions. Content is metadata only, including for free content: no
media relation, source URL or stream URL is returned. A published metadata entry
does not establish playback entitlement or technical asset readiness.

| Method | `/api/v4` path | Access / behavior |
|---|---|---|
| GET | `/channels` | Public discovery; `search`, `user_id`, `page`, `per_page` |
| GET | `/channels/{channelId}` | Public profile; latest 15 active plans and released content previews |
| GET | `/channels/{channelId}/plans` | Public paginated active plans |
| GET | `/channels/{channelId}/contents` | Public paginated metadata; `content_type`, `access_type` filters |
| GET | `/channel-plans/{planId}` | Public active plan under an active channel |
| GET | `/channel-contents/{contentId}` | Public released metadata under an active channel |
| GET | `/channels/{channelId}/subscription` | Bearer token; only that user's membership and `has_access` |
| POST | `/channels/{channelId}/subscriptions` | Bearer token plus owner device; purchases currently return HTTP 409 |
| DELETE | `/channels/{channelId}/subscriptions` | Bearer token plus owner device; cancel own membership |

Public lists default to 15 rows, allow 1–100 rows per page, and reject invalid
pagination with HTTP 422. Channel detail previews are bounded; use the paginated
endpoints for the rest. Public profiles omit the owner's internal `user_id`.

Membership identity always comes from the token, never body/query IDs.
`has_access` describes channel membership only; it does not authorize a media
request or bypass future stream authorization. It requires an active channel,
an active membership, a reached start date, and a future
expiry. An expired membership may still have stored status `active`, but returns
`has_access: false`. No cron job or main Zo Stream subscription is needed for
this check. An authenticated user may inspect their own membership even when the
channel is suspended or removed; access is false in those states.

Customer cancellation is idempotent and preserves the historical `expires_at`,
but retains the existing immediate loss of membership access. Historical
`cancelled` records never become entitled again. It cannot revive an
expired/admin-revoked membership. No recurring gateway mandate is created or
cancelled by this foundation. Period-end cancellation requires a distinct state
and verified billing integration in the later membership phase.

## Administrator operations

The Vue admin sidebar includes **Content → Channels**, at `/admin/channels`.
Channel profiles and the Plans, Contents, Subscribers and Payment history tabs
are at `/admin/channels/{id}`. Tabs have shareable `?tab=plans` (or `contents`,
`subscribers`, `history`) URLs. The UI supports owner lookup by name, phone,
email or UID, channel creation/editing/removal, plan editing/deactivation,
content metadata and visibility, media references, rental settings/history and
immediate membership/rental revocation. Lists are paginated and writes show
server validation errors without clearing entered form values.

Artwork supports URL entry or file upload. Private review sources support file
upload; streaming media references are still attached separately by staff. This
UI does not perform DASH packaging or certify playback QA. It does
not offer payment activation or payout actions while verified channel billing
is unavailable. Existing admin login, session refresh, theme and shell are reused.

Every route below requires both `auth.token` and `admin.token`. Ordinary channel
owners do not become administrators; their separate Studio permissions are
described above.

| Method | `/api/v4/admin` path | Operation |
|---|---|---|
| GET, POST | `/channels` | List all states / create channel |
| GET, PUT, DELETE | `/channels/{channelId}` | Inspect / update / mark deleted |
| GET, POST | `/channels/{channelId}/plans` | List / create plans |
| GET, PUT, DELETE | `/channel-plans/{planId}` | Inspect / update / deactivate plan |
| GET, POST | `/channels/{channelId}/contents` | List all states / create content |
| GET, PUT, DELETE | `/channel-contents/{contentId}` | Inspect including media / update / withdraw to private |
| POST | `/channel-contents/{contentId}/media` | Attach a media record |
| PUT, DELETE | `/channel-media/{mediaId}` | Update / remove a media record |
| PUT, DELETE | `/channel-contents/{contentId}/ppv` | Configure / remove channel PPV settings |
| GET | `/channels/{channelId}/subscribers` | List subscribers |
| GET | `/channels/{channelId}/subscription-history` | Inspect channel history |
| DELETE | `/channels/{channelId}/subscribers/{userId}` | Immediately revoke membership using stored `expired` status |
| GET | `/channel-contents/{contentId}/rental-history` | Inspect rental history |
| DELETE | `/channel-contents/{contentId}/rentals/{userId}` | Cancel rental |

Subscriber/history user relations select only `uid`, `name`, `img`; full user
records, contact fields and tokens are not serialized. Content withdrawal
retains media and financial references instead of deleting the content row.

## Payments and legacy clients

No verified channel checkout currently exists. Supplying `status: success`, a
transaction ID, or payment method must never grant membership/rental access.
These purchase entry points now return HTTP 409 with
`error.code: CHANNEL_PAYMENT_NOT_AVAILABLE`, including the legacy subscription
and rental entry points. They do not write subscriber, rental or payment history
records. This restriction also applies to administrators. Existing memberships
and financial records are retained. Implement a separate verified channel order
flow before enabling new paid purchases; do not route them through the main
subscription activation handler.

Legacy `/api/v3.0/channels` discovery URLs remain available using their existing
`status`, `message`, `data` success envelope, with the same safe catalog filters.
Legacy mutation, subscriber and history routes now require bearer authentication
and administrator access. A client relying on unauthenticated writes, hidden
content, inactive plans or exposed media URLs must migrate; these bypasses are
intentionally closed. New integrations should use V4 exclusively.

## Deployment and validation

Existing channel tables and identifiers are reused. The upload feature adds a
new `channel_uploads` table using a reversible migration; it does not convert or
delete existing channel data. Main
movie/episode playback, billing/webhooks, subscription tables, device middleware
and R2/CDN configuration are not changed.

Before production rollout, confirm the channel tables and upload migration have
been applied and point staging/admin clients at the native V4 response shape.
Smoke-test public discovery, admin editing and membership cancellation on
staging. Do not deploy a code rollback that restores the unauthenticated channel
routes or client-asserted payment activation.

`tests/Feature/ChannelApiTest.php` exercises real bearer/admin middleware against
an isolated SQLite database, including legacy bypass attempts, draft/source URL
disclosure, suspended channels, forged payments, identity spoofing, cancellation
and administrative CRUD. Existing V4/playback/subscription regression tests must
also pass. Live CDN access and direct storage object privacy require separate
staging verification; hiding a stored URL does not invalidate previously shared
public objects.

Run `npm run test:channels` for Vue interaction checks. They compile and mount the
actual pages using Vue's host renderer and mocked API responses, covering V4
payloads, media/settings/history interactions and error retention. They are not
browser or live staging tests. Build with `npm run build`; if the installed
`node_modules/.bin/vite` launcher is empty, the equivalent direct command is
`node node_modules/vite/bin/vite.js build`.


## Drag-and-drop uploads

Admin and Creator Studio image fields accept JPG, JPEG, PNG and WebP (up to
10 MB, maximum 4096 × 4096). Save the channel first so artwork can be scoped to
its ID. Uploaded artwork is converted to WebP using the existing image helper,
stored under `channels/{channel}/artwork/` in the configured public R2 disk, and
applied only when the profile/content form is saved. R2 must have a configured
public HTTPS URL and working credentials.

Save a content draft before uploading a source. Creators choose **Video drafts
→ Upload MP4**. Admins use **Contents → Media & details → Source files for
review**; they can also upload MP3, M4A, WAV, VTT and SRT review files. Uploading
or replacing a source returns content to draft. It never creates a public
playback target or a streaming media record. Admins download original sources
through an authenticated, non-cacheable endpoint.

Source files are stored on the server's dedicated private `channel_uploads`
local disk at `storage/app/channel-private`, outside public storage and without
Laravel file serving. Sources are not put into the existing public R2 bucket;
a public CDN prefix cannot ensure privacy. Back up this directory and use shared
private storage or sticky routing for multi-server deployments. This phase does
not implement direct-to-private-R2 source ingest or automated duration/malware
inspection; staff processing and QA remain required.

Files transfer in 1 MB chunks, with progress, cancellation and retry of the same
selected file. Chunk replay and completion are idempotent. Retry is within the
current page/session; reload recovery is not provided. Actual MIME, extension,
size, channel/content ownership and current suspension state are enforced by the
server. Missing chunks cannot finalize. A changed channel owner cannot finalize
the previous owner's session. Completed source URLs and disk paths are never
returned in public/owner catalog responses.

Default limits: 512 MB per source, 10 GB source capacity per channel, 3 unfinished
sessions per channel, 24-hour session lifetime. Override source limits with
`CHANNEL_SOURCE_MAX_BYTES` and `CHANNEL_SOURCE_QUOTA_BYTES`; the other limits are
in `config/channel_uploads.php`. Completed sources are retained and count toward
capacity. `channel-uploads:prune` runs hourly via the existing scheduler and removes
expired unfinished uploads only. Ready sources are not deleted by cleanup.

Apply this additive migration before testing upload controls:

```sh
php artisan migrate --path=database/migrations/2026_11_16_000002_create_channel_uploads_table.php
```

The existing channel tables must already exist. The migration rollback drops
only upload metadata; preserve private source files and metadata backups before
rolling it back. No production migration or live R2 transfer is performed by the
implementation tests.

V4 upload routes are under both `/api/v4/admin/channels/{channelId}/uploads` and
`/api/v4/creator/channels/{channelId}/uploads`: `POST` starts a session, `GET`
lists ready sources for `?content_id=...`, `GET /{id}` checks a session,
`POST /{id}/parts/{index}` accepts a multipart `file`, `POST /{id}/complete`
validates/finalizes, and `DELETE /{id}` cancels unfinished uploads. Only the admin
boundary exposes `GET /{id}/download` for completed source files.

## Channel workspace improvements

The admin channel directory now shows the matching result count and clearly
labels page-level active/pending counts, with resettable filters and channel
identity links. Admin detail includes a banner/logo overview, status badges,
profile preview, form guidance and actionable empty states.
Creator Studio has an independent warm light theme, a responsive navigation
sidebar, thumbnail video cards and its own forms, upload and pagination
components. It loads `resources/css/creator.css` instead of the admin stylesheet.
Videos are the initial view, and saving a new draft opens its MP4 upload step.
Channel selection is preserved on refresh. The studio also includes a
publishing guide, visibility filters and a paginated
private source history panel for each video. Suspended owners can inspect this
history while upload and editing controls remain disabled.

Artwork uploads lock surrounding navigation and pagination until they finish
or are cancelled; the active upload remains cancellable. Upload completion
releases its busy state before notifying its parent, so a parent refresh does
not receive a second stale busy event. Image selections over 10 MB are rejected
before transfer. Source histories display readable file sizes. These changes
reuse the existing API and do not activate checkout or channel playback.
