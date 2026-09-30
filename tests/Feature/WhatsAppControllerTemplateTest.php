<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppControllerTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.whatsapp_phone_id' => 'phone-id',
            'app.whatsapp_token' => 'access-token',
            'services.whatsapp.api_version' => 'v22.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v22.0/phone-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.template']],
            ]),
        ]);
    }

    public function test_it_sends_named_template_parameters_with_their_parameter_names(): void
    {
        $response = app(WhatsAppController::class)->send(new Request([
            'to' => '919876543210',
            'type' => 'template',
            'template_name' => 'zostream_wifi_reminder',
            'language' => 'en',
            'template_params' => [
                'customer_name' => 'Test Customer',
                'expiry_date' => '01 Oct 2026',
                'amount' => '599.00',
                'plan_name' => 'Home 50 Mbps',
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'template.components.0.parameters') === [
                ['type' => 'text', 'text' => 'Test Customer', 'parameter_name' => 'customer_name'],
                ['type' => 'text', 'text' => '01 Oct 2026', 'parameter_name' => 'expiry_date'],
                ['type' => 'text', 'text' => '599.00', 'parameter_name' => 'amount'],
                ['type' => 'text', 'text' => 'Home 50 Mbps', 'parameter_name' => 'plan_name'],
            ];
        });
    }

    public function test_it_keeps_existing_positional_template_parameters_unchanged(): void
    {
        $response = app(WhatsAppController::class)->send(new Request([
            'to' => '919876543210',
            'type' => 'template',
            'template_name' => 'existing_template',
            'language' => 'en',
            'template_params' => ['First', 'Second'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'template.components.0.parameters') === [
                ['type' => 'text', 'text' => 'First'],
                ['type' => 'text', 'text' => 'Second'],
            ];
        });
    }
}
