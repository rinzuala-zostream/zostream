<?php

namespace App\Console\Commands;

use App\Support\Analytics\AnalyticsCollectionSetting;
use App\Support\Chat\ChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DeliverChat extends Command
{
    protected $signature = 'chat:deliver {--once} {--lane=all : all, realtime, push, or analytics}';

    protected $description = 'Deliver durable chat change notifications and server analytics';

    public function handle(): int
    {
        $lane = (string) $this->option('lane');
        if (! in_array($lane, ['all', 'realtime', 'push', 'analytics'], true)) { return self::INVALID; }
        $lastWatchExpiry = 0;
        do {
            // Unclean player exits have no stop request. Expire their rooms after
            // the stream heartbeat deadline, even when no chat events are queued.
            if (in_array($lane, ['all', 'realtime'], true) && config('chat.enabled') && time() - $lastWatchExpiry >= 15) {
                app(\App\Support\Chat\InviteRoomLifecycle::class)->endInactiveWatchRooms(\Illuminate\Http\Request::create('/'));
                app(\App\Support\Chat\PrivateWatchService::class)->endInactiveSessions(\Illuminate\Http\Request::create('/'));
                app(\App\Support\Chat\RoomInvitationService::class)->expireSharedRooms(\Illuminate\Http\Request::create('/'));
                $lastWatchExpiry = time();
            }
            $pending = DB::table('chat_outbox')->whereNull('delivered_at')
                ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
            if ($lane === 'push') { $pending->where('kind', 'message_push'); }
            if ($lane === 'realtime') { $pending->where('kind', '!=', 'message_push'); }
            $rows = $lane === 'analytics' ? collect() : $pending->orderBy('id')->limit(100)->get();
            foreach ($rows as $row) {
                $claim = (string) \Illuminate\Support\Str::uuid();
                $owned = DB::table('chat_outbox')->where('id', $row->id)->whereNull('delivered_at')
                    ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))
                    ->update(['claim_token' => $claim, 'claimed_until' => now()->addMinutes(2)]);
                if (! $owned) { continue; }
                $renew = function () use ($row, $claim): void {
                    if (! DB::table('chat_outbox')->where('id', $row->id)->where('claim_token', $claim)
                        // MySQL reports zero changed rows when this runs in the
                        // same second as the initial claim and the timestamp is
                        // unchanged. Advance past second precision so ownership
                        // verification cannot fail spuriously on fast delivery.
                        ->update(['claimed_until' => now()->addMinutes(2)->addSeconds(5)])) { throw new \RuntimeException('Chat delivery claim lost'); }
                };
                try {
                    if ($row->kind === 'message_push') {
                        app(\App\Support\Chat\ChatMessageNotifications::class)->deliver($row);
                    } elseif (in_array($row->kind, ['feed_created', 'feed_deleted'], true)) {
                        app(\App\Support\Chat\ChatLiveDelivery::class)->deliver($row, $renew);
                    } elseif ($row->kind !== 'reported') {
                        $this->publish($row);
                    }
                    DB::table('chat_outbox')->where('id', $row->id)->where('claim_token', $claim)
                        ->update(['delivered_at' => now(), 'claim_token' => null, 'claimed_until' => null]);
                } catch (\Throwable $e) {
                    DB::table('chat_outbox')->where('id', $row->id)->where('claim_token', $claim)->update([
                        'claim_token' => null, 'claimed_until' => null,
                        'attempts' => min(1000, $row->attempts + 1),
                        'available_at' => now()->addSeconds(min(60, 2 ** min(6, $row->attempts + 1)) + random_int(0, 2)),
                    ]);
                    // Keep payloads, auth signatures, query bindings and message
                    // content out of logs; the exception class/status is enough
                    // to distinguish Redis/DB failures from rejected Reverb posts.
                    $reason = class_basename($e);
                    if ($e instanceof \Illuminate\Http\Client\RequestException) {
                        $reason .= ' HTTP '.$e->response->status();
                    } elseif ($e instanceof \Illuminate\Database\QueryException) {
                        $reason .= ' SQLSTATE '.($e->errorInfo[0] ?? $e->getCode());
                    } elseif ($e instanceof \RuntimeException && in_array($e->getMessage(), [
                        'Chat delivery claim lost', 'Chat is disabled', 'Missing Reverb settings',
                    ], true)) {
                        $reason .= ' '.$e->getMessage();
                    }
                    $this->warn('Chat delivery pending: '.$row->id.' ('.$reason.')');
                }
            }
            // Analytics outages cannot hold up realtime delivery for other rooms.
            $telemetry = DB::table('chat_outbox')->whereNotNull('analytics')->whereNull('analytics_delivered_at')->where(fn ($q) => $q->whereNull('analytics_available_at')->orWhere('analytics_available_at', '<=', now()))->orderBy('id')->limit(in_array($lane, ['all', 'analytics'], true) ? 100 : 0)->get();
            foreach ($telemetry as $row) {
                try {
                    $this->analytics($row);
                    DB::table('chat_outbox')->where('id', $row->id)->update(['analytics_delivered_at' => now()]);
                } catch (\Throwable $e) {
                    DB::table('chat_outbox')->where('id', $row->id)->update([
                        'analytics_attempts' => min(1000, $row->analytics_attempts + 1),
                        'analytics_available_at' => now()->addSeconds(min(60, 2 ** min(6, $row->analytics_attempts + 1)) + random_int(0, 2)),
                    ]);
                    $this->warn('Chat analytics pending: '.$row->id);
                }
            }
            if (! $this->option('once')) {
                usleep(500000);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function publish(object $row): void
    {
        if (! config('chat.enabled')) {
            throw new \RuntimeException('Chat is disabled');
        }
        $c = config('broadcasting.connections.reverb');
        if (empty($c['app_id']) || empty($c['key']) || empty($c['secret'])) {
            throw new \RuntimeException('Missing Reverb settings');
        }
        $recipient = $row->recipient_user_id ?? null;
        $event = ['kind' => $row->kind, 'message_id' => $row->message_id ? (string) $row->message_id : null];
        if ($recipient !== null) {
            $badge = $row->kind === 'read'
                ? app(\App\Support\Chat\ChatReadService::class)->readState($recipient, (int) $row->room_id)
                : ['kind' => 'sync', 'room_id' => (string) $row->room_id];
            $event['badge'] = $badge + ['event_id' => (string) $row->id];
            $channel = \App\Support\Chat\ChatReadService::userChannel($recipient);
        } else {
            $channel = 'private-chat.'.$row->room_id;
            $watch = app(\App\Support\Chat\PrivateWatchService::class)->snapshot((int) $row->room_id);
            if ($watch !== null) { $event['watch'] = $watch + ['event_id' => (string) $row->id]; }
            if (in_array($row->kind, ['created', 'deleted', 'ended'], true)) {
                $message = $row->message_id ? DB::table('chat_messages')->find($row->message_id) : null;
                $event['badge'] = ['kind' => $message && str_starts_with($message->user_id, 'deleted:') ? 'sync' : $row->kind, 'room_id' => (string) $row->room_id,
                    'event_id' => (string) $row->id, 'message_id' => $row->message_id ? (string) $row->message_id : null,
                    'sender_id' => $message?->user_id, 'retained' => $message && \Illuminate\Support\Carbon::parse($message->created_at)->gte(now()->subDays(config('chat.retention_days'))), 'eligible' => $message && ! $message->deleted_at
                        && \Illuminate\Support\Carbon::parse($message->created_at)->gte(now()->subDays(config('chat.retention_days')))];
            }
        }
        if ($row->kind === 'room_invite_status' && $row->message_id) {
            $target = DB::table('chat_messages')->where('id', $row->message_id)->value('invite_room_id');
            if ($target) { $event['room_invite'] = app(\App\Support\Chat\RoomInvitationService::class)->metadata((int) $target); }
        }
        $channels = [$channel];
        if ($recipient === null && isset($event['badge'])) {
            $room = DB::table('chat_rooms')->find($row->room_id);
            if ($room && in_array($room->type, ['global', 'movie', 'episode'], true)) {
                $channels[] = \App\Support\Chat\ChatReadService::contextChannel($room->type, $room->content_id);
            }
        }
        $body = json_encode(['name' => $recipient !== null ? 'chat.badge' : 'chat.changed', 'channels' => \App\Support\Chat\ChatChannels::publishing($channels),
            'data' => json_encode($event)], JSON_THROW_ON_ERROR);
        $path = '/apps/'.rawurlencode($c['app_id']).'/events';
        $query = ['auth_key' => $c['key'], 'auth_timestamp' => (string) time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
        ksort($query);
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n".$path."\n".$encoded, $c['secret']);
        $host = $c['options']['scheme'].'://'.$c['options']['host'].':'.$c['options']['port'];
        // Receivers can fetch history as soon as Reverb accepts the event,
        // before this publish request returns to the worker.
        app(ChatService::class)->invalidate($row->room_id);
        Http::connectTimeout(2)->timeout(5)->withBody($body, 'application/json')
            ->post($host.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->throw();
    }

    private function analytics(object $row): void
    {
        if (! app(AnalyticsCollectionSetting::class)->enabled()) {
            return;
        }
        $a = json_decode($row->analytics, true, flags: JSON_THROW_ON_ERROR);
        $room = DB::table('chat_rooms')->find($row->room_id);
        DB::connection('analytics')->table('analytics_events')->insertOrIgnore([
            'event_id' => $a['event_id'], 'name' => $a['name'], 'user_id' => $a['user_id'],
            'device_id' => $a['device_id'], 'app_session_id' => $a['app_session_id'],
            'platform' => $a['platform'], 'app_version' => $a['app_version'],
            'occurred_at' => $row->created_at,
            'properties_json' => json_encode(['room_id' => $a['room_id'], 'room_type' => $room?->type, 'content_id' => $room?->content_id, 'playback_session_id' => $a['playback_session_id'] ?? null]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
