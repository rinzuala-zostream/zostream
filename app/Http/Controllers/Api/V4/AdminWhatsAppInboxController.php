<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppCloudService;
use Illuminate\Http\Request;
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
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $messages = WhatsAppMessage::query()
            ->orderByDesc('message_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('contact_phone')
            ->map(function ($rows) {
                $latest = $rows->first();
                return [
                    'phone' => $latest->contact_phone,
                    'name' => $rows->firstWhere('contact_name', '!=', null)?->contact_name,
                    'last_message' => $this->conversationPreview($latest),
                    'last_message_at' => $latest->message_at,
                    'direction' => $latest->direction,
                    'status' => $latest->status,
                    'message_count' => $rows->count(),
                ];
            })
            ->values()
            ->take($limit);

        return response()->json($messages);
    }

    public function messages(string $phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);

        return response()->json(
            WhatsAppMessage::where('contact_phone', $phone)
                ->orderBy('message_at')
                ->orderBy('id')
                ->limit(300)
                ->get()
        );
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
