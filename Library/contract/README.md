# Contract rules

- All durations and positions are non-negative integer milliseconds.
- All timestamps are ISO 8601 UTC.
- Playback `session_id` and product `event_id` values are UUIDs.
- `revision` increases whenever a checkpoint or final summary is regenerated.
- `watched_ms` counts actual playing time and includes replays.
- `unique_watched_ms` is the merged duration of watched media ranges.
- Seeking to the end does not mark a session complete.
- Completion means natural end or at least 90% unique watched coverage.
- The SDK persists payloads, owner partitions and retry metadata, but never
  persists bearer tokens or device tokens.
- `playback-summary.schema.json` is the server/client validation source for v1.
- `playback-final.example.json` is the canonical cross-platform fixture.
