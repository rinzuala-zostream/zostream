<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserModel;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{

    // Get all users
    public function index(Request $request)
    {
        try {

            if ($this->cleanSearchTerm($request->get('search', $request->get('q', ''))) !== '') {
                return $this->search($request);
            }

            $limit = $this->perPage($request);

            $users = UserModel::orderBy('num', 'desc')
                ->paginate($limit);

            return response()->json([
                'status' => 'success',
                'data' => $users
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Get single user
    public function show($id)
    {
        try {

            $user = UserModel::where('uid', $id)
                ->orWhere('num', $id)
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $user
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Search users by name, email, phone, UID, or numeric ID
    public function search(Request $request)
    {
        try {

            $request->validate([
                'q' => 'nullable|string|max:120',
                'search' => 'nullable|string|max:120',
                'name' => 'nullable|string|max:120',
                'mail' => 'nullable|string|max:120',
                'email' => 'nullable|string|max:120',
                'phone' => 'nullable|string|max:40',
                'uid' => 'nullable|string|max:120',
                'limit' => 'nullable|integer|min:1|max:100',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $term = $this->cleanSearchTerm($request->get('q', $request->get('search', '')));
            $name = $this->cleanSearchTerm($request->get('name', ''));
            $mail = $this->cleanSearchTerm($request->get('mail', $request->get('email', '')));
            $phone = $this->normalizePhone($request->get('phone', ''));
            $uid = $this->cleanSearchTerm($request->get('uid', ''));

            if ($term === '' && $name === '' && $mail === '' && $phone === '' && $uid === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Search query is required'
                ], 422);
            }

            $termPhone = $this->normalizePhone($term);
            $mailNeedles = $this->emailNeedles($term, $mail);
            $limit = $this->perPage($request);

            $users = UserModel::query()
                ->where(function ($query) use ($term, $name, $mail, $phone, $uid, $termPhone, $mailNeedles) {
                    if ($term !== '') {
                        $query->orWhere('uid', 'like', '%' . $term . '%')
                            ->orWhere('num', $term)
                            ->orWhere('name', 'like', '%' . $term . '%')
                            ->orWhere('mail', 'like', '%' . $term . '%')
                            ->orWhere('call', 'like', '%' . $term . '%')
                            ->orWhere('auth_phone', 'like', '%' . $term . '%')
                            ->orWhere('device_id', 'like', '%' . $term . '%')
                            ->orWhere('device_name', 'like', '%' . $term . '%');
                    }

                    if ($name !== '') {
                        $query->orWhere('name', 'like', '%' . $name . '%');
                    }

                    if ($mail !== '') {
                        $query->orWhere('mail', 'like', '%' . $mail . '%');
                    }

                    foreach ($mailNeedles as $needle) {
                        $query->orWhere('mail', 'like', '%' . $needle . '%');
                    }

                    if ($uid !== '') {
                        $query->orWhere('uid', 'like', '%' . $uid . '%');
                    }

                    if ($phone !== '') {
                        $query->orWhere('call', 'like', '%' . $phone . '%')
                            ->orWhere('auth_phone', 'like', '%' . $phone . '%');
                    }

                    if ($termPhone !== '') {
                        $query->orWhere('call', 'like', '%' . $termPhone . '%')
                            ->orWhere('auth_phone', 'like', '%' . $termPhone . '%');
                    }
                })
                ->orderByRaw($this->searchRankSql(), $this->searchRankBindings($term, $name, $mail, $phone, $uid, $termPhone, $mailNeedles))
                ->orderBy('name')
                ->orderByDesc('num')
                ->paginate($limit)
                ->appends($request->query());

            return response()->json([
                'status' => 'success',
                'message' => 'Users fetched successfully',
                'search' => [
                    'query' => $term,
                    'name' => $name,
                    'mail' => $mail,
                    'phone' => $phone,
                    'uid' => $uid,
                    'sort' => 'best_match',
                ],
                'data' => $users
            ]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search users',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Find user by UID or email
    public function find(Request $request)
    {
        try {

            $request->validate([
                'uid' => 'nullable|string|max:180',
                'mail' => 'nullable|string|max:180',
                
            ]);

            $uid = $request->query('uid', '');
            $mail = $request->query('mail', '');
            
            $user = UserModel::query()
                ->where(function ($query) use ($uid, $mail) {
                    if ($uid !== '') {
                        $query->where('uid', $uid);
                    }

                    if ($mail !== '') {
                        $query->where('mail', $mail);
                    }
                })
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $user
            ]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Create user
    public function store(Request $request)
    {
        try {

            $request->validate([
                'uid' => 'required|string',
                'mail' => 'nullable|string',
                'name' => 'nullable|string'
            ]);

            $user = UserModel::create($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $user
            ], 201);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'User creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Update user
    public function update(Request $request, $id)
    {
        try {

            $user = UserModel::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $validated = $request->validate([
                'uid' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:180',
                    Rule::unique('user', 'uid')->ignore($user->uid, 'uid'),
                ],
                'mail' => 'nullable|string|max:180',
                'name' => 'nullable|string|max:180',
                'call' => 'nullable|string|max:40',
                'auth_phone' => 'nullable|string|max:40',
                'is_auth_phone_active' => 'sometimes|boolean',
                'img' => 'nullable|string|max:2048',
                'dob' => 'nullable|date',
                'khua' => 'nullable|string|max:180',
                'veng' => 'nullable|string|max:180',
                'device_id' => 'nullable|string|max:255',
                'device_name' => 'nullable|string|max:255',
                'token' => 'nullable|string|max:2048',
                'isACActive' => 'sometimes|boolean',
                'isAccountComplete' => 'sometimes|boolean',
            ]);

            $user->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => $user
            ]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Delete user
    public function destroy($id)
    {
        try {

            $user = UserModel::find($id);

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
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function perPage(Request $request)
    {
        $limit = (int) $request->get('limit', $request->get('per_page', 20));
        return max(1, min($limit, 100));
    }


    private function cleanSearchTerm($value)
    {
        return trim((string) $value);
    }


    private function normalizePhone($value)
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits ? substr($digits, -10) : '';
    }


    private function emailNeedles($term, $mail)
    {
        $needles = [];

        foreach ([$term, $mail] as $value) {
            $value = $this->cleanSearchTerm($value);

            if ($value === '') {
                continue;
            }

            $needles[] = $value;

            if (strpos($value, '@') === false) {
                $needles[] = $value . '@';
                $needles[] = $value . '@gmail.com';
                $needles[] = $value . '@yahoo.com';
                $needles[] = $value . '@outlook.com';
                $needles[] = $value . '@hotmail.com';
            }
        }

        return array_values(array_unique($needles));
    }


    private function searchRankSql()
    {
        return "CASE
            WHEN `uid` = ? THEN 1000
            WHEN `num` = ? THEN 990
            WHEN `mail` = ? THEN 980
            WHEN `mail` = ? THEN 970
            WHEN `auth_phone` = ? THEN 960
            WHEN `call` = ? THEN 950
            WHEN `name` = ? THEN 940
            WHEN `uid` LIKE ? THEN 900
            WHEN `mail` LIKE ? THEN 880
            WHEN `mail` LIKE ? THEN 870
            WHEN `name` LIKE ? THEN 860
            WHEN `auth_phone` LIKE ? THEN 840
            WHEN `call` LIKE ? THEN 830
            WHEN `uid` LIKE ? THEN 760
            WHEN `mail` LIKE ? THEN 740
            WHEN `name` LIKE ? THEN 720
            WHEN `auth_phone` LIKE ? THEN 700
            WHEN `call` LIKE ? THEN 690
            ELSE 0
        END DESC";
    }


    private function searchRankBindings($term, $name, $mail, $phone, $uid, $termPhone, array $mailNeedles)
    {
        $primary = $term !== '' ? $term : ($uid !== '' ? $uid : ($mail !== '' ? $mail : ($phone !== '' ? $phone : $name)));
        $primaryPhone = $termPhone !== '' ? $termPhone : $phone;
        $phoneRankNeedle = $primaryPhone !== '' ? $primaryPhone : '__NO_PHONE_MATCH__';
        $primaryMail = $mail !== '' ? $mail : $primary;
        $expandedMail = $mailNeedles[0] ?? $primaryMail;

        return [
            $uid !== '' ? $uid : $primary,
            is_numeric($primary) ? $primary : -1,
            $primaryMail,
            $expandedMail,
            $phoneRankNeedle,
            $phoneRankNeedle,
            $name !== '' ? $name : $primary,
            $primary . '%',
            $primaryMail . '%',
            $expandedMail . '%',
            ($name !== '' ? $name : $primary) . '%',
            $phoneRankNeedle . '%',
            $phoneRankNeedle . '%',
            '%' . $primary . '%',
            '%' . $primaryMail . '%',
            '%' . ($name !== '' ? $name : $primary) . '%',
            '%' . $phoneRankNeedle . '%',
            '%' . $phoneRankNeedle . '%',
        ];
    }

}
