<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StreamController extends Controller
{
    public function stream(Request $request)
    {
        try {
            $ip = $request->query('ip', $request->ip());
            $isFromISP = false;
            $ipInfo = [];

            // Step 1: Try ipinfo.io
            $ipinfoResponse = Http::timeout(5)->get("https://ipinfo.io/{$ip}/json?token=45341976cbdb77");

            if ($ipinfoResponse->successful()) {
                $ipInfo = $ipinfoResponse->json();

                // Step 2: Only check ipwhois.pro if org matches
                if (
                    isset($ipInfo['asn.asn']) &&
                    $ipInfo['asn.name'] === 'Hyosec Solutions Private Limited'
                ) {
                    $apiKey = config('app.ipwhois_api_key');
                    $url = "https://ipwhois.pro/{$ip}?key={$apiKey}";
                    $fallbackData = json_decode(@file_get_contents($url), true);

                    $connection = $fallbackData['connection'] ?? [];

                    if (
                        isset($connection['asn']) &&
                        $connection['asn'] == 141253 &&
                        ($connection['org'] ?? '') === 'ZONET COMMUNICATIONS' &&
                        ($connection['isp'] ?? '') === 'Hyosec Solutions Private Limited'
                    ) {
                        $isFromISP = true;
                    }

                    if (!empty($fallbackData)) {
                        $ipInfo = $fallbackData;
                    }
                }
            } else {
                // 👇 add this
                $ipInfo = [
                    'error' => 'ipinfo.io request failed',
                    'status' => $ipinfoResponse->status(),
                    'body' => $ipinfoResponse->body(),
                    'message' => 'Falling back to ipwhois only'
                ];
            }

            return response()->json([
                'status' => true,
                'is_from_isp' => $isFromISP,
                'ip_info' => $ipInfo,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'ISP check failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
