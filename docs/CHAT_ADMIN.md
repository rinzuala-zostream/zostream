# Vue admin chat

Open **Engagement → Chat moderation** (`/admin/chat`). This uses the existing Vue
admin and administrator login; no separate account or framework is required.

- **Rooms:** search by room key/content ID, filter global/movie/episode and
  open/closed rooms, view retained message counts and pending reports. Review
  history, load older messages, search message text/name/viewer ID, delete a
  message, mute/ban a viewer, and open/close a room. Admin review works for closed
  rooms and does not require a viewer playback session.
- **Reports:** filter pending/resolved/all and optional room ID. Review the
  reported text and reporter, inspect the room, delete the message, ban the
  sender, then resolve the report. Deleting a message does not silently resolve
  all its reports; an operator records a resolution reason separately.
- **Restrictions:** list active restrictions, filter room or viewer, remove a
  restriction, or create a mute/ban by viewer ID. Scope defaults to the selected
  room; explicitly choose all rooms for an account-wide restriction. Optional
  expiry uses the operator's browser timezone and is transmitted as an ISO UTC
  timestamp. Room 0 denotes all rooms.
- **Audit log:** paginated moderation history, including administrator ID, target,
  scope, timestamp and reason. Every mutation requires a reason and confirmation.
- **Analytics:** selected-range messages, opens, unique visitors/senders,
  reports, send/connection failures, daily activity, platforms, most active rooms
  and event counts. Export the current filtered aggregate report as JSON.

The main `/admin/analytics` page also has a **Chat** tab and recognizes chat event
names in Product events. Its date/timezone, platform, app version, content type
and viewer filters apply to collected chat telemetry. The Chat panel adds room
type/content ID filters. Date windows are limited to 93 days and chart day
buckets use UTC. Linked playback counts include only matching viewer and
playback analytics session IDs; they show association, not a causal uplift in
watch time. Missing optional playback data does not hide the other chat metrics.

Operational counts (rooms, unresolved reports, active restrictions and pending
broadcast/analytics delivery) are current and cover all platforms; telemetry
filters do not change those counts. Analytics outages show an unavailable state,
not fabricated zeroes, while moderation health remains readable. The existing
analytics collection switch still governs chat telemetry independently of chat.

Room/report lists refresh every 15 seconds while visible. Auto-refresh pauses
while a confirmation dialog is open. History refreshes only at the bottom of an
unsearched window; older history stays stable until explicitly refreshed. It is
an admin polling view, not a second viewer WebSocket subscription.

## API and permissions

All `/api/v4/admin/chat/*` reads require both `auth.token` and `admin.token`:
`rooms`, `rooms/{room}/messages`, `reports`, `restrictions`, `audit`, `metrics`.
Lists use page/per_page pagination, except history which uses exclusive
before/after message cursors. Mutations reuse the authenticated, admin-only
`/chat/rooms/{room}/moderate` and `/chat/reports/{report}/resolve` endpoints.
Bodies render as escaped plain text in Vue. No public message-write path or
admin role is inferred from a client-supplied flag.

The chat storage migration and existing admin/analytics deployment must be
present. Missing storage produces a setup-unavailable message. Redis must be
configured for moderation mutation throttling. Rebuild Vue assets with
`npm run build` and deploy the backend routes/controller with them. See
[chat infrastructure deployment](deploy/CHAT.md) for Redis, Reverb/WSS and worker
setup. This change does not enable production chat or deploy infrastructure.
