# Zo Stream Analytics API v4

## Purpose

This API collects Android and iOS product and playback analytics without
Firebase. All public ingestion routes use the `/api/v4/analytic` prefix.
Analytics data is stored through a separate `analytics` database connection;
the existing `watch_position`, `n_active_streams`, billing and subscription
tables remain unchanged.

Analytics failures must never block playback, watch-position saving,
subscription checks or device entitlement.

## Architecture

```text
Android / iOS player
        |
        |-- POST /api/v4/playback/sessions
        |      Existing playback start response supplies an analytics session ID
        |
        |-- Local analytics collector
        |      No heartbeat request; metrics are accumulated on the device
        |
        |-- POST /api/v4/playback/sessions/stop
        |      Existing watch-position and stream-stop behavior
        |
        `-- PUT /api/v4/analytic/playback/{session_id}
               Final playback summary
                         |
                         v
                Analytics database
                         |
                         v
                Daily rollup worker
                         |
                         v
                Admin report endpoints
```

The stop and analytics requests run independently and may be sent in parallel.
The client stores the analytics summary locally before attempting upload. A
failed upload is retried later and does not change the result of the stop call.

## Conventions

- JSON keys use `snake_case`.
- Identifiers use UUID strings where the client or server creates them.
- Positions and durations use integer milliseconds and end in `_ms`.
- Timestamps use ISO 8601 UTC, for example `2026-10-06T13:02:10Z`.
- Every payload includes `schema_version`.
- Unknown top-level keys are rejected. Unknown keys inside `properties` or
  `metrics` are removed unless allow-listed by the server.
- Mobile clients must not send email addresses, phone numbers, payment tokens,
  receipts, passwords, access tokens, stream URLs or exact GPS locations.

## Authentication

Mobile ingestion requests require the same customer authentication used by the
v4 playback routes:

```http
Authorization: Bearer <access-token>
Device-Token: <device-token>
Content-Type: application/json
Accept: application/json
X-Analytics-SDK-Version: 1.2.0
X-Platform: ios
```

The server derives `user_id` from the access token and resolves `device_id`
from `Device-Token`. Values supplied by the client cannot override either ID.

Admin report routes require admin authentication and do not accept customer
access tokens.

## Playback lifecycle

### 1. Start playback

The existing route remains the source of playback authorization:

```http
POST /api/v4/playback/sessions
```

Its response gains an optional `analytics` object. Failure to prepare this
object must not fail playback.

```json
{
  "status": "success",
  "stream_token": "existing-stream-token",
  "watch_position": 120000,
  "analytics": {
    "enabled": true,
    "session_id": "019b1234-7e58-7000-a123-456789abcdef",
    "schema_version": 1,
    "upload_path": "/api/v4/analytic/playback/019b1234-7e58-7000-a123-456789abcdef",
    "max_batch_size": 20
  }
}
```

If analytics is remotely disabled, the response is:

```json
{
  "analytics": {
    "enabled": false,
    "schema_version": 1
  }
}
```

The SDK begins local collection after receiving the playback response. It does
not call an analytics heartbeat endpoint.

### 2. Collect locally

The SDK observes playback state and accumulates:

- actual playing time;
- unique watched time and replayed time;
- pause, resume and seek counts;
- initial load and rebuffering duration;
- quality changes and dropped frames when the player exposes them;
- subtitle, audio track and playback-speed selections;
- playback errors;
- foreground, background, Picture in Picture, cast or AirPlay state;
- completion and end reason.

A local snapshot may be written every 15 to 30 seconds. This is a device-only
write and does not cause a network request.

### 3. Stop playback and submit analytics

When the player stops, is replaced, or is destroyed, the app performs these
independent operations:

```text
Task A: POST /api/v4/playback/sessions/stop
Task B: PUT  /api/v4/analytic/playback/{session_id}
```

The app does not wait for Task B before dismissing the player. It first saves
the final payload in the SDK's local pending queue and removes it only after a
successful 2xx response.

### 4. Recover interrupted sessions

`destroy` callbacks are not guaranteed after a crash or force quit. On the next
launch, the SDK finalizes an unfinished local session with
`end_reason = "app_terminated"` and submits it through the batch endpoint.

## Ingestion endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v4/analytic/config` | Cached SDK controls and supported schema |
| `PUT` | `/api/v4/analytic/playback/{session_id}` | Upsert one playback summary |
| `POST` | `/api/v4/analytic/playback/batch` | Retry several pending summaries |
| `POST` | `/api/v4/analytic/playback/{session_id}/errors` | Store a fatal or diagnostic playback error |
| `POST` | `/api/v4/analytic/events/batch` | Store non-playback product events |

## GET `/api/v4/analytic/config`

The app caches this response for 24 hours. Failure to fetch it leaves the last
cached configuration active. With no cached value, analytics defaults to
enabled with final-summary upload only.

### Response

```json
{
  "status": "success",
  "data": {
    "enabled": true,
    "schema_version": 1,
    "minimum_sdk_version": "1.0.0",
    "checkpoint_upload_enabled": false,
    "local_snapshot_interval_seconds": 30,
    "max_pending_sessions": 500,
    "pending_retention_days": 7,
    "max_batch_size": 20,
    "max_payload_bytes": 65536,
    "sample_rate": 1.0
  }
}
```

`sample_rate` ranges from `0.0` to `1.0`. Playback failures may always be
collected even when successful sessions are sampled.

## PUT `/api/v4/analytic/playback/{session_id}`

Creates or updates one playback session. `{session_id}` must be the UUID issued
with the playback start response.

The server uses `(session_id, revision)` for idempotency. A larger revision
replaces the stored summary. The same or an older revision returns success
without changing stored data.

### Request

```http
PUT /api/v4/analytic/playback/019b1234-7e58-7000-a123-456789abcdef
Authorization: Bearer <access-token>
Device-Token: <device-token>
Content-Type: application/json
X-Analytics-SDK-Version: 1.2.0
X-Platform: ios
```

```json
{
  "schema_version": 1,
  "revision": 1,
  "state": "final",
  "started_at": "2026-10-06T12:30:00Z",
  "ended_at": "2026-10-06T13:02:10Z",
  "end_reason": "player_destroyed",
  "content": {
    "id": "movie-123",
    "type": "movie",
    "series_id": null,
    "season_id": null,
    "episode_id": null,
    "is_downloaded": false,
    "autoplay": false
  },
  "timing": {
    "duration_ms": 2100000,
    "watch_position_ms": 1840000,
    "max_position_ms": 1905000,
    "watched_ms": 1765000,
    "unique_watched_ms": 1690000,
    "replayed_ms": 75000,
    "foreground_watch_ms": 1765000,
    "background_play_ms": 0,
    "startup_ms": 1240
  },
  "interaction": {
    "play_count": 2,
    "pause_count": 3,
    "resume_count": 2,
    "seek_count": 4,
    "seek_forward_ms": 180000,
    "seek_backward_ms": 45000,
    "fullscreen_count": 1,
    "pip_count": 0,
    "cast_count": 0
  },
  "buffering": {
    "count": 3,
    "total_ms": 8400,
    "longest_ms": 4100
  },
  "quality": {
    "initial": "720p",
    "final": "1080p",
    "change_count": 1,
    "average_bitrate_kbps": 3200,
    "dropped_frames": 4,
    "rendered_frames": 52800,
    "video_codec": "h264",
    "audio_codec": "aac",
    "stream_format": "hls"
  },
  "tracks": {
    "audio_language": "mizo",
    "subtitle_enabled": true,
    "subtitle_language": "english",
    "playback_speed": 1.0
  },
  "result": {
    "completed": false,
    "completion_percent": 80.5,
    "milestones": [25, 50, 75],
    "error_count": 0
  },
  "context": {
    "network_type": "wifi",
    "app_version": "2.4.0",
    "build_number": "104",
    "os_version": "26.0",
    "device_model": "iPhone",
    "device_category": "phone",
    "locale": "en-IN",
    "timezone": "Asia/Kolkata"
  }
}
```

### Required fields

- `schema_version`
- `revision`
- `state`
- `started_at`
- `content.id`
- `content.type`
- `timing.watch_position_ms`
- `timing.watched_ms`
- `context.app_version`
- `context.platform`, unless supplied by the trusted request header

### Enumerations

`state`:

- `checkpoint`
- `final`

`content.type`:

- `movie`
- `episode`
- `live`

`end_reason`:

- `completed`
- `user_closed`
- `back_pressed`
- `content_changed`
- `next_episode`
- `app_backgrounded`
- `app_terminated`
- `playback_error`
- `network_lost`
- `subscription_expired`
- `player_destroyed`
- `unknown`

### Accepted response

```http
HTTP/1.1 200 OK
```

```json
{
  "status": "success",
  "data": {
    "accepted": true,
    "stored": true,
    "session_id": "019b1234-7e58-7000-a123-456789abcdef",
    "revision": 1,
    "received_at": "2026-10-06T13:02:12Z"
  }
}
```

### Duplicate or old revision response

```json
{
  "status": "success",
  "data": {
    "accepted": true,
    "stored": false,
    "reason": "revision_already_processed",
    "session_id": "019b1234-7e58-7000-a123-456789abcdef",
    "revision": 1
  }
}
```

The SDK treats both responses as successful and removes the local pending item.

## POST `/api/v4/analytic/playback/batch`

Retries final or checkpoint summaries saved in the local queue. A request may
contain at most 20 sessions and must not exceed 256 KiB.

### Request

```json
{
  "schema_version": 1,
  "sessions": [
    {
      "session_id": "019b1234-7e58-7000-a123-456789abcdef",
      "summary": {
        "schema_version": 1,
        "revision": 2,
        "state": "final",
        "started_at": "2026-10-06T12:30:00Z",
        "ended_at": "2026-10-06T13:02:10Z",
        "end_reason": "app_terminated",
        "content": {
          "id": "movie-123",
          "type": "movie"
        },
        "timing": {
          "duration_ms": 2100000,
          "watch_position_ms": 1840000,
          "watched_ms": 1765000
        },
        "context": {
          "platform": "ios",
          "app_version": "2.4.0"
        }
      }
    }
  ]
}
```

### Response

```json
{
  "status": "success",
  "data": {
    "accepted": [
      {
        "session_id": "019b1234-7e58-7000-a123-456789abcdef",
        "revision": 2
      }
    ],
    "ignored": [],
    "rejected": []
  }
}
```

The client deletes accepted and ignored items. It retains retryable rejected
items and permanently removes invalid items after recording a local diagnostic.

## POST `/api/v4/analytic/playback/{session_id}/errors`

This endpoint is for a fatal playback error or a diagnostic error that must be
investigated independently. Ordinary transient errors are counted locally and
included in the final summary to avoid excessive requests.

### Request

```json
{
  "schema_version": 1,
  "event_id": "019b1234-9701-7000-b123-456789abcdef",
  "occurred_at": "2026-10-06T12:48:30Z",
  "position_ms": 1080000,
  "category": "segment",
  "stage": "during_playback",
  "code": "HTTP_404",
  "http_status": 404,
  "is_fatal": false,
  "is_retryable": true,
  "retry_count": 1,
  "network_type": "wifi",
  "sanitized_message": "Media segment was unavailable"
}
```

`event_id` is unique. Repeating the request returns success without creating a
duplicate error row. Messages are length-limited and sanitized. URLs, headers,
tokens and response bodies are not accepted.

## POST `/api/v4/analytic/events/batch`

Stores non-playback product events. The request may contain at most 50 events.
The SDK normally sends these events when the app backgrounds, after 50 queued
events, or on the next launch. It does not upload each event separately.

### Supported event names

- `app_opened`
- `app_foregrounded`
- `app_backgrounded`
- `app_session_ended`
- `screen_viewed`
- `content_impression`
- `content_opened`
- `search_performed`
- `search_result_selected`
- `search_empty`
- `wishlist_added`
- `wishlist_removed`
- `download_started`
- `download_completed`
- `download_failed`
- `notification_opened`
- `paywall_viewed`
- `plan_selected`
- `purchase_started`
- `purchase_completed`
- `purchase_failed`
- `purchase_cancelled`
- `restore_purchase_completed`

Purchase events are analytics signals only. They never grant subscription or
content access; the billing API remains authoritative.

### Request

```json
{
  "schema_version": 1,
  "events": [
    {
      "event_id": "019b1234-a222-7000-a123-456789abcdef",
      "name": "content_opened",
      "occurred_at": "2026-10-06T12:28:00Z",
      "app_session_id": "019b1234-a000-7000-a123-456789abcdef",
      "properties": {
        "content_id": "movie-123",
        "content_type": "movie",
        "source": "home_banner",
        "position": 1
      }
    },
    {
      "event_id": "019b1234-a333-7000-a123-456789abcdef",
      "name": "search_performed",
      "occurred_at": "2026-10-06T12:29:00Z",
      "app_session_id": "019b1234-a000-7000-a123-456789abcdef",
      "properties": {
        "query_length": 8,
        "result_count": 4
      }
    }
  ],
  "context": {
    "platform": "android",
    "app_version": "2.4.0",
    "build_number": "104",
    "os_version": "16",
    "locale": "en-IN",
    "timezone": "Asia/Kolkata"
  }
}
```

Raw search text is excluded by default. `query_length` and `result_count` are
enough for basic search-quality analysis without retaining personal queries.

## Validation limits

| Value | Limit |
| --- | --- |
| Single playback request | 64 KiB |
| Playback batch | 20 sessions / 256 KiB |
| Event batch | 50 events / 256 KiB |
| Error message | 500 characters |
| String property | 255 characters unless explicitly documented |
| `watched_ms` | `0...86,400,000` per submitted session |
| `completion_percent` | `0...100` |
| Counters | non-negative integers |
| Client timestamp drift | recorded, but server receive time remains authoritative |

The server clamps or rejects impossible numeric values and never trusts client
analytics for billing, entitlement, royalty payment or fraud decisions.

## Rate limits

Rate-limit keys use authenticated user and device identity rather than shared
IP addresses.

| Route | Suggested limit |
| --- | --- |
| Playback upsert | 30 per device per minute |
| Playback batch | 10 per device per minute |
| Playback error | 20 per device per minute |
| Product event batch | 20 per device per minute |
| Admin reports | 60 per admin per minute |

## Error contract

```json
{
  "status": "error",
  "code": "ANALYTICS_VALIDATION_FAILED",
  "message": "The analytics payload is invalid.",
  "errors": {
    "timing.watched_ms": [
      "The timing.watched_ms field must be a non-negative integer."
    ]
  },
  "retryable": false
}
```

| HTTP status | Meaning | SDK behavior |
| --- | --- | --- |
| `200` | Stored, updated, duplicate or older revision safely handled | Remove pending item |
| `400` | Invalid JSON | Remove and log locally |
| `401` | Access token expired or invalid | Refresh once, then retry |
| `403` | Device or session mismatch | Do not retry unchanged payload |
| `413` | Payload too large | Split batch or remove optional detail |
| `422` | Validation failed | Do not retry unchanged payload |
| `429` | Rate limited | Retry using `Retry-After` |
| `500` / `503` | Temporary service failure | Retry with backoff and jitter |

Suggested retry delays are 30 seconds, 2 minutes, 10 minutes, 1 hour, and the
next app launch. Playback and UI never wait for these retries.

## Admin report endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v4/analytic/reports/overview` | Main KPIs |
| `GET` | `/api/v4/analytic/reports/content` | Paginated content performance |
| `GET` | `/api/v4/analytic/reports/content/{type}/{id}` | One title or episode |
| `GET` | `/api/v4/analytic/reports/quality` | Startup, buffering and quality metrics |
| `GET` | `/api/v4/analytic/reports/errors` | Error groups and affected sessions |
| `GET` | `/api/v4/analytic/reports/users/{user_id}/sessions` | Support investigation, audited |

Common query parameters:

```text
from=2026-10-01
to=2026-10-06
timezone=Asia/Kolkata
platform=ios|android|tv
app_version=2.4.0
content_type=movie|episode|live
page=1
per_page=50
```

### Overview response

```json
{
  "status": "success",
  "data": {
    "playback_starts": 12500,
    "valid_views": 10820,
    "unique_viewers": 4800,
    "watch_hours": 18240.5,
    "average_watch_minutes": 41.2,
    "completion_rate": 63.5,
    "startup_failure_rate": 0.9,
    "playback_error_rate": 1.8,
    "average_startup_ms": 1450,
    "rebuffer_ratio": 0.012
  },
  "filters": {
    "from": "2026-10-01",
    "to": "2026-10-06",
    "timezone": "Asia/Kolkata"
  }
}
```

## Metric definitions

```text
valid_view = watched_ms >= 10,000

completion = natural playback end
             OR unique_watched_ms / duration_ms >= 0.90

startup_failure = playback requested but first frame was never rendered

playback_error_rate = failed playback sessions / playback sessions started

rebuffer_ratio = buffer_ms / (watched_ms + buffer_ms)
```

Seeking near the end does not by itself mark a session complete. `watched_ms`
counts actual play time, while `unique_watched_ms` counts the union of watched
media ranges and excludes replayed portions.

## Analytics database

The Laravel application uses a separate connection named `analytics`.

Suggested tables:

### `playback_sessions`

Core queryable columns:

```text
id
session_id                 unique
user_id
device_id
subscription_id            nullable
content_id
content_type
revision
state
started_at
ended_at                   nullable
watch_position_ms
duration_ms
watched_ms
unique_watched_ms
startup_ms                 nullable
buffer_count
buffer_ms
error_count
completion_percent
completed
end_reason                 nullable
platform
app_version
sdk_version
metrics_json
created_at
updated_at
```

Recommended indexes:

```text
UNIQUE(session_id)
INDEX(content_type, content_id, ended_at)
INDEX(user_id, ended_at)
INDEX(platform, app_version, ended_at)
INDEX(ended_at)
```

### `playback_errors`

```text
id
event_id                   unique
session_id
category
stage
code
http_status                nullable
position_ms
is_fatal
is_retryable
retry_count
network_type
sanitized_message          nullable
occurred_at
created_at
```

### `analytics_events`

```text
id
event_id                   unique
user_id
device_id
app_session_id
name
occurred_at
platform
app_version
properties_json
created_at
```

### `daily_content_metrics`

```text
metric_date
content_type
content_id
platform
playback_starts
valid_views
unique_viewers
watch_ms
completed_views
buffer_count
buffer_ms
error_count
startup_ms_total
```

The request path performs validation and a small idempotent upsert only. Report
aggregation runs in a scheduled worker and updates `daily_content_metrics`.
Dashboard requests read rollups instead of scanning raw playback rows.

## Retention and privacy

- Raw playback sessions: suggested retention of 13 months.
- Playback errors with sanitized messages: suggested retention of 90 days.
- Daily aggregate metrics: retain according to business requirements.
- Account deletion removes or irreversibly anonymizes user-linked raw rows.
- Analytics collection honors the application's consent and analytics-disable
  setting.
- Production, staging and development use separate analytics databases.

## Minimum implementation order

1. Add the separate `analytics` database connection and migrations.
2. Add `analytics.session_id` to the playback-start response.
3. Implement playback summary validation and idempotent upsert.
4. Add iOS AVPlayer and Android Media3 collectors.
5. Add the local pending queue and batch retry.
6. Add non-playback event batching.
7. Add the daily rollup job and admin reports.

The first release can ship steps 1 through 5. Reports may initially query the
playback session table for small date ranges until the rollup worker is ready.
