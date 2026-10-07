<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V4\AccountController;
use App\Http\Controllers\Api\V4\CustomerStatsController;
use App\Models\WebLoginGrant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebStatsController extends Controller
{
    public function consumeAppTicket(Request $request)
    {
        $ticket = (string) $request->query('ticket', '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $ticket)) {
            return redirect('/account/stats?bridge=expired');
        }

        $userId = DB::transaction(function () use ($ticket) {
            $grant = WebLoginGrant::where('ticket_hash', hash('sha256', $ticket))
                ->lockForUpdate()
                ->first();
            if (!$grant || $grant->expires_at->isPast()) {
                if ($grant) $grant->delete();
                return null;
            }

            $userId = $grant->user_id;
            $grant->delete();
            return $userId;
        });

        if (!$userId) {
            return redirect('/account/stats?bridge=expired');
        }

        $request->session()->regenerate();
        $request->session()->put('customer_web_user_id', $userId);

        return redirect('/account/stats?app=1');
    }

    public function data(Request $request, CustomerStatsController $stats, WatchPositionController $history, AccountController $account)
    {
        $userId = (string) $request->session()->get('customer_web_user_id', '');
        if ($userId === '') {
            return response()->json(['message' => 'Sign-in required.'], 401);
        }

        $historyRequest = Request::create('/api/v4/library/history', 'GET', [
            'userId' => $userId,
            'per_page' => 30,
        ]);
        $historyPayload = $history->getWatchContinue($historyRequest)->getData(true);

        $accountRequest = Request::create('/api/v4/account', 'GET');
        $accountRequest->merge(['auth_user_id' => $userId]);
        $subscriptionPayload = $account->subscriptions($accountRequest)->getData(true);
        $devicePayload = $account->devices($accountRequest)->getData(true);

        return response()->json([
            'stats' => $stats->dataForUser($userId),
            'watch_history' => $historyPayload['watch_history'] ?? [],
            'subscriptions' => $subscriptionPayload,
            'devices' => $devicePayload,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('customer_web_user_id');
        $request->session()->regenerateToken();
        return response()->json(['success' => true]);
    }
}
