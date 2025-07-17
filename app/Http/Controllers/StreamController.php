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
            $ipinfoResponse = Http::timeout(5)->get("https://ipinfo.io/{$ip}/json");

            if ($ipinfoResponse->successful()) {
                $ipInfo = $ipinfoResponse->json();

                if (
                    isset($ipInfo['org']) &&
                    $ipInfo['org'] === 'AS141253 Hyosec Solutions Private Limited'
                ) {
                    $isFromISP = true;
                }
            }

            // Step 2: Fallback to ipwhois.app if not matched
            if (!$isFromISP) {
                $fallbackData = json_decode(@file_get_contents("https://ipwhois.app/json/{$ip}"), true);

                if (
                    isset($fallbackData['asn']) &&
                    $fallbackData['asn'] === 'AS141253' &&
                    ($fallbackData['org'] ?? '') === 'ZONET COMMUNICATIONS' &&
                    ($fallbackData['isp'] ?? '') === 'Hyosec Solutions Private Limited'
                ) {
                    $isFromISP = true;
                }

                // Use fallback data if available
                if (!empty($fallbackData)) {
                    $ipInfo = $fallbackData;
                }
            }

            // Final response
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
