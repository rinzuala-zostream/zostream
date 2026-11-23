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
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    private const PROFILE_FIELDS = ['profile_district_code', 'profile_village_code', 'profile_gender'];

    public function show(string $id): JsonResponse
    {
        return V4Response::success($this->findUser($id)->makeVisible(self::PROFILE_FIELDS));
    }

    public function locations(MizoramLocations $locations): JsonResponse
    {
        return V4Response::success(['districts' => $locations->districts()]);
    }

    public function update(Request $request, string $id, MizoramLocations $locations): JsonResponse
    {
        $user = $this->findUser($id);
        if (is_string($request->input('username'))) {
            $request->merge(['username' => strtolower(ltrim(trim($request->input('username')), '@'))]);
        }
        $data = $request->validate([
            'num' => ['sometimes', 'integer', Rule::in([$user->getKey()])],
            'uid' => ['sometimes', 'string', Rule::in([$user->uid])],
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:30', 'regex:/\A[a-z0-9_]+\z/', Rule::unique('user', 'username')->ignore($user->getKey(), 'num')],
            'name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'mail' => ['sometimes', 'nullable', 'string', 'max:180'],
            'call' => ['sometimes', 'nullable', 'string', 'max:40'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'auth_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_auth_phone_active' => ['sometimes', 'boolean'],
            'img' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'khua' => ['sometimes', 'nullable', 'string', 'max:180'],
            'veng' => ['sometimes', 'nullable', 'string', 'max:180'],
            'profile_district_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'profile_village_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'profile_gender' => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'token' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'isACActive' => ['sometimes', 'boolean'],
            'isAccountComplete' => ['sometimes', 'boolean'],
            'created_date' => ['sometimes', 'nullable', 'date'],
            'edit_date' => ['sometimes', 'nullable', 'date'],
            'lastLogin' => ['sometimes', 'nullable', 'date'],
        ], ['username.unique' => 'This username is already taken. Choose another one.', 'uid.in' => 'The account UID cannot be changed.', 'num.in' => 'The internal account ID cannot be changed.']);
        unset($data['uid'], $data['num']);
        foreach (['created_date', 'edit_date', 'lastLogin', 'dob'] as $field) {
            if (isset($data[$field])) $data[$field] = Carbon::parse($data[$field])->format($field === 'dob' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        try {
            $updated = DB::transaction(function () use ($user, $data, $locations) {
                $locked = UserModel::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                if (array_key_exists('profile_district_code', $data) || array_key_exists('profile_village_code', $data)) {
                    $district = array_key_exists('profile_district_code', $data) ? $data['profile_district_code'] : $locked->profile_district_code;
                    $village = array_key_exists('profile_village_code', $data) ? $data['profile_village_code'] : $locked->profile_village_code;
                    if (($district || $village) && ! $locations->find($district, $village)) {
                        throw ValidationException::withMessages(['profile_village_code' => 'Choose a village from the selected district, or clear both location fields.']);
                    }
                }
                if ($data !== []) {
                    $locked->forceFill($data + ['edit_date' => now()->format('Y-m-d H:i:s')])->save();
                }
                return $locked;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! empty($data['username']) && UserModel::where('username', $data['username'])->where('num', '!=', $user->getKey())->exists()) {
                throw ValidationException::withMessages(['username' => 'This username is already taken. Choose another one.']);
            }
            throw $exception;
        }

        return V4Response::success($updated->makeVisible(self::PROFILE_FIELDS), 'User saved successfully.');
    }

    private function findUser(string $id): UserModel
    {
        return ctype_digit($id)
            ? UserModel::where('num', (int) $id)->firstOrFail()
            : UserModel::where('uid', $id)->firstOrFail();
    }
}
