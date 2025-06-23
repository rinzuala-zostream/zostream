<?php

namespace App\Http\Controllers;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Nette\Utils\Random;

class AxinomLicense extends Controller
{
    public function invokeWidevineCommonEncryption()
    {
        $contentId = Random::generate(16, '0-9a-zA-Z'); // Generate a random content ID
        $widevineRequest = [
            "content_id" => base64_encode("CID:$contentId"),
            "drm_types" => ["WIDEVINE"],
            "tracks" => [
                ["type" => "HD"],
            ],
            "protection_scheme" => "CENC"
        ];

        $response = $this->executeWidevineRequest($widevineRequest);

        // Extract only key_id and key, and convert to hex
        $keys = collect($response['tracks'] ?? [])->map(function ($track) {
            $keyId = bin2hex(base64_decode($track['key_id'] ?? '')); // Extract the key_id

            return [
                'type' => $track['type'] ?? null,
                'key_id' => $keyId,  // Use the extracted key_id
                'key' => bin2hex(base64_decode($track['key'] ?? '')),
            ];
        });

        // Generate JWT Token with dynamic keyId
        $jwtToken = $this->generateJwtToken($keys);

        return response()->json([
            'status' => 'OK',
            'keys' => $keys,
            'token' => $jwtToken, // Include the token in the response
        ]);
    }

    private function executeWidevineRequest(array $widevineRequest): array
    {
        $MOSAIC_ENDPOINT = 'https://key-server-management.axprod.net/api/WidevineProtectionInfo';
        $MOSAIC_KEY_NAME = 'db3ceb38-e7da-4927-95e7-59a185472f06';
        $SIGNING_KEY = 'F361301083164464249A1B49F7C534D51880942A83F380EA8856BEEF75091F2E'; // 64 hex characters (32 bytes)
        $SIGNING_IV = '212DEF577CE5B181267E09BC07CF219A'; // 32 hex characters (16 bytes)

        if (!$SIGNING_KEY || !$SIGNING_IV) {
            abort(500, "Signing key or IV not configured.");
        }

        $requestJson = json_encode($widevineRequest, JSON_PRETTY_PRINT);

        // Hash with SHA1
        $sha1Hash = sha1($requestJson, true);

        // Encrypt SHA1 hash using AES-256-CBC
        $encrypted = openssl_encrypt(
            $sha1Hash,
            'aes-256-cbc',
            hex2bin($SIGNING_KEY),
            OPENSSL_RAW_DATA,
            hex2bin($SIGNING_IV)
        );

        // Create signature
        $signature = base64_encode($encrypted);

        $envelope = [
            "request" => base64_encode($requestJson),
            "signature" => $signature,
            "signer" => $MOSAIC_KEY_NAME,
        ];

        // POST to Axinom Key Service
        $response = Http::acceptJson()->post($MOSAIC_ENDPOINT, $envelope);

        if (!$response->successful()) {
            abort($response->status(), $response->body());
        }

        $decoded = json_decode(base64_decode($response->json('response')), true);

        return $decoded ?? ['error' => 'Invalid response from Axinom DRM'];
    }

    // Method to generate JWT Token
    private function generateJwtToken($keys): string
    {
        $communicationKeyAsBase64 = "uoy1wOPkyPQznp7MIb8auiSoaeSbRn2ExzQdFZrsuPQ=";
        $communicationKeyId = "c52ad793-022a-447f-8250-b2ba00568b80";

        // Decode communication key from base64
        $communicationKey = base64_decode($communicationKeyAsBase64);

        // Extract the first keyId from the keys (you may want to adjust this if there are multiple keys)
        $keyId = $keys[0]['key_id'] ?? null;

        // Prepare the payload with the dynamic keyId
        $payload = [
            "version" => 1,
            "com_key_id" => $communicationKeyId,
            "message" => [
                "type" => "entitlement_message",
                "version" => 2,
                "content_keys_source" => [
                    "inline" => [
                        [
                            "id" => $keyId  // Use the dynamic keyId in the payload
                        ]
                    ]
                ]
            ]
        ];

        // Encode the JWT
        $jwtToken = JWT::encode($payload, $communicationKey, 'HS256'); // Generate the JWT token

        return $jwtToken; // Return the generated JWT token
    }
}
