<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FCMNotificationController extends Controller
{
    public function send(Request $request): array
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:2000',
            'image' => 'nullable|url|max:2048',
            'token' => 'nullable|string|max:4096',
            'topic' => 'nullable|string|max:900',
            'key' => 'nullable|string|max:255',
            'data' => 'nullable|array',
        ]);

        return $this->sendNotification(
            token: $validated['token'] ?? null,
            topic: $validated['topic'] ?? 'all',
            title: $validated['title'] ?? '',
            body: $validated['body'] ?? '',
            image: $validated['image'] ?? null,
            key: $validated['key'] ?? null,
            data: $validated['data'] ?? [],
        );
    }

    public function sendToToken(
        $token,
        $title,
        $body,
        $image = '',
        $key = null,
        array $data = [],
    ): array {
        return $this->sendNotification(
            token: (string) $token,
            topic: null,
            title: (string) $title,
            body: (string) $body,
            image: $image ? (string) $image : null,
            key: $key !== null ? (string) $key : null,
            data: $data,
        );
    }

    public function sendToTopic(
        $topic,
        $title,
        $body,
        $image = '',
        $key = null,
        array $data = [],
    ): array {
        return $this->sendNotification(
            token: null,
            topic: (string) $topic,
            title: (string) $title,
            body: (string) $body,
            image: $image ? (string) $image : null,
            key: $key !== null ? (string) $key : null,
            data: $data,
        );
    }

    private function sendNotification(
        ?string $token,
        ?string $topic,
        string $title,
        string $body,
        ?string $image,
        ?string $key,
        array $data,
    ): array {
        try {
            $credentials = (string) config('firebase.credentials', '');
            if ($credentials === '' || ! is_file($credentials)) {
                throw new \RuntimeException('Firebase credentials are not configured.');
            }

            $messageData = [];
            foreach ($data as $dataKey => $dataValue) {
                if (
                    $dataValue !== null
                    && ! is_array($dataValue)
                    && ! is_object($dataValue)
                ) {
                    $messageData[(string) $dataKey] = (string) $dataValue;
                }
            }

            if ($key !== null && $key !== '') {
                $messageData['key'] = $key;
            }

            $notification = Notification::create($title, $body);
            if ($image !== null && $image !== '') {
                $notification = $notification->withImageUrl($image);
            }

            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withAndroidConfig([
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'color' => '#f45342',
                    ],
                ])
                ->withApnsConfig([
                    'headers' => ['apns-priority' => '10'],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,
                        ],
                    ],
                ]);

            if ($messageData !== []) {
                $message = $message->withData($messageData);
            }

            $message = $token !== null && $token !== ''
                ? $message->withToken($token)
                : $message->withTopic($topic ?: 'all');

            $result = (new Factory)
                ->withServiceAccount($credentials)
                ->createMessaging()
                ->send($message);

            return [
                'success' => true,
                'status' => 200,
                'body' => $result,
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'success' => false,
                'status' => 500,
                'error' => 'Notification delivery failed.',
            ];
        }
    }
}
