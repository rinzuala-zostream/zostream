# ZoAnalytics for Apple platforms

Add `Library/ios` as a local Swift Package and import `ZoAnalytics`.

```swift
import AVFoundation
import ZoAnalytics

let context = AnalyticsContext(
    platform: .ios,
    appVersion: "2.4.0",
    buildNumber: "104"
)

let analytics = PlaybackAnalyticsSession(
    sessionId: analyticsSessionIdFromPlaybackStart,
    content: PlaybackContent(id: movieId, type: .movie),
    context: context
)
let adapter = AVPlayerAnalyticsAdapter(player: player, analytics: analytics)

// When the player is leaving:
let summary = analytics.finish(reason: .playerDestroyed)
await analyticsClient.submit(summary, credentials: credentials)
```

For force-quit recovery, save a local checkpoint from the periodic player task
or when the scene backgrounds. This does not make a network request:

```swift
try await analyticsClient.saveLocally(
    analytics.checkpoint(),
    ownerKey: credentials.ownerKey
)
```

Start `ZoAnalytics` once at app launch. The SDK monitors the active network path and fills an
unknown context network type with the detected Wi-Fi, cellular, Ethernet, or offline value.
The first reading can remain `unknown` briefly while iOS reports the initial path.

Keep the adapter alive for the lifetime of the player. Call `recordSeek` from
the app's seek control for exact seek direction and distance. The adapter also
observes player state, buffering, periodic position, completion, item failure,
resolution and HLS access-log bitrate.

Start one application-scoped runtime from the app entry point. The providers
are evaluated for every upload, so token refresh does not require rebuilding
the SDK:

```swift
ZoAnalytics.shared.start(
    collectionEnabled: { analyticsEnabled },
    credentials: { session.analyticsCredentials },
    context: {
        AnalyticsContext(
            appVersion: appVersion,
            buildNumber: buildNumber
        )
    }
)
```

Start with `analyticsEnabled = false`, then observe the Realtime Database
Boolean at `/config/analytics/enabled`:

```swift
let analyticsFlag = Database.database()
    .reference(withPath: "config/analytics/enabled")

analyticsFlag.observe(
    .value,
    with: { snapshot in
        analyticsEnabled = snapshot.value as? Bool ?? false
        ZoAnalytics.shared.collectionStateDidChange()
    },
    withCancel: { _ in
        analyticsEnabled = false
        ZoAnalytics.shared.collectionStateDidChange()
    }
)
```

Import `FirebaseDatabase` in the host app and retain this application-scoped
observer. It receives changes live and automatically reconnects after temporary
network loss.

Check `ZoAnalytics.shared.isCollectionEnabled` before creating and attaching a
playback adapter to avoid even local player observation while disabled. The
client also blocks persistence and every analytics network request as a final
guard.

This automatically records app open, foreground/background and session
duration, and flushes the persisted queue on launch/foreground. If the app
starts before login, call `ZoAnalytics.shared.credentialsDidChange()` after
credentials become available. iOS screen names and product actions carry
business meaning, so record them at the navigation/action point:

```swift
ZoAnalytics.shared.screenViewed("home")
ZoAnalytics.shared.track(
    name: "wishlist_added",
    properties: ["content_id": .string(movieId)]
)
```

Use `ZoAnalytics.shared.client` when submitting playback summaries. Call
`clearPending(ownerKey:)` on account deletion. On ordinary logout, either flush
first or retain the owner partition until that account next signs in.
