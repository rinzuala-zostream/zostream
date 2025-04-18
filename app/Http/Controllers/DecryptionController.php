<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use App\Models\WatchHistoryModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DecryptionController extends Controller
{
    private $validApiKey;

    private $allowedPackageNames = [
        'com.buannel.studio.pvt.ltd.zostream',
        'com.test',
    ];

    private $allowedSha = [
        'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
        '24a4785bb225d7392aa419e218d9e2e7461e193a27c42d8af8418d28e0d53676',
    ];
    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function decryptMessage(Request $request)
    {

        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"]);
        }

        $message = $request->input('msg');
        $packageName = $request->input('packageName');
        $shaKey = $request->input('sha');
        $userId = $request->input('userId');
        $movieId = $request->input('movieId');
        $isAgeRestricted = $request->boolean('isAgeRestricted');

        // Package name check
        if (!$packageName || !in_array($packageName, $this->allowedPackageNames)) {
            return response()->json(['status' => '101', 'message' => 'Invalid package name']);
        }

        // SHA key check
        if (!$shaKey || !in_array($shaKey, $this->allowedSha)) {
            return response()->json(['status' => '100', 'message' => 'Invalid SHA key']);
        }

        // Get user age
        $userAge = null;
        if ($userId) {
            $user = UserModel::select('dob')->where('uid', $userId)->first();
            if ($user && $user->dob) {
                $userAge = Carbon::parse($user->dob)->age;
            }
        }

        // Get watch position
        $watchPosition = 0;
        if ($userId && $movieId) {
            $watchData = WatchHistoryModel::select('position')
                ->where('user_id', $userId)
                ->where('movie_id', $movieId)
                ->first();
            $watchPosition = $watchData->position ?? 0;
        }

        // Age restriction check
        if ($isAgeRestricted && ($userAge === null || $userAge < 18)) {
            return response()->json([
                'status' => '104',
                'message' => 'Age restriction avangin i en thei lo. Khawngaihin adang en rawh'
            ]);
        }

        // Determine decryption key
        $decryptionKey = hash(
            'sha256',
            ($shaKey === '24a4785bb225d7392aa419e218d9e2e7461e193a27c42d8af8418d28e0d53676') ?
            'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a' :
            $shaKey,
            true
        );

        // Decrypt the message
        $data = base64_decode($message);
        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        $result = str_replace(["\n", "\r"], "", $decryptedMessage);

        return response()->json([
            'status' => '103',
            'message' => $result,
            'watchPosition' => $watchPosition
        ]);
    }
}
