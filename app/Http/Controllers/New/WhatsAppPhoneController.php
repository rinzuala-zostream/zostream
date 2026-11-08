<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\UserModel;

class WhatsAppPhoneController extends Controller
{
    public function updatePhone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'auth_phone' => 'required|string|max:20',
            'country_code' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = UserModel::where('uid', $request->user_id)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $user->auth_phone = preg_replace('/[^0-9]/', '', $request->auth_phone);
        $user->country_code = trim($request->country_code);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Phone number updated successfully.',
            'data' => [
                'user_id' => $user->id,
                'country_code' => $user->country_code,
                'auth_phone' => $user->auth_phone,
                'full_phone' => $user->country_code . $user->auth_phone,
            ]
        ]);
    }

    public function checkPhone(Request $request)
    {
        $user = UserModel::where('uid', $request->user_id)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $needsUpdate = empty($user->country_code);

        return response()->json([
            'status' => true,
            'needs_update' => $needsUpdate,
            'country_code' => $user->country_code,
            'auth_phone' => $user->auth_phone,
            'message' => $needsUpdate
                ? 'Country code is missing.'
                : 'Country code already exists.'
        ]);
    }
}
