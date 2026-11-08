# iOS publish notifications

Movie and episode publishing sends FCM notifications to topic `all`. The legacy
payload's `key` is the public movie/series ID; an episode release points to its
parent title. Android already subscribes to this topic. iOS previously registered
for direct invitation notifications without subscribing to publish broadcasts.

iOS now subscribes to `all` after APNs and FCM registration are ready, on the main
actor. Repeated callbacks for the same token do not create concurrent subscription
requests. Token rotation triggers subscription again, and stale completion
callbacks cannot replace the current token's subscription state. Firebase also
retries failed topic subscriptions; see the official
[topic subscription guide](https://firebase.google.com/docs/cloud-messaging/manage-topic-subscriptions).

Notification taps use `AppPushRouter` for both direct invitations and publish
notifications. Publish keys open the existing `/watch/{public-id}` details flow,
including its sign-in handling. The pending destination stays in memory until the
SwiftUI root can consume it. Invalid keys and the reserved `customer_support` key
are not interpreted as movie IDs. Foreground banner/sound/badge presentation still
applies to all notification types.

Ship the updated iOS app. On a physical device, allow notifications and verify a newly
published movie and episode in foreground, background and after a cold launch.
Tap each notification and confirm it opens the correct title. Also retest a direct
invitation notification. Verify the app's push entitlement/provisioning profile
and Firebase APNs credentials if delivery still fails.

## Poster and thumbnail attachments

The backend now includes `apns.fcm_options.image` and `aps.mutable-content = 1`
when the notification has an image. The existing common image field remains for
Android. Notifications without an image retain their plain payload. Movie/episode
publish notifications use the image URL already supplied by their publishing flow.
The URL must be publicly downloadable over HTTPS.

The iOS project includes and embeds the `ZoStreamNotificationService` target. It
downloads the image independently of the main app, converts WebP/PNG/JPEG to a JPEG
attachment, and downsamples to at most 1024 pixels per side. Downloads time out
within 20 seconds; files larger than 10 MB and invalid images fall back to the
original notification. Download failure or the extension's system deadline still
delivers the original title, message and routing data exactly once. See the
[Firebase image payload guide](https://firebase.google.com/docs/cloud-messaging/customize-messages/cross-platform)
and [Apple notification service extension documentation](https://developer.apple.com/documentation/usernotifications/unnotificationserviceextension).

Deploy the updated backend and release a new iOS build containing the extension.
No database migration is required. In Xcode, automatic signing must provision the
extension bundle ID `com.buannel.pvt.ltd.zostream.Zo-Stream.NotificationService`
under the same team as the app. Keep the app and extension version/build numbers
in sync when bumping releases. The existing app scheme builds and embeds this
target through its dependency.

On a physical device, test movie and episode publishes with real CDN images in
background and after the app is closed. Expand/long-press the notification to
inspect the larger image; iOS controls its collapsed/expanded presentation. Check
that tapping still opens the correct title. Also test a missing image URL and a
notification with no image; both must keep their title and message.

Local validation: 13 backend payload/movie/episode notification tests, 19 Swift
checks covering routing, APNs readiness, duplicate callbacks, token rotation and
subscription failure/retry, and the extension's Swift type checking. Standalone
checks cover PNG/JPEG/WebP conversion, image size limits, invalid images, HTTPS
validation and fallback delivery preserving message/routing data. Project checks
validate the extension dependency, embedding, signing settings and matching
versions. Production APNs/FCM delivery and the full iOS app build still require
Xcode and a device.
