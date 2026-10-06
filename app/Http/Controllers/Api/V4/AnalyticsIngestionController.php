<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnalyticsIngestionController extends Controller
{
    private const PLATFORMS = ['ios', 'tvos', 'android', 'tv'];

    private const EVENT_NAMES = [
        'app_opened',
        'app_foregrounded',
        'app_backgrounded',
        'app_session_ended',
        'screen_viewed',
        'content_impression',
        'content_opened',
        'search_performed',
        'search_result_selected',
        'search_empty',
        'wishlist_added',
        'wishlist_removed',
        'download_started',
        'download_completed',
        'download_failed',
        'notification_opened',
        'paywall_viewed',
        'plan_selected',
        'purchase_started',
        'purchase_completed',
        'purchase_failed',
        'purchase_cancelled',
        'restore_purchase_completed',
    ];

    public function config(Request $request): JsonResponse
    {
        if ($error = $this->identityError($request)) {
            return $error;
        }

        return V4Response::success([
            'enabled' => $this->analyticsEnabled(),
            'schema_version' => 1,
            'minimum_sdk_version' => '1.2.0',
            'checkpoint_upload_enabled' => false,
            'local_snapshot_interval_seconds' => 30,
            'max_pending_sessions' => 500,
            'pending_retention_days' => 7,
            'max_batch_size' => 20,
            'max_payload_bytes' => 65_536,
            'sample_rate' => 1.0,
        ]);
    }

    public function upsertPlayback(Request $request, string $sessionId): JsonResponse
    {
        if ($error = $this->collectionDisabledError()) {
            return $error;
        }
        if ($error = $this->identityError($request)) {
            return $error;
        }
        if ($error = $this->payloadSizeError($request, 65_536)) {
            return $error;
        }

        $payload = $request->validate($this->playbackRules());
        $this->rejectUnknownPlaybackKeys($request->json()->all());
        $result = $this->storePlayback($request, $sessionId, $payload);

        return V4Response::success([
            'accepted' => true,
            'stored' => $result['stored'],
            'reason' => $result['stored'] ? null : 'revision_already_processed',
            'session_id' => $sessionId,
            'revision' => $result['revision'],
            'received_at' => now('UTC')->toIso8601String(),
        ]);
    }

    public function batchPlayback(Request $request): JsonResponse
    {
        if ($error = $this->collectionDisabledError()) {
            return $error;
        }
        if ($error = $this->identityError($request)) {
            return $error;
        }
        if ($error = $this->payloadSizeError($request, 262_144)) {
            return $error;
        }

        $rules = [
            'schema_version' => ['required', 'integer', 'in:1'],
            'sessions' => ['required', 'array', 'min:1', 'max:20'],
            'sessions.*.session_id' => ['required', 'uuid', 'distinct'],
            'sessions.*.summary' => ['required', 'array'],
        ];
        foreach ($this->playbackRules('sessions.*.summary.') as $key => $rule) {
            $rules[$key] = $rule;
        }
        $validated = $request->validate($rules);
        foreach ($validated['sessions'] as $index => $item) {
            $rawSummary = Arr::get($request->json()->all(), "sessions.{$index}.summary", []);
            $this->rejectUnknownPlaybackKeys($rawSummary, "sessions.{$index}.summary");
        }

        $accepted = [];
        $ignored = [];
        foreach ($validated['sessions'] as $item) {
            $result = $this->storePlayback($request, $item['session_id'], $item['summary']);
            $target = $result['stored'] ? 'accepted' : 'ignored';
            ${$target}[] = [
                'session_id' => $item['session_id'],
                'revision' => $result['revision'],
            ];
        }

        return V4Response::success([
            'accepted' => $accepted,
            'ignored' => $ignored,
            'rejected' => [],
        ]);
    }

    public function storePlaybackError(Request $request, string $sessionId): JsonResponse
    {
        if ($error = $this->collectionDisabledError()) {
            return $error;
        }
        if ($error = $this->identityError($request)) {
            return $error;
        }
        if ($error = $this->payloadSizeError($request, 65_536)) {
            return $error;
        }
        validator(['session_id' => $sessionId], ['session_id' => ['required', 'uuid']])->validate();

        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'position_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'category' => ['required', 'string', 'max:64'],
            'stage' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:128'],
            'http_status' => ['nullable', 'integer', 'between:100,599'],
            'is_fatal' => ['required', 'boolean'],
            'is_retryable' => ['required', 'boolean'],
            'retry_count' => ['required', 'integer', 'min:0', 'max:1000'],
            'network_type' => ['nullable', 'string', 'max:32'],
            'sanitized_message' => ['nullable', 'string', 'max:500'],
        ]);

        $now = now('UTC');
        $stored = DB::connection('analytics')->table('playback_errors')->insertOrIgnore([
            'event_id' => $data['event_id'],
            'session_id' => $sessionId,
            'user_id' => (string) $request->input('auth_user_id'),
            'device_id' => (string) $request->input('auth_device_id'),
            'category' => $data['category'],
            'stage' => $data['stage'],
            'code' => $data['code'],
            'http_status' => $data['http_status'] ?? null,
            'position_ms' => $data['position_ms'],
            'is_fatal' => $data['is_fatal'],
            'is_retryable' => $data['is_retryable'],
            'retry_count' => $data['retry_count'],
            'network_type' => $data['network_type'] ?? null,
            'sanitized_message' => $this->sanitizeMessage($data['sanitized_message'] ?? null),
            'occurred_at' => CarbonImmutable::parse($data['occurred_at'])->utc(),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;

        return V4Response::success([
            'accepted' => true,
            'stored' => $stored,
            'event_id' => $data['event_id'],
        ]);
    }

    public function batchEvents(Request $request): JsonResponse
    {
        if ($error = $this->collectionDisabledError()) {
            return $error;
        }
        if ($error = $this->identityError($request)) {
            return $error;
        }
        if ($error = $this->payloadSizeError($request, 262_144)) {
            return $error;
        }

        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.event_id' => ['required', 'uuid', 'distinct'],
            'events.*.name' => ['required', 'string', Rule::in(self::EVENT_NAMES)],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.app_session_id' => ['required', 'uuid'],
            'events.*.properties' => ['present', 'array', 'max:50'],
            'context' => ['required', 'array'],
            'context.platform' => ['required', Rule::in(self::PLATFORMS)],
            'context.app_version' => ['required', 'string', 'max:64'],
        ]);
        $platform = $this->platform($request, $data['context']['platform'] ?? null);
        $now = now('UTC');
        $accepted = [];
        $ignored = [];

        foreach ($data['events'] as $event) {
            $stored = DB::connection('analytics')->table('analytics_events')->insertOrIgnore([
                'event_id' => $event['event_id'],
                'user_id' => (string) $request->input('auth_user_id'),
                'device_id' => (string) $request->input('auth_device_id'),
                'app_session_id' => $event['app_session_id'],
                'name' => $event['name'],
                'occurred_at' => CarbonImmutable::parse($event['occurred_at'])->utc(),
                'platform' => $platform,
                'app_version' => $data['context']['app_version'],
                'properties_json' => json_encode(
                    $this->sanitizeProperties($event['properties']),
                    JSON_THROW_ON_ERROR
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;
            if ($stored) {
                $accepted[] = $event['event_id'];
            } else {
                $ignored[] = $event['event_id'];
            }
        }

        return V4Response::success([
            'accepted' => $accepted,
            'ignored' => $ignored,
            'rejected' => [],
        ]);
    }

    private function playbackRules(string $prefix = ''): array
    {
        return [
            $prefix.'schema_version' => ['required', 'integer', 'in:1'],
            $prefix.'revision' => ['required', 'integer', 'min:1', 'max:1000000'],
            $prefix.'state' => ['required', Rule::in(['checkpoint', 'final'])],
            $prefix.'started_at' => ['required', 'date'],
            $prefix.'ended_at' => ['nullable', 'date'],
            $prefix.'end_reason' => ['nullable', Rule::in([
                'completed', 'user_closed', 'back_pressed', 'content_changed',
                'next_episode', 'app_backgrounded', 'app_terminated', 'playback_error',
                'network_lost', 'subscription_expired', 'player_destroyed', 'unknown',
            ])],
            $prefix.'content' => ['required', 'array'],
            $prefix.'content.id' => ['required', 'string', 'min:1', 'max:191'],
            $prefix.'content.type' => ['required', Rule::in(['movie', 'episode', 'live'])],
            $prefix.'content.series_id' => ['nullable', 'string', 'max:191'],
            $prefix.'content.season_id' => ['nullable', 'string', 'max:191'],
            $prefix.'content.episode_id' => ['nullable', 'string', 'max:191'],
            $prefix.'content.is_downloaded' => ['required', 'boolean'],
            $prefix.'content.autoplay' => ['required', 'boolean'],
            $prefix.'timing' => ['required', 'array'],
            $prefix.'timing.duration_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.watch_position_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.max_position_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.watched_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.unique_watched_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.replayed_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.foreground_watch_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.background_play_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'timing.startup_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            $prefix.'interaction' => ['required', 'array'],
            $prefix.'interaction.play_count' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.pause_count' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.resume_count' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.seek_count' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.seek_forward_ms' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.seek_backward_ms' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.fullscreen_count' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.pip_count' => ['required', 'integer', 'min:0'],
            $prefix.'interaction.cast_count' => ['required', 'integer', 'min:0'],
            $prefix.'buffering.count' => ['required', 'integer', 'min:0'],
            $prefix.'buffering.total_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'buffering.longest_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            $prefix.'quality' => ['required', 'array'],
            $prefix.'quality.initial' => ['nullable', 'string', 'max:32'],
            $prefix.'quality.final' => ['nullable', 'string', 'max:32'],
            $prefix.'quality.change_count' => ['required', 'integer', 'min:0'],
            $prefix.'quality.average_bitrate_kbps' => ['nullable', 'integer', 'min:0'],
            $prefix.'quality.dropped_frames' => ['nullable', 'integer', 'min:0'],
            $prefix.'quality.rendered_frames' => ['nullable', 'integer', 'min:0'],
            $prefix.'quality.video_codec' => ['nullable', 'string', 'max:64'],
            $prefix.'quality.audio_codec' => ['nullable', 'string', 'max:64'],
            $prefix.'quality.stream_format' => ['nullable', 'string', 'max:32'],
            $prefix.'tracks' => ['required', 'array'],
            $prefix.'tracks.audio_language' => ['nullable', 'string', 'max:64'],
            $prefix.'tracks.subtitle_enabled' => ['required', 'boolean'],
            $prefix.'tracks.subtitle_language' => ['nullable', 'string', 'max:64'],
            $prefix.'tracks.playback_speed' => ['required', 'numeric', 'between:0.25,4'],
            $prefix.'result' => ['required', 'array'],
            $prefix.'result.error_count' => ['required', 'integer', 'min:0'],
            $prefix.'result.completion_percent' => ['required', 'numeric', 'between:0,100'],
            $prefix.'result.completed' => ['required', 'boolean'],
            $prefix.'result.milestones' => ['required', 'array'],
            $prefix.'result.milestones.*' => ['integer', 'distinct', Rule::in([25, 50, 75, 90])],
            $prefix.'context' => ['required', 'array'],
            $prefix.'context.platform' => ['required', Rule::in(self::PLATFORMS)],
            $prefix.'context.network_type' => ['required', Rule::in(['wifi', 'cellular', 'ethernet', 'offline', 'unknown'])],
            $prefix.'context.app_version' => ['required', 'string', 'max:64'],
            $prefix.'context.build_number' => ['required', 'string', 'max:64'],
            $prefix.'context.os_version' => ['required', 'string', 'max:128'],
            $prefix.'context.device_model' => ['required', 'string', 'max:128'],
            $prefix.'context.device_category' => ['required', 'string', 'max:32'],
            $prefix.'context.locale' => ['required', 'string', 'max:32'],
            $prefix.'context.timezone' => ['required', 'string', 'max:64'],
        ];
    }

    private function storePlayback(Request $request, string $sessionId, array $payload): array
    {
        validator(['session_id' => $sessionId], ['session_id' => ['required', 'uuid']])->validate();

        $revision = (int) $payload['revision'];
        $now = now('UTC');
        $row = [
            'session_id' => $sessionId,
            'user_id' => (string) $request->input('auth_user_id'),
            'device_id' => (string) $request->input('auth_device_id'),
            'subscription_id' => null,
            'content_id' => (string) Arr::get($payload, 'content.id'),
            'content_type' => (string) Arr::get($payload, 'content.type'),
            'revision' => $revision,
            'state' => (string) $payload['state'],
            'started_at' => CarbonImmutable::parse($payload['started_at'])->utc(),
            'ended_at' => isset($payload['ended_at'])
                ? CarbonImmutable::parse($payload['ended_at'])->utc()
                : null,
            'watch_position_ms' => (int) Arr::get($payload, 'timing.watch_position_ms', 0),
            'duration_ms' => (int) Arr::get($payload, 'timing.duration_ms', 0),
            'watched_ms' => (int) Arr::get($payload, 'timing.watched_ms', 0),
            'unique_watched_ms' => (int) Arr::get(
                $payload,
                'timing.unique_watched_ms',
                Arr::get($payload, 'timing.watched_ms', 0)
            ),
            'startup_ms' => Arr::get($payload, 'timing.startup_ms'),
            'buffer_count' => (int) Arr::get($payload, 'buffering.count', 0),
            'buffer_ms' => (int) Arr::get($payload, 'buffering.total_ms', 0),
            'error_count' => (int) Arr::get($payload, 'result.error_count', 0),
            'completion_percent' => (float) Arr::get($payload, 'result.completion_percent', 0),
            'completed' => (bool) Arr::get($payload, 'result.completed', false),
            'end_reason' => $payload['end_reason'] ?? null,
            'platform' => $this->platform($request, Arr::get($payload, 'context.platform')),
            'app_version' => (string) Arr::get($payload, 'context.app_version'),
            'sdk_version' => substr((string) $request->header('X-Analytics-SDK-Version', ''), 0, 32) ?: null,
            'metrics_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];

        $query = DB::connection('analytics')->table('playback_sessions');
        $updated = $query->where('session_id', $sessionId)
            ->where('revision', '<', $revision)
            ->update(Arr::except($row, ['session_id']));

        if ($updated === 1) {
            return ['stored' => true, 'revision' => $revision];
        }

        $inserted = $query->insertOrIgnore($row + ['created_at' => $now]);

        return ['stored' => $inserted === 1, 'revision' => $revision];
    }

    private function identityError(Request $request): ?JsonResponse
    {
        $device = trim((string) $request->input('auth_device_id', ''));
        $header = trim((string) $request->header('Device-Token', ''));
        if ($device === '' || $header === '') {
            return V4Response::error(
                'ANALYTICS_DEVICE_REQUIRED',
                'Device-Token is required for analytics.',
                422
            );
        }
        if (! hash_equals($device, $header)) {
            return V4Response::error(
                'ANALYTICS_DEVICE_MISMATCH',
                'This access token belongs to another device.',
                403
            );
        }

        return null;
    }

    private function analyticsEnabled(): bool
    {
        try {
            $url = (string) config('firebase.database_url', '');
            if ($url === '') {
                return false;
            }

            $database = (new Factory)
                ->withServiceAccount((string) config('firebase.credentials'))
                ->withDatabaseUri($url)
                ->createDatabase();

            return $database->getReference('config/analytics/enabled')->getValue() === true;
        } catch (\Throwable $error) {
            report($error);

            return false;
        }
    }

    private function rejectUnknownPlaybackKeys(array $payload, string $path = ''): void
    {
        $allowed = [
            '' => ['schema_version', 'revision', 'state', 'started_at', 'ended_at', 'end_reason', 'content', 'timing', 'interaction', 'buffering', 'quality', 'tracks', 'result', 'context'],
            'content' => ['id', 'type', 'series_id', 'season_id', 'episode_id', 'is_downloaded', 'autoplay'],
            'timing' => ['duration_ms', 'watch_position_ms', 'max_position_ms', 'watched_ms', 'unique_watched_ms', 'replayed_ms', 'foreground_watch_ms', 'background_play_ms', 'startup_ms'],
            'interaction' => ['play_count', 'pause_count', 'resume_count', 'seek_count', 'seek_forward_ms', 'seek_backward_ms', 'fullscreen_count', 'pip_count', 'cast_count'],
            'buffering' => ['count', 'total_ms', 'longest_ms'],
            'quality' => ['initial', 'final', 'change_count', 'average_bitrate_kbps', 'dropped_frames', 'rendered_frames', 'video_codec', 'audio_codec', 'stream_format'],
            'tracks' => ['audio_language', 'subtitle_enabled', 'subtitle_language', 'playback_speed'],
            'result' => ['completed', 'completion_percent', 'milestones', 'error_count'],
            'context' => ['platform', 'network_type', 'app_version', 'build_number', 'os_version', 'device_model', 'device_category', 'locale', 'timezone'],
        ];
        foreach ($allowed as $object => $keys) {
            $value = $object === '' ? $payload : Arr::get($payload, $object);
            if (! is_array($value)) {
                continue;
            }
            $unknown = array_diff(array_keys($value), $keys);
            if ($unknown !== []) {
                $field = ($path !== '' ? $path.'.' : '').($object !== '' ? $object.'.' : '').array_values($unknown)[0];
                throw ValidationException::withMessages([
                    $field => ['The field is not supported by analytics schema v1.'],
                ]);
            }
        }
    }

    private function collectionDisabledError(): ?JsonResponse
    {
        if ($this->analyticsEnabled()) {
            return null;
        }

        return V4Response::error(
            'ANALYTICS_COLLECTION_DISABLED',
            'Analytics collection is disabled.',
            403
        );
    }

    private function payloadSizeError(Request $request, int $limit): ?JsonResponse
    {
        if (strlen($request->getContent()) <= $limit) {
            return null;
        }

        return V4Response::error(
            'ANALYTICS_PAYLOAD_TOO_LARGE',
            'The analytics payload is too large.',
            413,
            ['max_bytes' => $limit]
        );
    }

    private function platform(Request $request, mixed $fallback): string
    {
        $platform = strtolower(trim((string) $request->header('X-Platform', $fallback ?? '')));
        if ($platform === 'android-tv') {
            $platform = 'tv';
        }

        return in_array($platform, self::PLATFORMS, true) ? $platform : 'android';
    }

    private function sanitizeProperties(array $properties): array
    {
        $blocked = ['query', 'search_query', 'email', 'phone', 'password', 'token', 'authorization'];
        $sanitized = [];

        foreach (array_slice($properties, 0, 50, true) as $key => $value) {
            $key = substr((string) $key, 0, 64);
            if ($key === '' || in_array(strtolower($key), $blocked, true)) {
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = mb_substr($value, 0, 255);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = array_map(
                    fn ($item) => is_string($item) ? mb_substr($item, 0, 255) : $item,
                    array_values(array_filter(
                        array_slice($value, 0, 20),
                        fn ($item) => is_scalar($item) || $item === null
                    ))
                );
            }
        }

        return $sanitized;
    }

    private function sanitizeMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $message = preg_replace('/https?:\/\/\S+/i', '[url]', $message) ?? $message;
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
