<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use App\Support\Chat\{ChatDatabase, ChatInteractions, ChatService};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatInteractionController extends Controller
{
    public function show(Request $r, int $room, int $message, ChatInteractions $interactions, ChatService $chat)
    {
        $m = $interactions->target($r, $room, $message);
        return V4Response::success($interactions->decorate([$chat->message($m)], (string) $r->input('auth_user_id'))[0]);
    }
    public function reaction(Request $r, int $room, int $message, ChatInteractions $interactions, ChatService $chat)
    {
        $chat->room($r, $room, true);
        $v = $r->validate(['emoji' => ['required', Rule::in(ChatInteractions::EMOJI)], 'active' => 'required|boolean']);
        $m = $interactions->target($r, $room, $message);
        abort_if($m->deleted_at, 410, 'This message was deleted.');
        ChatDatabase::connection()->transaction(function () use ($r, $room, $message, $v, $chat): void {
            $target = ChatDatabase::connection()->table('chat_messages')->where('id', $message)->lockForUpdate()->first();
            abort_if(! $target || $target->deleted_at, 410, 'This message was deleted.');
            $key = ['message_id' => $message, 'user_id' => (string) $r->input('auth_user_id'), 'emoji' => $v['emoji']];
            $changed = $v['active'] ? ChatDatabase::connection()->table('chat_reactions')->insertOrIgnore($key)
                : ChatDatabase::connection()->table('chat_reactions')->where($key)->delete();
            if ($changed) { $chat->outbox($r, $room, 'reactions', $message, null); }
        });
        return V4Response::success($interactions->decorate([$chat->message($m)], (string) $r->input('auth_user_id'))[0]);
    }
    public function typing(Request $r, int $room, ChatInteractions $interactions)
    {
        $v = $r->validate(['active' => 'required|boolean']);
        return V4Response::success(['users' => $interactions->typing($r, $room, $v['active'])]);
    }
    public function typists(Request $r, int $room, ChatInteractions $interactions)
    {
        return V4Response::success(['users' => $interactions->typing($r, $room)]);
    }
}
