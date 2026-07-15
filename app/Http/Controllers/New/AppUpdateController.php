<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;

class AppUpdateController extends Controller
{
    private const VERSION_FIELDS = [
        'ios_update' => 'v_code',
        'lg_tv_update' => 'version',
        'sam_tv_update' => 'version',
        'tv_update' => 'v',
    ];

    private $database;

    public function __construct()
    {
        $databaseUrl = config('firebase.database_url');
        $credentials = config('firebase.credentials');

        if (empty($databaseUrl)) {
            throw new \RuntimeException('FIREBASE_DATABASE_URL is missing in .env');
        }

        if (empty($credentials) || !file_exists($credentials)) {
            throw new \RuntimeException('Firebase credentials file not found: ' . $credentials);
        }

        $firebase = (new Factory)
            ->withServiceAccount($credentials)
            ->withDatabaseUri($databaseUrl);

        $this->database = $firebase->createDatabase();
    }

    public function index()
    {
        try {
            $updates = [];

            foreach (array_keys(self::VERSION_FIELDS) as $platform) {
                $value = $this->database->getReference($platform)->getValue();

                if (is_array($value)) {
                    $updates[$platform] = $this->normalizeConfig($platform, $value);
                }
            }

            return response()->json([
                'status' => true,
                'data' => $updates,
            ]);
        } catch (\Throwable $error) {
            return $this->firebaseErrorResponse($error, 'Failed to fetch app updates');
        }
    }

    public function show(string $platform)
    {
        if (!$this->isValidPlatform($platform)) {
            return $this->invalidPlatformResponse();
        }

        try {
            $value = $this->database->getReference($platform)->getValue();

            if (!is_array($value)) {
                return response()->json([
                    'status' => false,
                    'message' => 'App update configuration not found',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $this->normalizeConfig($platform, $value),
            ]);
        } catch (\Throwable $error) {
            return $this->firebaseErrorResponse($error, 'Failed to fetch app update');
        }
    }

    public function update(Request $request, string $platform)
    {
        if (!$this->isValidPlatform($platform)) {
            return $this->invalidPlatformResponse();
        }

        $versionField = self::VERSION_FIELDS[$platform];
        $numericVersion = in_array($platform, ['ios_update', 'tv_update'], true);
        $rules = [
            'force' => ['required', 'boolean'],
            'url' => ['nullable', 'string', 'max:2048'],
            $versionField => $numericVersion
                ? ['required', 'integer', 'min:0']
                : ['nullable', 'string', 'max:100'],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $config = [
            'force' => (bool) $validated['force'],
            'url' => $validated['url'] ?? '',
            $versionField => $validated[$versionField] ?? '',
        ];

        try {
            $this->database->getReference($platform)->set($config);

            return response()->json([
                'status' => true,
                'message' => 'App update configuration saved',
                'data' => $config,
            ]);
        } catch (\Throwable $error) {
            return $this->firebaseErrorResponse($error, 'Failed to save app update');
        }
    }

    private function isValidPlatform(string $platform): bool
    {
        return array_key_exists($platform, self::VERSION_FIELDS);
    }

    private function invalidPlatformResponse()
    {
        return response()->json([
            'status' => false,
            'message' => 'Invalid app update platform',
            'allowed_platforms' => array_keys(self::VERSION_FIELDS),
        ], 422);
    }

    private function normalizeConfig(string $platform, array $value): array
    {
        $versionField = self::VERSION_FIELDS[$platform];

        return [
            'force' => ($value['force'] ?? false) === true,
            'url' => is_string($value['url'] ?? null) ? $value['url'] : '',
            $versionField => $value[$versionField] ?? '',
        ];
    }

    private function firebaseErrorResponse(\Throwable $error, string $message)
    {
        Log::error($message, [
            'exception' => $error,
        ]);

        return response()->json([
            'status' => false,
            'message' => $message,
        ], 500);
    }
}
