<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppCloudService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $settings = WhatsAppSetting::current();
        $expected = $settings->verify_token ?: config('services.whatsapp.verify_token');
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode === 'subscribe' && $expected && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Webhook verification failed.', 403)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppCloudService $whatsApp): Response
    {
        $settings = WhatsAppSetting::current();

        if (! $this->validSignature($request, config('services.whatsapp.app_secret'))) {
            return response('Invalid signature.', 401);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);
                $this->storeStatuses((array) ($value['statuses'] ?? []));
                $contactNames = collect((array) ($value['contacts'] ?? []))
                    ->mapWithKeys(fn (array $contact) => [
                        (string) ($contact['wa_id'] ?? '') => data_get($contact, 'profile.name'),
                    ]);

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $phone = preg_replace('/\D+/', '', (string) ($message['from'] ?? ''));
                    if ($phone === '') {
                        continue;
                    }

                    $stored = WhatsAppMessage::firstOrCreate(
                        ['wamid' => (string) ($message['id'] ?? '')],
                        [
                            'contact_phone' => $phone,
                            'contact_name' => $contactNames->get($phone),
                            'direction' => 'inbound',
                            'type' => (string) ($message['type'] ?? 'unknown'),
                            'body' => $this->messageBody($message),
                            'status' => 'received',
                            'reply_to_wamid' => data_get($message, 'context.id'),
                            'payload' => $message,
                            'message_at' => isset($message['timestamp'])
                                ? now()->setTimestamp((int) $message['timestamp'])
                                : now(),
                        ]
                    );

                    if (
                        $stored->wasRecentlyCreated
                        && $settings->auto_reply_enabled
                        && filled($settings->auto_reply_message)
                    ) {
                        try {
                            $whatsApp->sendText($phone, $settings->auto_reply_message);
                        } catch (Throwable $exception) {
                            Log::warning('WhatsApp auto reply failed', [
                                'message_id' => $stored->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }

    private function validSignature(Request $request, ?string $appSecret): bool
    {
        if (! $appSecret) {
            return true;
        }

        $provided = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function storeStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            $wamid = (string) ($status['id'] ?? '');
            if ($wamid === '') {
                continue;
            }

            WhatsAppMessage::where('wamid', $wamid)->update([
                'status' => (string) ($status['status'] ?? 'unknown'),
                'payload' => $status,
            ]);
        }
    }

    private function messageBody(array $message): string
    {
        return (string) (
            data_get($message, 'text.body')
            ?? data_get($message, 'button.text')
            ?? data_get($message, 'interactive.button_reply.title')
            ?? data_get($message, 'interactive.list_reply.title')
            ?? data_get($message, 'image.caption')
            ?? data_get($message, 'document.caption')
            ?? '['.($message['type'] ?? 'message').']'
        );
    }
}
