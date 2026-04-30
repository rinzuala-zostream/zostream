<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Carbon\Carbon;
use Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key'); // Get the API key from the config
    }

    public function getUserData(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        // Validate query parameters
        $request->validate([
            'mail' => 'nullable|email',
            'uid' => 'nullable|string',
        ]);

        try {
            $mail = $request->query('mail');
            $uid = $request->query('uid');

            $user = null;
            if (!empty($mail)) {
                $user = UserModel::where('mail', $mail)->first();
            } elseif (!empty($uid)) {
                $user = UserModel::where('uid', $uid)->first();
            }

            if ($user) {
                $data = $user->toArray();

                // Normalize dob: if null or empty, set to "0"
                if (empty($data['dob'])) {
                    $data['dob'] = "0";
                }

                return response()->json($data);
            } else {
                return response()->json(['status' => 'error', 'message' => 'User not found']);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching user data: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error']);
        }
    }

    public function searchUsers(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $request->validate([
            'q' => 'nullable|string|max:120',
            'query' => 'nullable|string|max:120',
            'search' => 'nullable|string|max:120',
            'mail' => 'nullable|email|max:180',
            'email' => 'nullable|email|max:180',
            'uid' => 'nullable|string|max:180',
            'name' => 'nullable|string|max:180',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $searchQuery = trim((string) (
            $request->query('q')
            ?? $request->query('query')
            ?? $request->query('search')
            ?? ''
        ));
        $mail = trim((string) ($request->query('mail') ?? $request->query('email') ?? ''));
        $uid = trim((string) $request->query('uid', ''));
        $name = trim((string) $request->query('name', ''));
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        if ($searchQuery === '' && $mail === '' && $uid === '' && $name === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Provide at least one search value: q, query, search, mail, email, uid, or name'
            ], 422);
        }

        try {
            $baseQuery = UserModel::query();

            $baseQuery->where(function ($query) use ($searchQuery, $mail, $uid, $name) {
                if ($mail !== '') {
                    $query->orWhere('mail', 'like', '%' . $mail . '%');
                }

                if ($uid !== '') {
                    $query->orWhere('uid', 'like', '%' . $uid . '%');
                }

                if ($name !== '') {
                    $query->orWhere('name', 'like', '%' . $name . '%');
                }

                if ($searchQuery !== '') {
                    $query->orWhere('mail', 'like', '%' . $searchQuery . '%')
                        ->orWhere('uid', 'like', '%' . $searchQuery . '%')
                        ->orWhere('name', 'like', '%' . $searchQuery . '%')
                        ->orWhere('call', 'like', '%' . $searchQuery . '%')
                        ->orWhere('auth_phone', 'like', '%' . $searchQuery . '%');
                }
            });

            $orderNeedle = $searchQuery !== '' ? $searchQuery : ($mail !== '' ? $mail : ($uid !== '' ? $uid : $name));
            $likeNeedle = '%' . $orderNeedle . '%';
            $prefixNeedle = $orderNeedle . '%';

            $users = $baseQuery
                ->orderByRaw(
                    "CASE
                        WHEN mail = ? THEN 1000
                        WHEN uid = ? THEN 980
                        WHEN name = ? THEN 950
                        WHEN mail LIKE ? THEN 900
                        WHEN uid LIKE ? THEN 880
                        WHEN name LIKE ? THEN 860
                        WHEN mail LIKE ? THEN 760
                        WHEN uid LIKE ? THEN 740
                        WHEN name LIKE ? THEN 720
                        WHEN call LIKE ? THEN 620
                        WHEN auth_phone LIKE ? THEN 600
                        ELSE 0
                    END DESC",
                    [
                        $orderNeedle,
                        $orderNeedle,
                        $orderNeedle,
                        $prefixNeedle,
                        $prefixNeedle,
                        $prefixNeedle,
                        $likeNeedle,
                        $likeNeedle,
                        $likeNeedle,
                        $likeNeedle,
                        $likeNeedle,
                    ]
                )
                ->orderBy('name')
                ->orderByDesc('num')
                ->paginate($perPage);

            $users->getCollection()->transform(function ($user) use ($searchQuery, $mail, $uid, $name) {
                return $this->formatUserSearchResult($user, $searchQuery, $mail, $uid, $name);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Users fetched successfully',
                'search' => [
                    'query' => $searchQuery,
                    'mail' => $mail,
                    'uid' => $uid,
                    'name' => $name,
                    'sort' => 'best_match',
                ],
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                    'has_more_pages' => $users->hasMorePages(),
                ],
                'data' => $users->items(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error searching users: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Internal Server Error'
            ], 500);
        }
    }

    public function updateDob(Request $request)
    {
        // Validate required input
        $request->validate([
            'uid' => 'required|string',
            'dob' => 'required|date',
        ]);

        $uid = $request->query('uid');
        $dob = $request->query('dob');

        // Check if the user exists
        $user = UserModel::where('uid', $uid)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'UID not found in the database'
            ], 404);
        }

        try {
            UserModel::where('uid', $uid)
                ->update(['dob' => $dob]);

            return response()->json([
                'status' => 'success',
                'message' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateToken(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        // Validate request
        $request->validate([
            'uid' => 'required|string',
            'token' => 'required|string',
        ]);

        $uid = $request->query('uid');
        $token = $request->query('token');

        try {

            $user = UserModel::where('uid', $uid)->first();

            if ($user) {
                UserModel::where('uid', $uid)
                    ->update(['token' => $token]);

                return response()->json(['status' => 'success', 'message' => 'Record updated successfully']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateLogin(Request $request)
    {

        // Validate request
        $request->validate([
            'uid' => 'required|string',
            'lastLogin' => 'required|string',
        ]);

        $uid = $request->input('uid');
        $lastLogin = $request->input('lastLogin');

        try {

            $user = UserModel::where('uid', $uid)->first();

            if ($user) {
                UserModel::where('uid', $uid)
                    ->update(['lastLogin' => $lastLogin]);

                return response()->json(['status' => 'success', 'message' => 'Record updated successfully']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            // Prefer uid from root or inside body
            $uid = $request->input('uid') ?? ($request->body['uid'] ?? null);

            if (!$uid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'UID is required'
                ], 400);
            }

            // Determine data source: body{} or root fields
            $data = $request->has('body') && is_array($request->body)
                ? $request->body
                : $request->all();

            // Remove uid from data (not updatable)
            unset($data['uid']);

            // Stop if body is empty or no fields to update
            if (empty($data)) {
                return response()->json([
                    'status' => 'no_change',
                    'message' => 'No fields provided to update'
                ]);
            }

            $user = UserModel::where('uid', $uid)->first();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Record not found'
                ], 404);
            }

            $editDate = now('Asia/Kolkata')->format('M d Y, h:i:s A');
            $data['edit_date'] = $editDate;

            // Fill and save if there are any changes
            $user->fill($data);

            if ($user->isDirty()) {
                $user->save();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Profile updated successfully',
                    'edit_date' => $editDate,
                    'changed' => array_keys($user->getChanges()),
                    'data' => $user
                ]);
            }

            return response()->json([
                'status' => 'no_change',
                'message' => 'Nothing changed',
                'edit_date' => $editDate
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //Delete-User
    public function deleteUser(Request $request)
    {
        // Validate input - either uid or mail must be present
        $request->validate([
            'uid' => 'nullable|string',
            'mail' => 'nullable|email',
        ]);

        $uid = $request->input('uid');
        $mail = $request->input('mail');

        if (empty($uid) && empty($mail)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Either uid or mail is required'
            ], 400);
        }

        try {
            $user = null;

            if (!empty($uid)) {
                $user = UserModel::where('uid', $uid)->first();
            } elseif (!empty($mail)) {
                $user = UserModel::where('mail', $mail)->first();
            }

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $user->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function clearDeviceId(Request $request)
    {

        $request->validate([
            'user_id' => 'required|string',
        ]);

        $userId = $request->user_id;

        $user = UserModel::where('uid', $userId)->first();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $user->device_id = null;

        $user->save();

        return response()->json(['status' => 'success', 'message' => 'Device ID cleared successfully']);
    }

    private function formatUserSearchResult(UserModel $user, string $searchQuery, string $mail, string $uid, string $name): array
    {
        $needles = array_values(array_filter([$searchQuery, $mail, $uid, $name], function ($value) {
            return trim((string) $value) !== '';
        }));

        $matchedFields = $this->getMatchedUserFields($user, $needles);
        $relevanceScore = $this->calculateUserSearchScore($user, $needles);
        $data = $user->toArray();

        if (empty($data['dob'])) {
            $data['dob'] = "0";
        }

        return [
            'match' => [
                'score' => $relevanceScore,
                'fields' => $matchedFields,
            ],
            'identity' => [
                'num' => $data['num'] ?? null,
                'uid' => $data['uid'] ?? null,
                'name' => $data['name'] ?? null,
                'mail' => $data['mail'] ?? null,
                'call' => $data['call'] ?? null,
                'auth_phone' => $data['auth_phone'] ?? null,
            ],
            'account' => [
                'isACActive' => (bool) ($data['isACActive'] ?? false),
                'isAccountComplete' => (bool) ($data['isAccountComplete'] ?? false),
                'is_auth_phone_active' => (bool) ($data['is_auth_phone_active'] ?? false),
                'dob' => $data['dob'] ?? "0",
                'khua' => $data['khua'] ?? null,
                'veng' => $data['veng'] ?? null,
                'img' => $data['img'] ?? null,
            ],
            'device' => [
                'device_id' => $data['device_id'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'token' => $data['token'] ?? null,
            ],
            'activity' => [
                'created_date' => $data['created_date'] ?? null,
                'edit_date' => $data['edit_date'] ?? null,
                'lastLogin' => $data['lastLogin'] ?? null,
            ],
            'raw' => $data,
        ];
    }

    private function getMatchedUserFields(UserModel $user, array $needles): array
    {
        $fields = ['mail', 'uid', 'name', 'call', 'auth_phone'];
        $matchedFields = [];

        foreach ($fields as $field) {
            $value = (string) ($user->{$field} ?? '');

            if ($value === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if (stripos($value, (string) $needle) !== false) {
                    $matchedFields[] = $field;
                    break;
                }
            }
        }

        return array_values(array_unique($matchedFields));
    }

    private function calculateUserSearchScore(UserModel $user, array $needles): int
    {
        $score = 0;
        $weightedFields = [
            'mail' => 100,
            'uid' => 95,
            'name' => 90,
            'call' => 65,
            'auth_phone' => 65,
        ];

        foreach ($needles as $needle) {
            $normalizedNeedle = mb_strtolower(trim((string) $needle));

            if ($normalizedNeedle === '') {
                continue;
            }

            foreach ($weightedFields as $field => $weight) {
                $value = mb_strtolower((string) ($user->{$field} ?? ''));

                if ($value === '') {
                    continue;
                }

                if ($value === $normalizedNeedle) {
                    $score += $weight * 10;
                } elseif (str_starts_with($value, $normalizedNeedle)) {
                    $score += $weight * 7;
                } elseif (str_contains($value, $normalizedNeedle)) {
                    $score += $weight * 5;
                }

                similar_text($normalizedNeedle, $value, $percent);

                if ($percent >= 85) {
                    $score += $weight * 4;
                } elseif ($percent >= 65) {
                    $score += $weight * 2;
                }
            }
        }

        return $score;
    }

}
