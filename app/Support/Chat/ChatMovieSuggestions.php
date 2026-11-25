<?php

namespace App\Support\Chat;

use App\Models\MovieModel;
use App\Support\MizoOnlyContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class ChatMovieSuggestions
{
    public function assertRoom(object $room): void
    {
        $private = $room->type === 'invite' && ChatDatabase::connection()->table('chat_invite_rooms')
            ->where('room_id', $room->id)->whereNull('content_type')->exists();
        abort_unless($room->type === 'global' || $private, 422, 'Suggest movies in Global Chat or a private chat room.');
    }

    private function visible(Request $request): Builder
    {
        $kids = strtolower($request->header('X-Mode', '')) === 'kids' || $request->boolean('isChildMode');
        return MovieModel::query()->where('status', 'Published')->where('isEnable', 1)
            ->when($kids, fn ($q) => $q->where('isChildMode', 1))
            ->when($kids || ! $request->boolean('age_restriction'), fn ($q) => $q->where('isAgeRestricted', 0))
            ->when(MizoOnlyContent::appliesTo(MizoOnlyContent::userId($request)), fn ($q) => $q->where('isMizo', 1));
    }

    private function card(object $movie): array
    {
        $poster = trim((string) ($movie->opt_poster_url ?: $movie->poster));
        $id = $movie->id === null || $movie->id === '' ? $movie->num : $movie->id;
        return ['id' => (string) $id, 'title' => (string) $movie->title,
            'poster' => filter_var($poster, FILTER_VALIDATE_URL) && strtolower((string) parse_url($poster, PHP_URL_SCHEME)) === 'https' ? $poster : null];
    }

    public function search(Request $request, string $query): array
    {
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query));
        return $this->visible($request)->whereRaw("LOWER(REPLACE(title, ' ', '')) LIKE ? ESCAPE '!'", ['%'.$term.'%'])
            ->orderBy('title')->limit(8)->get()->map(fn ($movie) => $this->card($movie))->all();
    }

    public function resolve(Request $request, object $room, string $id): array
    {
        $this->assertRoom($room);
        $movie = $this->visible($request)->where('id', $id)->first();
        if (! $movie && ctype_digit($id)) {
            $movie = $this->visible($request)->where('num', $id)->first();
        }
        abort_unless($movie, 422, 'This movie is no longer available to suggest. Choose a published movie.');
        return $this->card($movie);
    }
}
