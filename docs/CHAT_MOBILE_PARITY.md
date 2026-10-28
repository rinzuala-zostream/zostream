# Android and iOS chat flow review

Reviewed on 26 October 2026 against both mobile implementations, their chat SDKs,
the API routes and the private-room lifecycle rules. This is a source review
with compiler and SDK checks; it does not replace testing both apps on devices.

| Flow | Shared behavior after review |
| --- | --- |
| Home launcher | Global chat; hide the tab bar until a private room is available. |
| Player launcher | Chat toggle in the player controls. Portrait places video above chat; landscape places video on the left and chat in the right 40%. Movie/episode and Global tabs remain; Watch Together requires online playback. |
| Room creation | Create room in options; name, phone and private/watch selection in one form. Disable repeat submission while saving; retain the form on failure. |
| Invitations | One card per invitation with room name, inviter, room type, optional title, Accept and Decline. Show loading, empty and error states; allow refresh. |
| Private invite acceptance | Join the room, close invitations and restore its room tab. Decline removes only that invitation. |
| Watch invite acceptance | Seek and join when content ID and type match the current player. Otherwise the player callback opens the invited title; Home tells the user to open that title. |
| Private-room restore | Fetch active hosted/accepted rooms on sheet open and during 30-second reconciliation. |
| Unread badges | Launcher/controller totals and per-room counts; local socket deltas with five-minute connected integrity snapshots and one-minute disconnected recovery. No new background push service. |
| Read position | Server cursor per user/room; advance through visible messages, exclude own/blocked/deleted messages from counts, reopen at first unread with a divider. Failed read acknowledgements retry from local account storage. |
| Private-room expiry | Server denies access one hour after the last new message, or creation if empty, unless a live watch session keeps the room available. Opening or reading does not extend it. |
| End room | Creator only, private rooms only, confirmation required. Expired/ended rooms disappear and the selected room returns to Global. |
| Send and retry | Retry keeps the request UUID; room changes clear pending state. Preserve text edited while the previous send is in flight. Show a retry affordance on failure. |
| History | Prepend older messages; preserve the reader's position when newer messages arrive. Offer New messages to jump to the end. |
| Spoilers | Confirm marking, mask content and reveal on tap. Deleted messages cannot be reported or blocked. |
| Report/block | Other viewers only; cap report reasons at 500 characters. Refresh recent history after unblocking so previously hidden messages can reappear. |
| Timestamps | Respect explicit offsets; interpret unzoned database timestamps in Asia/Kolkata; handle epoch seconds/milliseconds. |
| Connection lifecycle | Reuse the screen's physical connection across sheet dismissal and tab changes; cached tabs catch up deltas without a full-page reload. Release on logout, background or screen destruction. |
| Response compatibility | Both SDKs accept boolean/numeric/string success flags, supported legacy direct payloads and status/result envelopes. Explicit failures remain failures and preserve the server message. |

Changes found during this review include the older Android invitation picker,
its extra create confirmation, premature iOS create-sheet dismissal, duplicate
submissions, Android clearing a newer draft, missing iOS retry icon, stale
report/error responses, delayed unblock recovery, incorrect unzoned Android
timestamps, and both apps forcing the reader to the bottom.

Home chat retains its existing presentation. Player chat is embedded on both
platforms; closing it restores the full player. Auxiliary dialogs retain their
native platform presentation. Watch Together synchronizes playback position
independently of the selected chat tab and continues while the chat panel is
hidden on the player. Leaving the player/backgrounding releases that session.
Host pause/play/seek controls synchronize through sequenced WebSocket state. Watch cards can route Home users into the invited player.

Watch Together guest controls: seeking/scrubbing is disabled for invitees;
creators retain seeking. A guest may pause locally without pausing the creator.
Play after a local pause requests a fresh authorized creator position before
resuming. Watch players use stable 1x speed, and device-clock differences are
not added to the server's extrapolated position. A creator's buffering state is
published as paused so guests do not advance while the creator is stalled.
Creator departure/end stops the guest player and its watch stream; the private
chat remains available. Both player screens show the Watch Together role/state.

Follow-up audit fixes cover failed/expired invite-room joins, an explicit iOS
join retry, retry payloads that include spoiler state, reports pinned to their
original room, blocked-user cache refresh on reopen/reconciliation, selected-tab
visibility on Android, and preserving scroll-follow intent during layout
changes. Android discards in-flight results after an account change and rejects
queued requests from the previous account before sending them. Malformed API
responses show a readable error instead of parser/internal diagnostics.

Verification includes Android Kotlin/Java compilation and SDK unit tests, Swift
SDK compilation, a standalone runner covering six response formats and two
server-error cases, and iOS chat-screen syntax checking. The installed command
line toolchain has no XCTest module or iOS app build environment, so the full
Swift test suite and screen rendering still require Xcode.

Device checks still needed: keyboard/safe-area layouts, VoiceOver/TalkBack,
invitation cards with several rooms, switching room while network requests are
slow, offline/reconnect, expiry with both users online, and cross-platform watch
invitations for movies and episodes (including identical IDs of different types).
Unread verification adds first-open/reopen with more than one history page,
visible-row acknowledgement, fast room switching, modal sheets covering the
transcript, offline read retries, and badge agreement between Android and iOS.


27 October follow-up: connected transcripts now reconcile every 30 seconds;
room and unread subscriptions renew signed five-minute channel leases. The
server stops publishing to expired leases after a 60-second boundary grace,
bounding metadata revocation to six minutes. The updated API/worker requires
both mobile SDK updates; use the exact auth response channel for subscriptions.

28 October retained-tab flow: twenty recent rooms keep up to 200 loaded messages
each. First opening loads history; returning displays the cache immediately and
fetches only newer pages. Room/lease subscription acknowledgements on the same
socket no longer request a reconnect snapshot. Presence, access and room/block
maintenance remain on the thirty-second timer; connected history integrity now
runs every five minutes. Launcher totals and tab unread badges share the existing
read state. See `CHAT_MOBILE.md` for the SDK event contract and cache boundaries.
