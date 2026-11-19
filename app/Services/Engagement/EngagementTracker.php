<?php

namespace App\Services\Engagement;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EngagementTracker
{
    public function eraseUser(string $userId): void
    {
        // Account erasure also works while delivery/tracking switches are disabled.
        try {
            if (! Schema::hasTable('engagement_users')) {
                return;
            }
            DB::transaction(function () use ($userId) {
                foreach (['engagement_deliveries', 'engagement_content', 'engagement_users'] as $table) {
                    DB::table($table)->where('user_id', $userId)->delete();
                }
            });
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** Only pass newly accepted, sanitized events from the authenticated analytics API. */
    public function events(string $userId, array $events): void
    {
        $this->safely(function () use ($userId, $events) {
            $latest = null;
            $interest = null;
            $hasAppActivity = false;
            foreach ($events as $event) {
                $at = $this->observedAt($event['occurred_at']);
                if (! $at) {
                    continue;
                }
                $hasAppActivity = $hasAppActivity || in_array($event['name'], ['app_opened', 'app_foregrounded'], true);
                if (in_array($event['name'], ['app_opened', 'app_foregrounded', 'screen_viewed', 'content_opened', 'search_performed', 'search_result_selected', 'wishlist_added', 'notification_opened'], true)) {
                    $latest = ! $latest || $at->gt($latest) ? $at : $latest;
                }
                $properties = $event['properties'] ?? [];
                // Never store raw search text. A selected catalogue item is the intent signal.
                if ($event['name'] === 'search_result_selected' && ! empty($properties['content_id']) && ($properties['content_type'] ?? 'movie') === 'movie') {
                    if (! $interest || $at->gt($interest['at'])) {
                        $interest = ['id' => mb_substr((string) $properties['content_id'], 0, 191), 'at' => $at];
                    }
                }
                if ($event['name'] === 'notification_opened' && ctype_digit((string) ($properties['notification_id'] ?? ''))) {
                    $changed = DB::table('engagement_deliveries')
                        ->where('id', $properties['notification_id'])->where('user_id', $userId)
                        ->where('status', 'sent')->whereNull('opened_at')
                        ->where('sent_at', '<=', $at)->update(['opened_at' => $at]);
                    if ($changed) {
                        $this->user($userId);
                        DB::table('engagement_users')->where('user_id', $userId)->update(['unopened_count' => 0, 'last_opened_at' => $at]);
                    }
                }
            }
            if (! $latest) {
                return;
            }
            DB::transaction(function () use ($userId, $latest, $interest, $hasAppActivity) {
                $this->user($userId);
                $state = DB::table('engagement_users')->where('user_id', $userId)->lockForUpdate()->first();
                $values = ['updated_at' => now('UTC')];
                if ($hasAppActivity) {
                    $values['has_app_activity'] = true;
                }
                if (! $state->last_active_at || $latest->gt(CarbonImmutable::parse($state->last_active_at, 'UTC'))) {
                    $values['last_active_at'] = $latest;
                }
                if ($interest && (! $state->interest_at || $interest['at']->gt(CarbonImmutable::parse($state->interest_at, 'UTC')))) {
                    $values['interest_content_id'] = $interest['id'];
                    $values['interest_at'] = $interest['at'];
                }
                DB::table('engagement_users')->where('user_id', $userId)->update($values);
            });
        });
    }

    public function playback(string $userId, string $sessionId, array $payload): void
    {
        $this->safely(function () use ($userId, $sessionId, $payload) {
            $at = $this->observedAt($payload['ended_at'] ?? $payload['started_at']);
            if (! $at) {
                return;
            }
            $type = $payload['content']['type'];
            $id = (string) $payload['content']['id'];
            DB::transaction(function () use ($userId, $sessionId, $payload, $at, $type, $id) {
                $this->user($userId);
                $state = DB::table('engagement_users')->where('user_id', $userId)->lockForUpdate()->first();
                $values = ['updated_at' => now('UTC')];
                foreach (['last_active_at', 'last_watch_at'] as $column) {
                    if ($column === 'last_watch_at' && (int) $payload['timing']['watched_ms'] <= 0) {
                        continue;
                    }
                    if (! $state->$column || $at->gt(CarbonImmutable::parse($state->$column, 'UTC'))) {
                        $values[$column] = $at;
                    }
                }
                if (in_array($type, ['movie', 'episode'], true) && (int) $payload['timing']['watched_ms'] > 0) {
                    $identity = ['user_id' => $userId, 'content_type' => $type, 'content_id' => $id];
                    $previous = DB::table('engagement_content')->where($identity)->first();
                    if (! $previous || $at->gte(CarbonImmutable::parse($previous->last_watch_at, 'UTC'))) {
                        $duration = (int) $payload['timing']['duration_ms'];
                        $position = (int) $payload['timing']['watch_position_ms'];
                        $completed = ($payload['result']['completed'] ?? false) || ($duration > 0 && $position / $duration >= .9);
                        DB::table('engagement_content')->updateOrInsert($identity, [
                            'position_ms' => $position, 'duration_ms' => $duration,
                            'last_watch_at' => $at, 'completed_at' => $previous?->completed_at ?? ($completed ? $at : null),
                            'last_session_id' => $sessionId,
                        ]);
                        if (! $previous || $previous->last_session_id !== $sessionId) {
                            $hours = json_decode($state->watch_hours ?? '{}', true) ?: [];
                            $hour = CarbonImmutable::parse($payload['started_at'], 'UTC')->setTimezone(config('engagement.timezone'))->hour;
                            // A bounded rolling histogram, updated only for a new viewing session.
                            if (array_sum($hours) >= 100) {
                                $hours = array_map(fn ($count) => (int) floor($count / 2), $hours);
                            }
                            $hours[$hour] = ($hours[$hour] ?? 0) + 1;
                            $values['watch_hours'] = json_encode($hours, JSON_THROW_ON_ERROR);
                        }
                    }
                    DB::table('engagement_deliveries')->where('user_id', $userId)->where('content_type', $type)->where('content_id', $id)
                        ->where('status', 'sent')->whereNull('played_at')->where('sent_at', '<=', $at)
                        ->where('sent_at', '>=', $at->subDays(7))->update(['played_at' => $at]);
                }
                DB::table('engagement_users')->where('user_id', $userId)->update($values);
            });
        });
    }

    private function user(string $userId): void
    {
        DB::table('engagement_users')->insertOrIgnore([
            'user_id' => $userId, 'next_check_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    private function observedAt(string $value): ?CarbonImmutable
    {
        $at = CarbonImmutable::parse($value, 'UTC')->utc();

        return $at->gt(CarbonImmutable::now('UTC')->addMinutes(5)) || $at->lt(CarbonImmutable::now('UTC')->subDays(90)) ? null : $at;
    }

    private function safely(callable $callback): void
    {
        if (! config('engagement.tracking_enabled')) {
            return;
        }
        try {
            $callback();
        } catch (Throwable $error) {
            // Optional engagement storage must never fail playback or analytics ingestion.
            report($error);
        }
    }
}
