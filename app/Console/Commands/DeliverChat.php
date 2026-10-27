<?php

namespace App\Console\Commands;

use App\Support\Analytics\AnalyticsCollectionSetting;
use App\Support\Chat\ChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DeliverChat extends Command
{
    protected $signature = 'chat:deliver {--once}';

    protected $description = 'Deliver durable chat change notifications and server analytics';

    public function handle(): int
    {
        do {
            $rows = DB::table('chat_outbox')->whereNull('delivered_at')->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->orderBy('id')->limit(100)->get();
            foreach ($rows as $row) {
                try {
                    if ($row->kind !== 'reported') {
                        $this->publish($row);
                    }
                    DB::table('chat_outbox')->where('id', $row->id)->update(['delivered_at' => now()]);
                } catch (\Throwable $e) {
                    // Payloads and credentials must never enter worker logs.
                    DB::table('chat_outbox')->where('id', $row->id)->update([
                        'attempts' => min(1000, $row->attempts + 1),
                        'available_at' => now()->addSeconds(min(60, 2 ** min(6, $row->attempts + 1)) + random_int(0, 2)),
                    ]);
                    $this->warn('Chat delivery pending: '.$row->id);

                    continue;
                }
            }
            // Analytics outages cannot hold up realtime delivery for other rooms.
            $telemetry = DB::table('chat_outbox')->whereNotNull('analytics')->whereNull('analytics_delivered_at')->where(fn ($q) => $q->whereNull('analytics_available_at')->orWhere('analytics_available_at', '<=', now()))->orderBy('id')->limit(100)->get();
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
        $c = config('broadcasting.connections.reverb');
        if (empty($c['app_id']) || empty($c['key']) || empty($c['secret'])) {
            throw new \RuntimeException('Missing Reverb settings');
        }
        $event = ['kind' => $row->kind, 'message_id' => $row->message_id ? (string) $row->message_id : null];
        $watch = app(\App\Support\Chat\PrivateWatchService::class)->snapshot((int) $row->room_id);
        if ($watch !== null) { $event['watch'] = $watch + ['event_id' => (string) $row->id]; }
        $body = json_encode(['name' => 'chat.changed', 'channels' => ['private-chat.'.$row->room_id],
            'data' => json_encode($event)], JSON_THROW_ON_ERROR);
        $path = '/apps/'.rawurlencode($c['app_id']).'/events';
        $query = ['auth_key' => $c['key'], 'auth_timestamp' => (string) time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
        ksort($query);
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n".$path."\n".$encoded, $c['secret']);
        $host = $c['options']['scheme'].'://'.$c['options']['host'].':'.$c['options']['port'];
        Http::connectTimeout(2)->timeout(5)->withBody($body, 'application/json')
            ->post($host.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->throw();
        app(ChatService::class)->invalidate($row->room_id);
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
