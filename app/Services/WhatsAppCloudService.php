<?php

namespace App\Services;

use App\Models\WhatsAppMessage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudService
{
    public function sendText(string $to, string $message): WhatsAppMessage
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $to),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ];

        $response = $this->postMessage($payload);
        $wamid = data_get($response->json(), 'messages.0.id');

        return WhatsAppMessage::create([
            'wamid' => $wamid,
            'contact_phone' => $payload['to'],
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $message,
            'status' => $wamid ? 'accepted' : 'sent',
            'payload' => $response->json(),
            'message_at' => now(),
        ]);
    }

    public function postMessage(array $payload): Response
    {
        $phoneNumberId = config('app.whatsapp_phone_id');
        $token = config('app.whatsapp_token');
        $version = config('services.whatsapp.api_version', 'v22.0');

        if (! $phoneNumberId || ! $token) {
            throw new RuntimeException('WHATSAPP_PHONE_ID or WHATSAPP_TOKEN is missing from the server environment.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                data_get($response->json(), 'error.message', 'WhatsApp rejected the message.')
            );
        }

        return $response;
    }
}
