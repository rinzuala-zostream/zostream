<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\WebLoginGrant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebSessionController extends Controller
{
    public function create(Request $request)
    {
        WebLoginGrant::where('expires_at', '<', now())->delete();
        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        WebLoginGrant::create([
            'ticket_hash' => hash('sha256', $ticket),
            'user_id' => (string) $request->input('auth_user_id'),
            'device_id' => $request->input('auth_device_id'),
            'expires_at' => now()->addSeconds(90),
        ]);

        $base = rtrim((string) config('app.url'), '/');
        $url = $base.'/account/stats/bridge?ticket='.rawurlencode($ticket);

        return response()->json([
            'success' => true,
            'data' => ['url' => $url, 'expires_in' => 90],
        ]);
    }

}
