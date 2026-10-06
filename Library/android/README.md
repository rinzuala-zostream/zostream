# ZoAnalytics for Android

Add the module to the host build or publish its AAR internally. It targets
compileSdk 36, minSdk 21, Kotlin 2.0 and Media3 1.8.0 to match the Zo Stream
Android application.

```kotlin
val context = AnalyticsContext(
    appVersion = BuildConfig.VERSION_NAME,
    buildNumber = BuildConfig.VERSION_CODE.toString(),
    platform = AnalyticsPlatform.ANDROID,
    networkType = NetworkType.WIFI,
)

val session = PlaybackAnalyticsSession(
    sessionId = analyticsSessionIdFromPlaybackStart,
    content = PlaybackContent(movieId, PlaybackContentType.MOVIE),
    context = context,
)
val adapter = Media3AnalyticsAdapter(player, session)

// Activity/Fragment teardown:
adapter.close()
val summary = session.finish(PlaybackEndReason.PLAYER_DESTROYED)
analyticsClient.submit(summary, credentials)
```

Save a device-only checkpoint periodically or when the activity backgrounds:

```kotlin
analyticsClient.saveLocally(session.checkpoint(), credentials.ownerKey)
```

Start one application-scoped runtime from `Application.onCreate`. Providers are
read for every request, so refreshed credentials are used automatically:

```kotlin
val analytics = ZoAnalytics.start(
    application = this,
    collectionEnabled = { analyticsEnabled.get() },
    credentials = { session.analyticsCredentialsOrNull() },
    context = {
        AnalyticsContext(
            appVersion = BuildConfig.VERSION_NAME,
            buildNumber = BuildConfig.VERSION_CODE.toString(),
        )
    },
)
```

Initialize `analyticsEnabled` as `AtomicBoolean(false)`, then observe the
Realtime Database Boolean at `/config/analytics/enabled`:

```kotlin
val analyticsFlag = FirebaseDatabase.getInstance()
    .getReference("config/analytics/enabled")

analyticsFlag.addValueEventListener(object : ValueEventListener {
    override fun onDataChange(snapshot: DataSnapshot) {
        analyticsEnabled.set(snapshot.getValue(Boolean::class.java) ?: false)
        analytics.collectionStateDidChange()
    }

    override fun onCancelled(error: DatabaseError) {
        analyticsEnabled.set(false)
        analytics.collectionStateDidChange()
    }
})
```

Import `com.google.firebase.database.*`. Keep one application-scoped listener;
it is event driven and does not poll.

Check `analytics.isCollectionEnabled` before creating and attaching a Media3
adapter to avoid even local player observation while disabled. The client also
blocks persistence and every analytics network request as a final guard.

The runtime automatically records app open, foreground/background, session
duration and resumed Activity screen names. It flushes on launch/foreground.
For Compose destinations, call `screenViewed("home")` from the destination
change instead of relying only on the host Activity name. Business actions are
still explicit:

```kotlin
analytics.track("wishlist_added", mapOf("content_id" to movieId))
```

If launch happens before login, call `analytics.credentialsDidChange()` after
credentials become available. The client stores payloads in the app-private
files directory and uploads on a single background executor. It never stores
access or device tokens.

The adapter automatically observes Media3 play/pause, buffering, seeks,
completion, errors, speed and video size. The host calls the subtitle, audio,
fullscreen, Picture in Picture, cast and foreground methods when those UI
states change.
