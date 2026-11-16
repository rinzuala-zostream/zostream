# Channel API v4: security and management foundation

This change hardens the existing channel module. It is not the complete Creator
Channel specification: private multipart ingest, staff DASH QA, creator studio,
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
is read-only until creator pricing policy limits are implemented. There are no
owner media URLs, payment activation, banking or payout controls. MP4 file upload
is not implemented by this phase.

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
mutations. No schema migration is required.

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

Artwork and media are URL references in this phase. This UI does not upload
source MP4 files, perform DASH packaging, or certify staff playback QA. It does
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

No database migration, destructive SQL, environment change or data conversion is
part of this change. Existing channel tables and identifiers are reused. Main
movie/episode playback, billing/webhooks, subscription tables, device middleware
and R2/CDN configuration are not changed.

Before production rollout, confirm the existing channel migration has already
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
