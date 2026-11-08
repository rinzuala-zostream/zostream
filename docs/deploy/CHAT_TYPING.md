# Typing indicators

Typing is available in Global, movie, episode and private rooms. Previously both
native clients and the backend restricted it to private rooms; public rooms could
not show it.

The sender posts its state to `POST /api/v4/chat/rooms/{room}/typing`. The realtime
worker publishes a `chat.changed` WebSocket event with `kind: typing` on the
room's authorized channel. Receivers fetch the current list from the matching GET
endpoint. This keeps room access, membership and block filtering on the server.
Typing is not a chat message and does not generate a push notification.

While a chat is visible, clients also recover the typing list every three seconds
in case a socket event is missed or delayed. Concurrent refreshes are coalesced.
The recovery loop stops when chat is hidden, dismissed or backgrounded. A typist
expires after six seconds without another update. Sending, clearing the composer,
hiding chat and switching rooms send an inactive state when possible.

The room cache retains up to twenty recent active users under a short cache lock
so simultaneous typing updates cannot overwrite one another. Readers never see
themselves, blocked users in either direction or former private-room members.
Movie and episode rooms retain the existing playback authorization requirements.

Both native apps display one compact animated-dot bubble containing up to three
overlapping rounded first-letter avatar boxes. Additional typists appear as +N.
Screen-reader descriptions name the typists; reduced-motion settings stop the dot
animation.

Deploy backend changes and restart the existing realtime/all `chat:deliver`
workers, then release the updated Android/iOS apps. This typing change needs no
new database migration. The earlier reaction/reply notification migration remains
required for that separate feature.
