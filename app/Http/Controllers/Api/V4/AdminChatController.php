<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class AdminChatController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('chat_rooms'), 503, 'Chat storage has not been deployed yet.');
    }

    private function paging(Request $r): array
    {
        return $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|between:1,100']);
    }

    private function room(int $room): object
    {
        $this->ready();
        $value = DB::table('chat_rooms')->find($room);
        abort_unless($value, 404);

        return $value;
    }

    public function rooms(Request $r)
    {
        $this->ready();
        $p = $this->paging($r);
        $v = $r->validate(['q' => 'nullable|string|max:150', 'type' => ['nullable', Rule::in(['global', 'movie', 'episode'])], 'state' => ['nullable', Rule::in(['open', 'closed'])]]);
        $rooms = DB::table('chat_rooms as r')
            ->select('r.*')
            ->selectSub(DB::table('chat_messages as m')->selectRaw('COUNT(*)')->whereColumn('m.room_id', 'r.id'), 'message_count')
            ->selectSub(DB::table('chat_messages as m')->selectRaw('MAX(created_at)')->whereColumn('m.room_id', 'r.id'), 'last_message_at')
            ->selectSub(DB::table('chat_reports as p')->join('chat_messages as m', 'm.id', '=', 'p.message_id')->selectRaw('COUNT(*)')->whereColumn('m.room_id', 'r.id')->where('p.status', 'pending'), 'pending_reports')
            ->when(! empty($v['q']), fn ($q) => $q->where('r.room_key', 'like', '%'.$v['q'].'%'))
            ->when(! empty($v['type']), fn ($q) => $q->where('r.type', $v['type']))
            ->when(! empty($v['state']), fn ($q) => $q->where('r.closed', $v['state'] === 'closed'))
            ->orderByDesc('r.id')->paginate($p['per_page'] ?? 25);
        $this->titles($rooms->getCollection());

        return V4Response::success($rooms);
    }

    private function titles($rooms): void
    {
        foreach (['movie' => 'movie', 'episode' => 'episode'] as $type => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $ids = $rooms->where('type', $type)->pluck('content_id')->filter()->all();
            if (! $ids) {
                continue;
            }
            $names = DB::table($table)->whereIn('id', $ids)->pluck('title', 'id');
            foreach ($rooms as $room) {
                if ($room->type === $type) {
                    $room->title = $names[$room->content_id] ?? $room->room_key;
                }
            }
        }
        foreach ($rooms as $room) {
            $room->closed = (bool) $room->closed;
            $room->title ??= $room->type === 'global' ? 'Global Chat' : $room->room_key;
        }
    }

    public function messages(Request $r, int $room)
    {
        $info = $this->room($room);
        $this->titles(collect([$info]));
        $v = $r->validate(['before' => ['nullable', 'integer', 'min:1', Rule::prohibitedIf($r->filled('after'))], 'after' => 'nullable|integer|min:0', 'q' => 'nullable|string|max:200']);
        $after = $v['after'] ?? null;
        $rows = DB::table('chat_messages')->where('room_id', $room)
            ->when(isset($v['before']), fn ($q) => $q->where('id', '<', $v['before']))
            ->when($after !== null, fn ($q) => $q->where('id', '>', $after))
            ->when(! empty($v['q']), fn ($q) => $q->where(fn ($q) => $q->where('body', 'like', '%'.$v['q'].'%')->orWhere('user_id', $v['q'])->orWhere('display_name', 'like', '%'.$v['q'].'%')))
            ->orderBy('id', $after !== null ? 'asc' : 'desc')->limit(51)->get();
        $more = $rows->count() > 50;
        $rows = $rows->take(50);
        if ($after === null) {
            $rows = $rows->reverse();
        }

        return V4Response::success(['room' => $info, 'messages' => $rows->map(fn ($m) => app(\App\Support\Chat\ChatService::class)->message($m))->values(), 'has_more' => $more]);
    }

    public function reports(Request $r)
    {
        $this->ready();
        $p = $this->paging($r);
        $v = $r->validate(['status' => ['nullable', Rule::in(['pending', 'resolved', 'all'])], 'room_id' => 'nullable|integer|min:1']);
        $rows = DB::table('chat_reports as p')->leftJoin('chat_messages as m', 'm.id', '=', 'p.message_id')->leftJoin('chat_rooms as r', 'r.id', '=', 'm.room_id')
            ->when(($v['status'] ?? 'pending') !== 'all', fn ($q) => $q->where('p.status', $v['status'] ?? 'pending'))
            ->when(isset($v['room_id']), fn ($q) => $q->where('m.room_id', $v['room_id']))
            ->select('p.*', 'm.room_id', 'm.user_id as sender_id', 'm.display_name', 'm.body', 'm.deleted_at', 'r.room_key')
            ->orderByDesc('p.id')->paginate($p['per_page'] ?? 25);

        return V4Response::success($rows);
    }

    public function restrictions(Request $r)
    {
        $this->ready();
        $p = $this->paging($r);
        $v = $r->validate(['room_id' => 'nullable|integer|min:0', 'user_id' => 'nullable|string|max:191']);
        $rows = DB::table('chat_bans as b')->leftJoin('user as u', 'u.uid', '=', 'b.user_id')->leftJoin('chat_rooms as r', 'r.id', '=', 'b.room_id')
            ->where(fn ($q) => $q->whereNull('b.expires_at')->orWhere('b.expires_at', '>', now()))
            ->when(isset($v['room_id']), fn ($q) => $q->where('b.room_id', $v['room_id']))
            ->when(! empty($v['user_id']), fn ($q) => $q->where('b.user_id', $v['user_id']))
            ->select('b.*', 'u.name as display_name', 'r.room_key')->orderByDesc('b.id')->paginate($p['per_page'] ?? 25);

        return V4Response::success($rows);
    }

    public function audit(Request $r)
    {
        $this->ready();
        $p = $this->paging($r);
        $v = $r->validate(['room_id' => 'nullable|integer|min:0']);

        return V4Response::success(DB::table('chat_moderation_actions')->when(isset($v['room_id']), fn ($q) => $q->where('room_id', $v['room_id']))->orderByDesc('id')->paginate($p['per_page'] ?? 25));
    }

    public function metrics(Request $r)
    {
        $this->ready();
        $v = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'timezone' => 'nullable|timezone',
            'platform' => ['nullable', Rule::in(['ios', 'android', 'tvos', 'tv', 'web', 'tizen', 'webos'])], 'app_version' => 'nullable|string|max:64',
            'room_type' => ['nullable', Rule::in(['global', 'movie', 'episode'])], 'content_type' => 'nullable|string|max:24',
            'content_id' => 'nullable|string|max:191', 'user_id' => 'nullable|string|max:191']);
        $tz = $v['timezone'] ?? 'UTC';
        $from = CarbonImmutable::parse($v['from'] ?? now($tz)->subDays(6)->toDateString(), $tz)->startOfDay()->utc();
        $to = CarbonImmutable::parse($v['to'] ?? now($tz)->toDateString(), $tz)->endOfDay()->utc();
        abort_if($from->gt($to) || $from->diffInDays($to) > 93, 422, 'Choose a date range of at most 93 days.');
        $operational = [
            'chat_enabled' => (bool) config('chat.enabled'),
            'rooms' => DB::table('chat_rooms')->count(),
            'open_rooms' => DB::table('chat_rooms')->where('closed', false)->count(),
            'pending_reports' => DB::table('chat_reports')->where('status', 'pending')->count(),
            'active_restrictions' => DB::table('chat_bans')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'pending_broadcasts' => DB::table('chat_outbox')->whereNull('delivered_at')->count(),
            'pending_analytics' => DB::table('chat_outbox')->whereNotNull('analytics')->whereNull('analytics_delivered_at')->count(),
            'oldest_pending_broadcast' => DB::table('chat_outbox')->whereNull('delivered_at')->min('created_at'),
        ];
        $response = ['operational' => $operational, 'analytics_available' => true, 'summary' => null, 'events' => [], 'trend' => [], 'platforms' => [], 'rooms' => [],
            'linked_playback' => null,
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => $tz, 'trend_timezone' => 'UTC']];
        try {
            $db = DB::connection('analytics');
            $q = $db->table('analytics_events')->whereIn('name', ['chat_opened', 'chat_closed', 'chat_message_sent', 'chat_message_reported', 'chat_connection_failed', 'chat_message_failed'])
                ->whereBetween('occurred_at', [$from, $to])
                ->when(! empty($v['platform']), fn ($q) => $q->where('platform', $v['platform']))
                ->when(! empty($v['app_version']), fn ($q) => $q->where('app_version', $v['app_version']))
                ->when(! empty($v['room_type']), fn ($q) => $q->where('properties_json->room_type', $v['room_type']))
                ->when(! empty($v['content_type']), fn ($q) => $q->where('properties_json->room_type', $v['content_type']))
                ->when(! empty($v['content_id']), fn ($q) => $q->where('properties_json->content_id', $v['content_id']))
                ->when(! empty($v['user_id']), fn ($q) => $q->where('user_id', $v['user_id']));
            $response['summary'] = (clone $q)->selectRaw("COUNT(*) as events, COUNT(DISTINCT CASE WHEN name='chat_opened' THEN user_id END) as unique_visitors, COUNT(DISTINCT CASE WHEN name='chat_message_sent' THEN user_id END) as unique_chatters, SUM(CASE WHEN name='chat_message_sent' THEN 1 ELSE 0 END) as messages, SUM(CASE WHEN name='chat_opened' THEN 1 ELSE 0 END) as opens, SUM(CASE WHEN name='chat_message_reported' THEN 1 ELSE 0 END) as reports, SUM(CASE WHEN name='chat_message_failed' THEN 1 ELSE 0 END) as message_failures, SUM(CASE WHEN name='chat_connection_failed' THEN 1 ELSE 0 END) as connection_failures")->first();
            $response['events'] = (clone $q)->select('name')->selectRaw('COUNT(*) as count, COUNT(DISTINCT user_id) as users')->groupBy('name')->orderByDesc('count')->get();
            $response['trend'] = (clone $q)->selectRaw("DATE(occurred_at) as date, SUM(CASE WHEN name='chat_message_sent' THEN 1 ELSE 0 END) as messages, SUM(CASE WHEN name='chat_opened' THEN 1 ELSE 0 END) as opens")->groupByRaw('DATE(occurred_at)')->orderBy('date')->get();
            $response['platforms'] = (clone $q)->select('platform')->selectRaw("COUNT(*) as events, SUM(CASE WHEN name='chat_message_sent' THEN 1 ELSE 0 END) as messages, COUNT(DISTINCT user_id) as users")->groupBy('platform')->orderByDesc('events')->get();
            try {
                $sessionExpr = $db->getQueryGrammar()->wrap('properties_json->playback_session_id');
                $linked = (clone $q)->whereIn('name', ['chat_opened', 'chat_message_sent'])->select('user_id')->selectRaw($sessionExpr.' as playback_session_id')->distinct();
                $response['linked_playback'] = $db->table('playback_sessions as p')->joinSub($linked, 'c', fn ($join) => $join->on('p.session_id', '=', 'c.playback_session_id')->on('p.user_id', '=', 'c.user_id'))
                    ->selectRaw('COUNT(*) as sessions, COALESCE(SUM(p.watched_ms),0)/3600000.0 as watch_hours, AVG(CASE WHEN p.completed THEN 100.0 ELSE 0.0 END) as completion_rate')->first();
            } catch (\Throwable $e) {
                $response['linked_playback'] = null;
            }
            $roomExpr = $db->getQueryGrammar()->wrap('properties_json->room_id');
            $response['rooms'] = (clone $q)->selectRaw($roomExpr.' as room_id')->selectRaw("COUNT(*) as events, SUM(CASE WHEN name='chat_message_sent' THEN 1 ELSE 0 END) as messages, COUNT(DISTINCT user_id) as users")->groupByRaw($roomExpr)->orderByDesc('messages')->limit(25)->get();
            $roomInfo = DB::table('chat_rooms')->whereIn('id', collect($response['rooms'])->pluck('room_id')->filter()->all())->get()->keyBy('id');
            $this->titles($roomInfo);
            foreach ($response['rooms'] as $row) {
                $row->title = $roomInfo[$row->room_id]->title ?? 'Unknown room';
            }
        } catch (\Throwable $e) {
            $response['analytics_available'] = false;
            $response['summary'] = null;
            $response['events'] = $response['trend'] = $response['platforms'] = $response['rooms'] = [];
        }

        return V4Response::success($response);
    }
}
