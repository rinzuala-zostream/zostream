# Chat interactions and player overlay — 30 October

Included: emoji reactions, replies with original-message navigation, private-room
typing indicators, spoiler-safe quotes/overlays, movie moments, and the transient
player message overlay. Room polls and invitation-link changes are excluded.
No chat preloading has been added.

## Rollout

Deploy the backend and run the new migration on chat PostgreSQL, before releasing
Android/iOS builds with the updated local ZoAnalytics packages:

```sh
php artisan chat:migrate --force
php artisan config:cache
sudo supervisorctl restart zostream-chat-delivery zostream-reverb
```

`2026_10_30_000001_add_chat_interactions` adds reply/moment metadata and the
`chat_reactions` table to PostgreSQL. Main MySQL schema remains unchanged. Restart
all delivery lanes if the VPS uses multiple workers. The worker must be updated
for the `overlay_live` freshness flag; clients do not display overlays when that
flag is absent. Existing clients ignore the additional message fields.

Android and iOS both need rebuilding. The iOS project includes the new
`Pages/PlayerChatOverlay.swift` through its synchronized source folder.

## Behavior and API

* Long press a message to reply or choose 😂 ❤️ 🔥 😢 👏 😮. Tap a reaction count
  to add/remove your reaction. Desired state writes are idempotent per
  message/user/emoji; moderation, room access and block rules apply.
* `POST /rooms/{room}/messages` accepts optional `reply_to_id` and
  `moment: {seconds, content_type, content_id}`. A reply must target the same room;
  a movie moment needs matching active playback and the matching movie/watch room.
  Sending retries preserve their request ID and their reply/moment context.
* Messages include `reply`, `reply_to_id`, `reactions`, `my_reactions` and `moment`.
  Reply previews never disclose blocked/deleted/expired parents or spoiler bodies.
  Reactions are personalized outside the shared history cache. Realtime reaction
  events refresh the specific message, including older visible history.
* `GET /rooms/{room}/messages/{message}` returns the authorized current message.
  `POST .../reactions` accepts `{emoji, active}`.
* `POST /rooms/{room}/typing` accepts `{active}`. `GET .../typing` returns eligible
  other typists. Indicators are private-room only, use six-second cache expiry,
  and are refreshed on realtime events instead of viewer polling. Clients send
  at most one active heartbeat every three seconds while typing, plus stop events.
* Timestamp taps seek only in a matching active player. Watch Together guests
  keep following their host, so independent moment seeks are disabled for guests.

## Overlay

The player uses a separate authorized live feed without fetching history or
marking messages read. Watch Together uses its active private room; otherwise
only the current movie/episode room is used. Global/other-room messages never
appear. Server freshness checks exclude messages older than ten seconds from
this presentation, while normal chat delivery/history still works. Each authorized
feed also captures a message cursor: messages already present before an overlay
is enabled or reconnected are never animated, even if their delivery was queued.

New rows enter at bottom-right on a translucent black gradient. New rows push
older rows upward; each row independently fades upward after about 4.5 seconds.
At most three rows are retained. Empty overlays have no panel or placeholder.
Spoilers display only “Spoiler message”. Deleted rows disappear immediately;
server-side access/block/session checks apply before sending message bodies.

Show chat overlay is a persisted player setting, default on. Switching it off,
opening the chat panel, ads, leaving the foreground, changing account/content or
ending the watch context stops/clears the overlay. Turning it back on does not
load history. The layout reserves space above playback controls and subtitles;
verify this on small displays and with your actual subtitle tracks. Reverb feed
presence may suppress duplicate push notifications while the overlay is active,
but does not advance the user's read cursor.

## Validation on the VPS and two devices

1. React twice/retry a reaction: one count. Remove it; verify the other account
   updates, including an older message. Try a muted/blocked/non-member account.
2. Reply to a message, tap its quote to load/navigate to older history, then delete
   or block its original sender. Confirm spoiler/deleted previews stay concealed.
3. Type in a private room: the other member sees the name; it disappears after
   stopping/closing or after six seconds. Global/Movie chat has no typing indicator.
4. Share a moment from the player; tap it in the matching player. Verify another
   title/global room cannot claim that timestamp, and guests retain host sync.
5. Close the chat panel and send three fresh messages: bottom-right stacking,
   independent expiry, maximum three rows, and no remaining background when empty.
   Test ON/OFF, ads, subtitles, rotation, reconnect, old queued messages, account
   switching, room termination and blocked/deleted messages. History/recovery
   must never enqueue overlay rows.
