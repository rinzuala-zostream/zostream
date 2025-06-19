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

            return response()->json([
                    'status' => true,
                    'message' => $data,
                ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'ISP check failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
