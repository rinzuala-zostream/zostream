<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngagementPreferenceController extends Controller
{
    public function show(Request $request)
    {
        $user = DB::table('engagement_users')->where('user_id', (string) $request->input('auth_user_id'))->first();

        return V4Response::success([
            'enabled' => $user ? (bool) $user->enabled : true,
            'content_mode' => $user->content_mode ?? 'kids',
            'quiet_hours' => ['start' => '21:00', 'end' => '09:00', 'timezone' => config('engagement.timezone')],
        ]);
    }

    public function update(Request $request)
    {
        $values = $request->validate(['enabled' => 'sometimes|required|boolean', 'content_mode' => 'sometimes|required|in:kids,adult']);
        $userId = (string) $request->input('auth_user_id');
        DB::table('engagement_users')->insertOrIgnore([
            'user_id' => $userId, 'next_check_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('engagement_users')->where('user_id', $userId)->update($values + ['updated_at' => now('UTC')]);
        if (array_key_exists('enabled', $values) && ! $values['enabled']) {
            DB::table('engagement_deliveries')->where('user_id', $userId)->whereIn('status', ['pending', 'queued'])
                ->update(['status' => 'cancelled', 'reason' => 'opted_out', 'updated_at' => now('UTC')]);
        }

        return $this->show($request);
    }
}
