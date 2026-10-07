# ZoAnalytics

ZoAnalytics is Zo Stream's internal, Firebase-free analytics SDK. It contains
native collectors for Apple AVPlayer and Android Media3, but both platforms
produce the same v1 JSON contract and upload to:

```text
https://zostream.in/api/v4/analytic
```

The SDK accumulates playback measurements locally, persists a bounded retry
queue, and submits a final session summary when the player stops. While the app
is in the foreground it also sends a lightweight presence heartbeat every
50–70 seconds. Presence is best-effort, never queued, and analytics failures
never block playback or watch-position saving.
Playback quality metrics include the stream's observed bitrate and cumulative
`bytes_transferred`, allowing admin reports to show per-session and aggregate
data usage. Older SDK payloads without this optional field remain valid.

## Packages

- `ios/`: Swift Package named `ZoAnalytics` (iOS 15+).
- `android/`: Android library module named `zoanalytics` (minSdk 21).
- `tv/`: TypeScript package named `@zostream/analytics-tv` for Samsung Tizen,
  LG webOS and Shaka/HTML5 video players.
- `contract/`: shared schema notes and a canonical payload fixture.

## Required headers

The host app supplies fresh credentials when flushing:

```text
Authorization: Bearer <access-token>
Device-Token: <device-token>
X-Analytics-SDK-Version: 1.4.0
X-Platform: ios|tvos|android|tv
```

Access tokens are never persisted by the SDK. Pending records are partitioned
by an opaque `ownerKey`; the host should use a stable per-account key and clear
that partition on logout or account deletion.

## Application flow

Start the platform runtime once when the app starts. It automatically observes
app open, foreground/background and session duration, persists events first,
and batch uploads without blocking the UI. Android can also observe Activity
screens; iOS and Compose navigation should report their stable product screen
name with `screenViewed`. Search, wishlist, download and purchase actions use
the allowlisted `track` call at the action point.

```text
app start -> lifecycle observation -> local queue -> background batch upload
business action --------------------^
```

There is no continuously running background service. Foreground presence uses a
60-second interval with jitter; backgrounding sends a best-effort offline signal
and stops the timer. Redis expires stale presence after 150 seconds, so force
quit and lost-network devices disappear without a database write or cleanup job.

## Firebase Realtime Database kill switch

Observe the Boolean at `/config/analytics/enabled` with a Firebase Realtime
Database value listener. Start with the app value set to `false`, pass its
provider to `ZoAnalytics.start`, then call `collectionStateDidChange()` whenever
the listener receives a change.

When the value is `false`, the SDK does not create new product records, persist
playback summaries, flush its queue or call an analytics endpoint. Existing
queued records remain private on the device and can upload only after the flag
is enabled again. `clearPending` remains available for logout/account deletion.

Firebase stays in the host app rather than becoming an SDK dependency. This
keeps the analytics AAR and Swift Package usable without Firebase and lets the
host choose its Firebase project and listener lifecycle.

## Playback flow

```text
startPlayback -> attach player -> local observation -> finish -> enqueue -> flush
```

`finish` creates a cumulative, idempotent summary. A `session_id` plus
monotonically increasing `revision` prevents retries from double counting.

See platform READMEs for integration examples. The matching server contract is
documented in `../docs/ANALYTICS_API_V4.md`.
