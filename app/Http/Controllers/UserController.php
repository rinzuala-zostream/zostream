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

}
