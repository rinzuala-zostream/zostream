<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FCMNotificationController;
use App\Services\Engagement\EngagementTemplates;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminEngagementController extends Controller
{
    public function index(EngagementTemplates $templates)
    {
        $stats = DB::table('engagement_deliveries')->where('created_at', '>=', now('UTC')->subDays(30))
            ->selectRaw('rule, status, COUNT(*) as total, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, SUM(CASE WHEN played_at IS NOT NULL THEN 1 ELSE 0 END) as played')
            ->groupBy('rule', 'status')->get();

        return V4Response::success([
            'rules' => array_values($templates->all()), 'stats' => $stats,
            'runtime' => ['tracking_enabled' => (bool) config('engagement.tracking_enabled'), 'enabled' => (bool) config('engagement.enabled'),
                'cooldown_hours' => config('engagement.cooldown_hours'), 'quiet_hours' => '21:00–09:00 Asia/Kolkata'],
        ]);
    }

    public function update(Request $request, string $rule, EngagementTemplates $templates)
    {
        abort_unless(array_key_exists($rule, EngagementTemplates::DEFAULTS), 404);
        $values = $request->validate([
            'enabled' => 'required|boolean', 'title_template' => 'required|string|max:200', 'body_template' => 'required|string|max:2000',
        ]);
        foreach (['title_template', 'body_template'] as $field) {
            $values[$field] = trim($values[$field]);
            if ($values[$field] === '' || preg_match('/[{}]/u', str_replace('{title}', '', $values[$field]))) {
                throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Use non-empty text and only the {title} placeholder.']);
            }
        }
        DB::transaction(function () use ($rule, $values) {
            DB::table('engagement_rules')->updateOrInsert(['rule' => $rule], $values + ['updated_at' => now('UTC'), 'created_at' => now('UTC')]);
            if (! $values['enabled']) {
                DB::table('engagement_deliveries')->where('rule', $rule)->whereIn('status', ['pending', 'queued'])
                    ->update(['status' => 'cancelled', 'reason' => 'rule_disabled', 'updated_at' => now('UTC')]);
            }
        });

        return V4Response::success($templates->all()[$rule], 'Smart notification rule saved.');
    }

    public function test(Request $request, EngagementTemplates $templates, FCMNotificationController $push)
    {
        $values = $request->validate([
            'rule' => ['required', Rule::in(array_keys(EngagementTemplates::DEFAULTS))],
            'token' => 'required|string|max:4096', 'movie_id' => 'required|string|max:191',
        ]);
        // An explicit device token is mandatory: test pushes can never become broadcasts.
        $movie = DB::table('movie')->where('id', $values['movie_id'])->where('status', 'Published')->where('isEnable', 1)->first();
        abort_unless($movie, 422, 'Choose a published, enabled movie public ID.');
        $text = $templates->render($values['rule'], $movie->title);
        $result = $push->sendToToken($values['token'], $text['title'], $text['body'], $movie->poster ?? '', (string) $movie->id,
            ['type' => 'smart_engagement_test', 'rule' => $values['rule'], 'movie_id' => (string) $movie->id,
                'content_id' => (string) $movie->id, 'content_type' => 'movie']);
        if (! ($result['success'] ?? false)) {
            return V4Response::error('PUSH_FAILED', 'Test notification could not be sent.', 502);
        }

        return V4Response::success(null, 'Test notification accepted by FCM.');
    }
}
