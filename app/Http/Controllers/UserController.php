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
                return response()->json($user);
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
        $request->validate([
            'uid' => 'required|string',
            'call' => 'nullable|string',
            'isAccountComplete' => 'nullable|boolean',
            'khua' => 'nullable|string',
            'name' => 'nullable|string',
            'veng' => 'nullable|string',
            'dob' => 'nullable|string' // add when you start saving DOB
        ]);

        try {
            $user = UserModel::where('uid', $request->input('uid'))->first();

            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }

            // Use India timezone if that’s your source of truth
            $editDate = now('Asia/Kolkata')->format('M d Y, h:i:s A'); // e.g., "Sep 29 2025, 11:32:59 AM"

            // Normalize incoming values: convert "" to null for nullable columns
            $normalize = fn($v) => ($v === '' ? null : $v);

            // Only set keys that were actually provided (avoid overwriting with null accidentally)
            $payload = [
                'call' => $normalize($request->input('call')),
                'isAccountComplete' => $request->has('isAccountComplete')
                    ? $request->boolean('isAccountComplete')
                    : $user->isAccountComplete, // keep current if not provided
                'khua' => $normalize($request->input('khua')),
                'name' => $normalize($request->input('name')),
                'veng' => $normalize($request->input('veng')),
                'dob' => $normalize($request->input('dob')),
                'edit_date' => $editDate,
            ];

            // Fill then detect dirty fields
            $user->fill($payload);

            if (!$user->isDirty()) {
                // Nothing changed
                return response()->json([
                    'status' => 'no_change',
                    'message' => 'No fields changed',
                    'edit_date' => $editDate,
                ]);
            }

            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'edit_date' => $editDate,
                'changed' => array_keys($user->getChanges()), // which columns changed
                'data' => $user->only(['uid', 'name', 'khua', 'veng', 'call', 'isAccountComplete', 'edit_date']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
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

    public function sendWishes()
    {
        $now = Carbon::now();

        $todayMonth = $now->month;
        $todayDay = $now->day;

        $users = UserModel::all()->filter(function ($user) use ($todayMonth, $todayDay) {
            try {
                $dob = Carbon::parse($user->dob);
                return $dob->month === $todayMonth && $dob->day === $todayDay;
            } catch (\Exception $e) {
                return false;
            }
        });

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            $messages = [
                "🎉 Happy Birthday, {$user->name}! Wishing you a day filled with love, laughter, and joy!",
                "🎂 Cheers to you, {$user->name}! May your birthday be as amazing as you are!",
                "🎈 Hey {$user->name}, it's your special day! Enjoy every moment of it – happy birthday!",
                "🥳 Zo Stream wishes you the happiest of birthdays, {$user->name}! Stay awesome!",
                "🎁 Warmest wishes on your birthday, {$user->name}! Hope your day is full of surprises and joy.",
                "🌟 Happy Birthday, {$user->name}! May your year ahead be bright and full of success!",
            ];

            $body = $messages[array_rand($messages)];

            try {
                $response = Http::asForm()->post('https://zostream.in/mail/send_mail.php', [
                    'recipient' => $user->mail,
                    'subject' => 'Happy Birthday from Zo Stream!',
                    'body' => $body,
                ]);

                if ($response->successful()) {
                    $sent++;
                } else {
                    $failed++;
                    return response()->json([
                        'email' => $user->mail,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                $failed++;
                return response()->json([
                    'email' => $user->mail,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'status' => 'done',
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }
}
