<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Support\Api\V4Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $uid = $this->userId($request);

        $polls = Poll::available()
            ->withCount('votes')
            ->with(['options' => fn ($query) => $query->withCount('votes')])
            ->orderByDesc('id')
            ->paginate($perPage);

        $votes = PollVote::query()
            ->where('uid', $uid)
            ->whereIn('poll_id', $polls->getCollection()->pluck('id'))
            ->get()
            ->keyBy('poll_id');
        $polls->getCollection()->each(function (Poll $poll) use ($votes): void {
            $poll->setAttribute('user_vote', $votes->get($poll->id));
        });

        return V4Response::success([
            'items' => $polls->items(),
            'pagination' => [
                'current_page' => $polls->currentPage(),
                'per_page' => $polls->perPage(),
                'total' => $polls->total(),
                'last_page' => $polls->lastPage(),
                'has_more' => $polls->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, Poll $poll): JsonResponse
    {
        if (! $this->isAvailable($poll)) {
            return V4Response::error('POLL_UNAVAILABLE', 'Poll is not available.', 404);
        }

        $poll->loadCount('votes')->load([
            'options' => fn ($query) => $query->withCount('votes'),
        ]);
        $poll->setAttribute('user_vote', PollVote::query()
            ->where('poll_id', $poll->id)
            ->where('uid', $this->userId($request))
            ->first());

        return V4Response::success($poll);
    }

    public function vote(Request $request, Poll $poll): JsonResponse
    {
        $validated = $request->validate([
            'poll_option_id' => ['required', 'integer'],
        ]);
        if (! $this->isAvailable($poll)) {
            return V4Response::error('POLL_UNAVAILABLE', 'Poll is not available for voting.', 409);
        }

        $option = PollOption::query()
            ->where('poll_id', $poll->id)
            ->find($validated['poll_option_id']);
        if (! $option) {
            return V4Response::error(
                'INVALID_POLL_OPTION',
                'Selected option does not belong to this poll.',
                422
            );
        }

        try {
            $vote = DB::transaction(fn () => PollVote::updateOrCreate(
                ['poll_id' => $poll->id, 'uid' => $this->userId($request)],
                ['poll_option_id' => $option->id]
            ));
        } catch (QueryException) {
            return V4Response::error('VOTE_SAVE_FAILED', 'Vote could not be saved.', 409);
        }

        return V4Response::success(
            $vote->fresh(['option']),
            $vote->wasRecentlyCreated ? 'Vote submitted successfully.' : 'Vote updated successfully.'
        );
    }

    public function removeVote(Request $request, Poll $poll): JsonResponse
    {
        $deleted = PollVote::query()
            ->where('poll_id', $poll->id)
            ->where('uid', $this->userId($request))
            ->delete();

        if ($deleted === 0) {
            return V4Response::error('VOTE_NOT_FOUND', 'Vote not found.', 404);
        }

        return V4Response::success(null, 'Vote removed successfully.');
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }

    private function isAvailable(Poll $poll): bool
    {
        return Poll::available()->whereKey($poll->getKey())->exists();
    }
}
