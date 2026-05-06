<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PollController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = (int) $request->get('limit', 20);
            $uid = $request->get('uid');

            $query = Poll::withCount('votes')
                ->with(['options' => function ($query) {
                    $query->withCount('votes');
                }])
                ->orderBy('id', 'desc');

            if ($request->filled('status')) {
                $query->where('status', $request->get('status'));
            }

            if ($request->boolean('available')) {
                $query->available();
            }

            $polls = $query->paginate($limit);

            if ($uid) {
                $polls->getCollection()->transform(function (Poll $poll) use ($uid) {
                    return $this->appendUserVote($poll, $uid);
                });
            }

            return response()->json([
                'status' => true,
                'data' => $polls,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch polls', $e);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $poll = Poll::withCount('votes')
                ->with(['options' => function ($query) {
                    $query->withCount('votes');
                }])
                ->find($id);

            if (!$poll) {
                return $this->notFoundResponse('Poll not found');
            }

            if ($request->filled('uid')) {
                $poll = $this->appendUserVote($poll, $request->get('uid'));
            }

            return response()->json([
                'status' => true,
                'data' => $poll,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch poll', $e);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->merge([
                'options' => $this->normalizeOptions($request->input('options', [])),
            ]);

            $validator = Validator::make($request->all(), [
                'question' => 'required|string|max:255',
                'description' => 'nullable|string',
                'status' => ['nullable', Rule::in([Poll::STATUS_ACTIVE, Poll::STATUS_CLOSED])],
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
                'options' => 'required|array|min:2',
                'options.*.option_text' => 'required|string|max:255',
                'options.*.sort_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validationResponse($validator);
            }

            $validated = $validator->validated();

            $poll = DB::transaction(function () use ($validated) {
                $poll = Poll::create([
                    'question' => $validated['question'],
                    'description' => $validated['description'] ?? null,
                    'status' => $validated['status'] ?? Poll::STATUS_ACTIVE,
                    'starts_at' => $validated['starts_at'] ?? null,
                    'ends_at' => $validated['ends_at'] ?? null,
                ]);

                foreach ($validated['options'] as $index => $option) {
                    $poll->options()->create([
                        'option_text' => $option['option_text'],
                        'sort_order' => $option['sort_order'] ?? $index,
                    ]);
                }

                return $poll;
            });

            return response()->json([
                'status' => true,
                'message' => 'Poll created successfully',
                'data' => $this->freshPoll($poll->id),
            ], 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create poll', $e);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $poll = Poll::find($id);

            if (!$poll) {
                return $this->notFoundResponse('Poll not found');
            }

            $validator = Validator::make($request->all(), [
                'question' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'status' => ['sometimes', Rule::in([Poll::STATUS_ACTIVE, Poll::STATUS_CLOSED])],
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
            ]);

            if ($validator->fails()) {
                return $this->validationResponse($validator);
            }

            $poll->update($validator->validated());

            return response()->json([
                'status' => true,
                'message' => 'Poll updated successfully',
                'data' => $this->freshPoll($poll->id),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update poll', $e);
        }
    }

    public function destroy($id)
    {
        try {
            $poll = Poll::find($id);

            if (!$poll) {
                return $this->notFoundResponse('Poll not found');
            }

            $poll->delete();

            return response()->json([
                'status' => true,
                'message' => 'Poll deleted successfully',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete poll', $e);
        }
    }

    public function storeOption(Request $request, $pollId)
    {
        try {
            $poll = Poll::find($pollId);

            if (!$poll) {
                return $this->notFoundResponse('Poll not found');
            }

            $validator = Validator::make($request->all(), [
                'option_text' => 'required|string|max:255',
                'sort_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validationResponse($validator);
            }

            $option = $poll->options()->create($validator->validated());

            return response()->json([
                'status' => true,
                'message' => 'Poll option created successfully',
                'data' => $option,
            ], 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create poll option', $e);
        }
    }

    public function updateOption(Request $request, $optionId)
    {
        try {
            $option = PollOption::find($optionId);

            if (!$option) {
                return $this->notFoundResponse('Poll option not found');
            }

            $validator = Validator::make($request->all(), [
                'option_text' => 'sometimes|required|string|max:255',
                'sort_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validationResponse($validator);
            }

            $option->update($validator->validated());

            return response()->json([
                'status' => true,
                'message' => 'Poll option updated successfully',
                'data' => $option->fresh(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update poll option', $e);
        }
    }

    public function destroyOption($optionId)
    {
        try {
            $option = PollOption::find($optionId);

            if (!$option) {
                return $this->notFoundResponse('Poll option not found');
            }

            if ($option->poll->options()->count() <= 2) {
                return response()->json([
                    'status' => false,
                    'message' => 'A poll must have at least 2 options',
                ], 422);
            }

            $option->delete();

            return response()->json([
                'status' => true,
                'message' => 'Poll option deleted successfully',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete poll option', $e);
        }
    }

    public function vote(Request $request, $pollId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|string|exists:user,uid',
                'poll_option_id' => 'required|integer|exists:poll_options,id',
            ]);

            if ($validator->fails()) {
                return $this->validationResponse($validator);
            }

            $poll = Poll::available()->find($pollId);

            if (!$poll) {
                return response()->json([
                    'status' => false,
                    'message' => 'Poll not found or not available for voting',
                ], 404);
            }

            $option = PollOption::where('poll_id', $poll->id)
                ->where('id', $request->poll_option_id)
                ->first();

            if (!$option) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected option does not belong to this poll',
                ], 422);
            }

            $vote = DB::transaction(function () use ($poll, $request) {
                return PollVote::updateOrCreate(
                    [
                        'poll_id' => $poll->id,
                        'uid' => $request->uid,
                    ],
                    [
                        'poll_option_id' => $request->poll_option_id,
                    ]
                );
            });

            return response()->json([
                'status' => true,
                'message' => $vote->wasRecentlyCreated ? 'Vote submitted successfully' : 'Vote updated successfully',
                'data' => $vote->load(['poll', 'option']),
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Vote could not be saved',
                'error' => $e->getMessage(),
            ], 409);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to submit vote', $e);
        }
    }

    public function removeVote(Request $request, $pollId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|string|exists:user,uid',
            ]);

            if ($validator->fails()) {
                return $this->validationResponse($validator);
            }

            $deleted = PollVote::where('poll_id', $pollId)
                ->where('uid', $request->uid)
                ->delete();

            if (!$deleted) {
                return $this->notFoundResponse('Vote not found');
            }

            return response()->json([
                'status' => true,
                'message' => 'Vote removed successfully',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to remove vote', $e);
        }
    }

    public function results($pollId)
    {
        try {
            $poll = Poll::with(['options' => function ($query) {
                $query->withCount('votes');
            }])->withCount('votes')->find($pollId);

            if (!$poll) {
                return $this->notFoundResponse('Poll not found');
            }

            $totalVotes = (int) $poll->votes_count;

            $poll->options->transform(function (PollOption $option) use ($totalVotes) {
                $votesCount = (int) $option->votes_count;
                $option->percentage = $totalVotes > 0
                    ? round(($votesCount / $totalVotes) * 100, 2)
                    : 0;

                return $option;
            });

            return response()->json([
                'status' => true,
                'data' => [
                    'poll' => $poll,
                    'total_votes' => $totalVotes,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch poll results', $e);
        }
    }

    public function voters($pollId)
    {
        try {
            $poll = Poll::find($pollId);

            if (!$poll) {
                return $this->notFoundResponse('Poll not found');
            }

            $votes = PollVote::with(['option', 'user'])
                ->where('poll_id', $poll->id)
                ->orderBy('id', 'desc')
                ->paginate(50);

            return response()->json([
                'status' => true,
                'data' => $votes,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch poll voters', $e);
        }
    }

    private function normalizeOptions(array $options): array
    {
        return collect($options)
            ->map(function ($option, $index) {
                if (is_array($option)) {
                    return [
                        'option_text' => $option['option_text'] ?? $option['text'] ?? null,
                        'sort_order' => $option['sort_order'] ?? $index,
                    ];
                }

                return [
                    'option_text' => $option,
                    'sort_order' => $index,
                ];
            })
            ->values()
            ->all();
    }

    private function freshPoll($pollId): Poll
    {
        return Poll::withCount('votes')
            ->with(['options' => function ($query) {
                $query->withCount('votes');
            }])
            ->findOrFail($pollId);
    }

    private function appendUserVote(Poll $poll, string $uid): Poll
    {
        $poll->user_vote = PollVote::where('poll_id', $poll->id)
            ->where('uid', $uid)
            ->first();

        return $poll;
    }

    private function validationResponse($validator)
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function notFoundResponse(string $message)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 404);
    }

    private function errorResponse(string $message, \Exception $e)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }
}
