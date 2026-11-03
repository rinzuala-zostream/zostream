# Apple trailer playback

The iOS and native Mac trailer players now use the existing HLS utility before
playing MPEG-DASH (`.mpd`) trailers. Direct HTTPS HLS/MP4 playback stays direct.
The main playback/session/entitlement endpoints and MPD conversion script are
unchanged.

## Request and cache isolation

```
GET /api/hls/check-folder?context=trailer&url=<percent-encoded MPD URL>
```

`HlsFolderController` opts trailer requests into
`hls/_trailers/<SHA-256 of the complete resolved MPD URL>/master.m3u8`.
The source includes its host, filename and signed query, preventing collisions
with main-video folders or other trailers. The same existing converter creates
these manifests. Ordinary calls without `context=trailer` retain their previous
paths and response fields.

Successful trailer responses include `data.context = "trailer"` and
`data.stream_url`. Apple clients require that acknowledgement and an HTTPS HLS
URL before sending the result to AVPlayer. They fail cleanly against an older
backend instead of accidentally playing a cached main-video manifest.

The utility request carries no access token, device identity or playback token.
Signed source URLs retain literal plus signs. Conversion can take up to the
existing server build timeout; cancelling/changing trailers or leaving Details
cancels the client task and ignores late results. Backgrounding pauses playback,
including a conversion that completes later.

## Rollout

1. Deploy the updated `app/Http/Controllers/HlsFolderController.php` to the VPS
   before distributing the new clients. Use the normal PHP deployment/OPcache
   reload procedure. No new route, migration or Node-script change is needed.
2. Build/distribute the updated iOS app and native Mac app. Existing clients
   continue using the original playback flow.
3. Check a real MPD trailer on each Apple platform, an existing HLS/MP4 trailer,
   switching clips during preparation and leaving Details during preparation.

Local validation: 37 backend tests (34 existing playback-security tests plus
three trailer-cache isolation tests), five Apple trailer tests, and native Mac
release packaging/signature verification. iOS trailer view/model/resolver were
type-checked with the installed macOS SwiftUI SDK using the iOS actor-isolation
settings, and the complete edited iOS view passed syntax parsing. A full iOS
build and live CDN playback still require Xcode/iOS SDK and runtime QA.
