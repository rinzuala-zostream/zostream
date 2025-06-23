<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function stream(Request $request)
    {
        try {
            $ip = $request->query('ip', $request->ip());
            $data = json_decode(file_get_contents("https://ipwhois.app/json/{$ip}"), true);

            $isFromISP = (
                ($data['asn'] ?? '') === 'AS141253' &&
                ($data['org'] ?? '') === 'ZONET COMMUNICATIONS' &&
                ($data['isp'] ?? '') === 'Hyosec Solutions Private Limited'
            );

            return response()->json([
                'status' => true,
                'is_from_isp' => $isFromISP,
                'ip_info' => $data,
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

