# ZoAnalytics for Samsung Tizen and LG webOS

`@zostream/analytics-tv` is the Tizen, webOS and web-browser implementation of Zo Stream's
analytics SDK. It has no Firebase, React, or Shaka dependency. It emits the
same playback summary v1 contract as the iOS and Android packages and uploads
to the same `/api/v4/analytic` endpoints.

## Features

- HTMLVideoElement observation for play, pause, resume, seek, buffering,
  first-frame time, position, completion, speed, errors and watched ranges.
- Optional Shaka-compatible quality collection for resolution, bitrate,
  downloaded bytes, codecs, dropped frames and audio language.
- Product events, app lifecycle events and foreground presence heartbeats.
- Durable bounded local queue, owner partitioning, retention pruning,
  exponential retry with jitter, batch upload and invalid-record isolation.
- Firebase Realtime Database kill-switch support through a host-provided
  Boolean. Firebase is intentionally not an SDK dependency.
- No persisted bearer token or device token. Analytics failures never block
  playback.

The SDK auto-detects `tizen`, `webos`, or `web`. Existing native SDK mappings
remain unchanged: `android` is Android Mobile and `tv` is Android TV.

## Install/build

Use this folder as a local package, or build and pack it for internal use:

```bash
npm install
npm test
npm pack
```

## Application runtime

Start one instance at app boot. Providers are evaluated for every request, so
login and token refresh do not require recreating the SDK.

```ts
import {
  ZoAnalytics,
  createTVAnalyticsContext,
} from '@zostream/analytics-tv'

let analyticsEnabled = false // fail closed until Firebase returns true

export const analytics = new ZoAnalytics({
  baseUrl: 'https://zostream.in',
})

analytics.start({
  collectionEnabled: () => analyticsEnabled,
  credentials: () => currentSession
    ? {
        accessToken: currentSession.accessToken,
        deviceToken: currentDeviceToken,
        ownerKey: currentSession.userId,
      }
    : null,
  context: () => createTVAnalyticsContext({
    appVersion: APP_VERSION,
    buildNumber: BUILD_NUMBER,
  }),
})
```

Observe `/config/analytics/enabled` in the host Firebase setup. On every value
or cancellation, update the Boolean and notify the SDK:

```ts
onValue(analyticsFlag, (snapshot) => {
  analyticsEnabled = snapshot.val() === true
  analytics.collectionStateDidChange()
}, () => {
  analyticsEnabled = false
  analytics.collectionStateDidChange()
})
```

Call `analytics.credentialsDidChange()` after login or token refresh. Call
`analytics.client.clearPending(ownerKey)` for account deletion. Ordinary logout
may retain the owner partition for the account's next login.

## Playback

Create one session after playback start succeeds and keep its adapter alive for
the player lifetime:

```ts
import {
  HTMLVideoAnalyticsAdapter,
  PlaybackAnalyticsSession,
} from '@zostream/analytics-tv'

const playback = new PlaybackAnalyticsSession({
  sessionId: analyticsSessionIdFromPlaybackStart, // omit to generate a UUID
  content: {
    id: movieId,
    type: 'movie',
    series_id: null,
    season_id: null,
    episode_id: null,
    is_downloaded: false,
    autoplay: false,
  },
  context: createTVAnalyticsContext({
    appVersion: APP_VERSION,
    buildNumber: BUILD_NUMBER,
  }),
})

const adapter = new HTMLVideoAnalyticsAdapter(videoElement, playback, {
  mediaPlayer: shakaPlayer,
  streamFormat: 'dash',
  checkpointIntervalMs: 30_000,
  onCheckpoint: (summary) => {
    if (analytics.isCollectionEnabled && credentials) {
      analytics.client.saveLocally(summary, credentials.ownerKey)
    }
  },
})

// Player teardown:
adapter.destroy()
await analytics.client.submit(
  playback.finish('player_destroyed'),
  credentials,
)
```

For exact player-exit meaning, finish with `back_pressed`, `content_changed`,
`next_episode`, `playback_error`, or another exported `PlaybackEndReason`.
Submit detailed Shaka/network failures with `createPlaybackErrorEvent()` and
`client.submitPlaybackError()`.

## Product analytics

```ts
analytics.screenViewed('home')
analytics.track('wishlist_added', { content_id: movieId })
analytics.track('purchase_completed', { plan_id: planId }, true)
```

Only the same allowlisted names supported by the native SDKs are accepted by
TypeScript. Properties are length-bounded before queueing.

## Required request headers

Every request contains:

```text
Authorization: Bearer <access-token>
Device-Token: <device-token>
X-Analytics-SDK-Version: 1.4.0
X-Platform: tizen|webos|web
```

The shared schema is in `../contract/playback-summary.schema.json`.
