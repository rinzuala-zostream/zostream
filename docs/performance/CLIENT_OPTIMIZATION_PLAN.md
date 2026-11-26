# Client optimization plan: Android, iOS and Web

## Evidence limits

`docs/CHAT_MOBILE.md` documents the mobile chat design: unread badges update from WebSocket frames, the ten-second unread poll was removed, recovery snapshots occur on initial load/open/reconnect and every five minutes while live, and disconnected fallback polling is used. It also says leaving/backgrounding the screen or logging out releases the connection. These are repository documents, not verification of the binaries currently deployed to production.

No Android, iOS or Web client checkout was inspected for this backend task, and no production access-log rows with user/device/app dimensions or WebSocket client telemetry were supplied. The high message endpoint count alone does not establish excessive polling, duplicate timers or background traffic.

## Required investigation

1. Compare deployed app version/build with the mobile implementations described in `docs/CHAT_MOBILE.md` and parity notes. Include web/Vue clients and older still-supported versions.
2. Record route calls per account/device per minute by foreground/background state, endpoint, status, client build and WebSocket connection state. Detect repeated identical `after`, `before`, `initial`, room and unread requests.
3. Count concurrent sockets per account/device and duplicate timer registrations when opening/closing room sheets, switching rooms, resuming from background and rotating/recreating screens.
4. Correlate Reverb close codes/errors and connection attempts with Nginx REST fallback calls. The November 26 refusal timestamp is a server-side connection failure, not evidence of client retry behavior.

## Required client behavior

- Maintain one WebSocket connection per authenticated account/session owner and share it across chat views. Do not create another room/badge connection from a child component if a shared connection exists.
- Cancel timers and socket subscriptions on background, logout and screen/session teardown. Do not poll while backgrounded. Recheck behavior after process recreation and network changes.
- Use exponential reconnect backoff with full jitter, a capped maximum delay and reset only after a stable connection. Avoid synchronized immediate reconnects across a fleet after Reverb recovery.
- Resume message synchronization from the last committed message ID/event cursor. Deduplicate by message/event ID; buffer events that arrive during a baseline fetch and replay them once the baseline completes.
- Use the existing paged `after`/`before` API for deltas/history. Do not repeatedly request the initial page to discover new messages.
- Throttle REST fallback while the socket is down. Use a bounded recovery cadence, honor server errors/Retry-After, and stop fallback when socket state is healthy. Avoid running both socket updates and a high-frequency polling timer.
- Keep unread state event-driven and reconcile with the documented five-minute integrity snapshot or on lifecycle/reconnect boundaries. Do not restore the removed ten-second unread polling unless measured event loss justifies it.
- Instrument app build, lifecycle state, socket state, retry delay, fallback reason and request correlation ID without logging message bodies, phone numbers or credentials.

## Acceptance checks

- Foreground connected session: one socket owner, no duplicate timers, no repeated initial snapshots; message and unread updates arrive from events.
- Background session: no periodic REST polling and no reconnect storm; foreground resume performs one bounded reconciliation.
- Reverb outage/recovery: retries follow jittered backoff, REST fallback remains bounded, and one recovery snapshot closes any cursor gap.
- Poor network/reordered frames: cursors prevent gaps, duplicates and late events from changing unread counts twice.
- Older supported builds retain compatible REST recovery and response parsing while newer builds use deltas.

Measure requests/account/minute, duplicate request ratio, connection attempts/account/minute, concurrent sockets/account, reconnect recovery time, event gap rate, and REST fallback rate by client version before and after rollout.
