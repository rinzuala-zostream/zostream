<?php

namespace Tests\Unit;

use App\Http\Controllers\FCMNotificationController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NotificationImagePayloadTest extends TestCase
{
    public function test_topic_poster_enables_ios_attachment_and_preserves_android_and_title_routing(): void
    {
        $message = $this->message(null, 'all', 'https://cdn.example.com/poster.webp', 'movie-123');

        $this->assertSame('all', $message['topic']);
        $this->assertSame('New movie', $message['notification']['title']);
        $this->assertSame('Streaming now', $message['notification']['body']);
        $this->assertSame('https://cdn.example.com/poster.webp', $message['notification']['image']);
        $this->assertSame('https://cdn.example.com/poster.webp', $message['apns']['fcm_options']['image']);
        $this->assertSame(1, $message['apns']['payload']['aps']['mutable-content']);
        $this->assertSame('default', $message['apns']['payload']['aps']['sound']);
        $this->assertSame('high', $message['android']['priority']);
        $this->assertSame('zostream_notifications', $message['android']['notification']['channel_id']);
        $this->assertSame('PRIORITY_HIGH', $message['android']['notification']['notification_priority']);
        $this->assertSame('default', $message['android']['notification']['sound']);
        $this->assertSame('movie-123', $message['data']['key']);
    }

    public function test_direct_invitation_keeps_recipient_and_data_with_an_image(): void
    {
        $message = $this->message('recipient-token', null, 'https://cdn.example.com/thumb.jpg', null, [
            'type' => 'chat_invite', 'invite_token' => 'invite-123', 'count' => 2,
        ]);

        $this->assertSame('recipient-token', $message['token']);
        $this->assertArrayNotHasKey('topic', $message);
        $this->assertSame('chat_invite', $message['data']['type']);
        $this->assertSame('invite-123', $message['data']['invite_token']);
        $this->assertSame('2', $message['data']['count']);
        $this->assertSame(1, $message['apns']['payload']['aps']['mutable-content']);
    }

    public function test_missing_image_uses_plain_notification_without_running_the_extension(): void
    {
        foreach ([null, ''] as $image) {
            $message = $this->message(null, null, $image, 'episode-parent');
            $this->assertSame('all', $message['topic']);
            $this->assertArrayNotHasKey('image', $message['notification']);
            $this->assertArrayNotHasKey('fcm_options', $message['apns']);
            $this->assertArrayNotHasKey('mutable-content', $message['apns']['payload']['aps']);
            $this->assertSame('episode-parent', $message['data']['key']);
        }
    }

    public function test_chat_notifications_use_the_channels_registered_by_android(): void
    {
        foreach ([
            'chat_invite' => 'chat_invitations',
            'chat_message' => 'private_chat_messages',
            'chat_activity' => 'private_chat_messages',
        ] as $key => $channel) {
            $message = $this->message('recipient-token', null, null, $key);
            $this->assertSame($channel, $message['android']['notification']['channel_id']);
        }
    }

    private function message(?string $token, ?string $topic, ?string $image, ?string $key, array $data = []): array
    {
        $method = new ReflectionMethod(FCMNotificationController::class, 'buildMessage');
        $message = $method->invoke(new FCMNotificationController, $token, $topic, 'New movie', 'Streaming now', $image, $key, $data);

        return json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
