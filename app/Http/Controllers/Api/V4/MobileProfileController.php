<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\UserModel;
use App\Support\Api\V4Response;
use App\Support\MizoramLocations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileProfileController extends Controller
{
    private const USERNAME_RULES = ['required', 'string', 'min:3', 'max:30', 'regex:/\A[a-z0-9_]+\z/'];

    private const GENDERS = ['male', 'female', 'other', 'prefer_not_to_say'];

    public function show(Request $request, MizoramLocations $locations): JsonResponse
    {
        return V4Response::success($this->profile($this->user($request), $locations));
    }

    public function locations(MizoramLocations $locations): JsonResponse
    {
        return V4Response::success(['districts' => $locations->districts()]);
    }

    public function username(Request $request): JsonResponse
    {
        $this->normalizeUsername($request);
        $data = $request->validate(['username' => self::USERNAME_RULES]);
        $user = $this->user($request);
        $available = ! UserModel::where('username', $data['username'])->where('num', '!=', $user->getKey())->exists();

        return V4Response::success(['username' => $data['username'], 'available' => $available]);
    }

    public function store(Request $request, MizoramLocations $locations): JsonResponse
    {
        $this->normalizeUsername($request);
        $user = $this->user($request);
        $data = $request->validate([
            'username' => [...self::USERNAME_RULES, Rule::unique('user', 'username')->ignore($user->getKey(), 'num')],
            'district_code' => ['required', 'string', 'max:20'],
            'village_code' => ['required', 'string', 'max:20'],
            'gender' => ['required', Rule::in(self::GENDERS)],
        ], ['username.unique' => 'This username is already taken. Choose another one.']);

        if (! $locations->find($data['district_code'], $data['village_code'])) {
            throw ValidationException::withMessages(['village_code' => 'Choose a village from the selected district.']);
        }

        try {
            DB::transaction(function () use ($user, $data) {
                // Serialize submissions from two devices belonging to this account.
                $locked = UserModel::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                if (filled($locked->username) && $locked->username !== $data['username']) {
                    throw ValidationException::withMessages(['username' => 'Your username cannot be changed.']);
                }
                $locked->forceFill([
                    'username' => $data['username'],
                    'profile_district_code' => $data['district_code'],
                    'profile_village_code' => $data['village_code'],
                    'profile_gender' => $data['gender'],
                ])->save();
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Availability checks are advisory; the unique index also handles races.
            if (! UserModel::where('username', $data['username'])->where('num', '!=', $user->getKey())->exists()) {
                throw $exception;
            }

            return V4Response::error('USERNAME_TAKEN', 'This username is already taken. Choose another one.', 409);
        }

        return V4Response::success($this->profile($user->fresh(), $locations), 'Profile saved.');
    }

    private function user(Request $request): UserModel
    {
        return UserModel::where('uid', $request->input('auth_user_id'))->firstOrFail();
    }

    private function normalizeUsername(Request $request): void
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => strtolower(trim($request->input('username')))]);
        }
    }

    private function profile(UserModel $user, MizoramLocations $locations): array
    {
        $location = $locations->find($user->profile_district_code, $user->profile_village_code);

        return [
            'user_id' => $user->uid,
            'username' => $user->username,
            'district_code' => $user->profile_district_code,
            'village_code' => $user->profile_village_code,
            'district' => $location['district'] ?? null,
            'village' => $location['village'] ?? null,
            'gender' => $user->profile_gender,
            'complete' => filled($user->username) && $location !== null && in_array($user->profile_gender, self::GENDERS, true),
        ];
    }
}
