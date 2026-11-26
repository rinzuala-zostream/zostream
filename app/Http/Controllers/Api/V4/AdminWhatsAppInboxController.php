<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppCloudService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminWhatsAppInboxController extends Controller
{
    public function settings(Request $request)
    {
        $settings = WhatsAppSetting::current();

        return response()->json([
            'auto_reply_enabled' => $settings->auto_reply_enabled,
            'auto_reply_message' => $settings->auto_reply_message,
            'has_verify_token' => filled($settings->verify_token),
            'webhook_url' => url('/api/v4/webhooks/whatsapp'),
            'server_credentials_configured' => filled(config('app.whatsapp_phone_id'))
                && filled(config('app.whatsapp_token')),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            'verify_token' => 'nullable|string|max:500',
            'auto_reply_enabled' => 'required|boolean',
            'auto_reply_message' => 'nullable|string|max:4096',
        ]);

        $settings = WhatsAppSetting::current();
        if (blank($validated['verify_token'] ?? null)) {
            unset($validated['verify_token']);
        }
        $settings->update($validated);

        return $this->settings($request);
    }

    public function generateVerifyToken(Request $request)
    {
        $token = Str::random(64);
        WhatsAppSetting::current()->update(['verify_token' => $token]);

        return response()->json([
            'verify_token' => $token,
            'message' => 'Copy this token now. It will not be shown again.',
        ]);
    }

    public function conversations(Request $request)
    {
        $latestMessageId = DB::table('whatsapp_messages as candidate')
            ->select('candidate.id')
            ->whereColumn('candidate.contact_phone', 'summary.contact_phone')
            ->orderByDesc('candidate.message_at')
            ->orderByDesc('candidate.id')
            ->limit(1);
        $latestContactName = DB::table('whatsapp_messages as named')
            ->select('named.contact_name')
            ->whereColumn('named.contact_phone', 'summary.contact_phone')
            ->whereNotNull('named.contact_name')
            ->orderByDesc('named.message_at')
            ->orderByDesc('named.id')
            ->limit(1);
        $summaries = DB::table('whatsapp_messages as summary')
            ->select('summary.contact_phone')
            ->selectRaw('COUNT(*) as message_count')
            ->selectRaw("SUM(CASE WHEN summary.direction = 'inbound' AND summary.read_at IS NULL THEN 1 ELSE 0 END) as unread_count")
            ->selectSub($latestMessageId, 'latest_message_id')
            ->selectSub($latestContactName, 'contact_name')
            ->groupBy('summary.contact_phone');

        $rows = DB::query()->fromSub($summaries, 'conversations')
            ->join('whatsapp_messages as latest', 'latest.id', '=', 'conversations.latest_message_id')
            ->orderByDesc('latest.message_at')
            ->orderByDesc('latest.id')
            ->get([
                'latest.*',
                'conversations.contact_name as conversation_name',
                'conversations.message_count',
                'conversations.unread_count',
            ]);

        // Hydrate only one message per contact so payload casts and preview
        // behavior remain identical without loading the full conversation log.
        $messages = $rows->map(function ($row) {
            $message = new WhatsAppMessage();
            $message->setRawAttributes((array) $row, true);
            $message->setAttribute('contact_name', $row->conversation_name);

            return [
                'message' => $message,
                'message_count' => (int) $row->message_count,
                'unread_count' => (int) $row->unread_count,
            ];
        })->map(function (array $row) {
            /** @var WhatsAppMessage $latest */
            $latest = $row['message'];

            return [
                'phone' => $latest->contact_phone,
                'name' => $latest->contact_name,
                'last_message' => $this->conversationPreview($latest),
                'last_message_at' => $latest->message_at,
                'direction' => $latest->direction,
                'status' => $latest->status,
                'message_count' => $row['message_count'],
                'unread_count' => $row['unread_count'],
            ];
        })->values();

        return response()->json($messages);
    }

    public function messages(string $phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);

        // Keep the legacy full-history response intact until pagination can be
        // introduced through a separately versioned/opt-in contract.
        return response()->json(
            WhatsAppMessage::where('contact_phone', $phone)
                ->orderBy('message_at')
                ->orderBy('id')
                ->get()
        );
    }

    public function markRead(string $phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);
        if ($phone === '') {
            return response()->json(['message' => 'A valid phone number is required.'], 422);
        }

        $readAt = now();
        $readCount = WhatsAppMessage::where('contact_phone', $phone)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);

        return response()->json([
            'phone' => $phone,
            'read_count' => $readCount,
            'read_at' => $readAt,
        ]);
    }

    public function media(WhatsAppMessage $message, WhatsAppCloudService $whatsApp)
    {
        if (! in_array($message->type, ['image', 'document'], true)) {
            return response()->json(['message' => 'This message does not contain supported media.'], 404);
        }

        $mediaId = (string) data_get($message->payload, "{$message->type}.id", '');
        if ($mediaId === '') {
            return response()->json(['message' => 'WhatsApp media ID is missing.'], 404);
        }

        try {
            $media = $whatsApp->downloadMedia($mediaId);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $filename = (string) data_get($message->payload, 'document.filename', "whatsapp-{$message->id}");
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename)) ?: "whatsapp-{$message->id}";

        return response($media['content'], 200, [
            'Content-Type' => $media['mime_type'],
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reply(Request $request, WhatsAppCloudService $whatsApp)
    {
        $validated = $request->validate([
            'to' => 'required|string|max:40',
            'message' => 'required|string|max:4096',
        ]);

        $message = $whatsApp->sendText(
            $validated['to'],
            $validated['message']
        );

        return response()->json($message, 201);
    }

    private function conversationPreview(WhatsAppMessage $message): ?string
    {
        if ($message->type === 'image') {
            return $message->body && $message->body !== '[image]' ? 'Image: '.$message->body : 'Image';
        }

        if ($message->type === 'document') {
            return 'Document: '.data_get($message->payload, 'document.filename', 'File');
        }

        return $message->body;
    }
}
