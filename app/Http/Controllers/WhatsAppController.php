<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    private $whatsappPhoneId;
    private $whatsappToken;

    public function __construct()
    {
        $this->whatsappPhoneId = config('app.whatsapp_phone_id');
        $this->whatsappToken = config('app.whatsapp_token');
    }


    public function send(Request $request)
    {
        $validated = $request->validate([
            'to' => 'required|string',
            'type' => 'required|string|in:template,text',
            'template_name' => 'nullable|string',
            'template_params' => 'nullable|array',
            'template_header_document_url' => 'nullable|string',
            'template_header_document_name' => 'nullable|string',
            'template_button_url' => 'nullable|string',
            'language' => 'nullable|string',
            'message' => 'nullable|string',
        ]);

        return $this->dispatchValidatedMessage($validated);
    }

    protected function dispatchValidatedMessage(array $validated)
    {
        $phoneId = $this->whatsappPhoneId;
        $token = $this->whatsappToken;
        $version = config('services.whatsapp.api_version', 'v22.0');

        if (empty($phoneId) || empty($token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'WhatsApp API is not configured.',
            ], 500);
        }

        $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";

        if ($validated['type'] === 'template') {
            if (empty($validated['template_name']) || empty($validated['template_params'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Template name and parameters are required for template messages.'
                ], 400);
            }

            $bodyParameters = [];
            foreach ($validated['template_params'] as $param) {
                $bodyParameters[] = [
                    "type" => "text",
                    "text" => $param
                ];
            }

            $payload = [
                "messaging_product" => "whatsapp",
                "to" => $validated['to'],
                "type" => "template",
                "template" => [
                    "name" => $validated['template_name'],
                    "language" => [
                        "code" => $validated['language'] ?? "en"
                    ],
                    "components" => [
                        [
                            "type" => "body",
                            "parameters" => $bodyParameters
                        ]
                    ]
                ]
            ];

            if (!empty($validated['template_header_document_url'])) {
                $payload['template']['components'][] = [
                    "type" => "header",
                    "parameters" => [
                        [
                            "type" => "document",
                            "document" => [
                                "link" => $validated['template_header_document_url'],
                                "filename" => $validated['template_header_document_name'] ?? 'invoice.pdf',
                            ],
                        ],
                    ],
                ];
            }

            if ($validated['template_name'] === 'zostream_auth_otp') {
                $otp = $validated['template_params'][0] ?? '';
                if (!empty($otp)) {
                    $payload['template']['components'][] = [
                        "type" => "button",
                        "sub_type" => "url",
                        "index" => "0",
                        "parameters" => [
                            [
                                "type" => "text",
                                "text" => $otp
                            ]
                        ]
                    ];
                }
            }

            if (!empty($validated['template_button_url'])) {
                $payload['template']['components'][] = [
                    "type" => "button",
                    "sub_type" => "url",
                    "index" => "0",
                    "parameters" => [
                        [
                            "type" => "text",
                            "text" => $validated['template_button_url']
                        ]
                    ]
                ];
            }
        } else {
            if (empty($validated['message'])) {
                return response()->json([
                'status' => 'error',
                'message' => 'Message field is required for text messages.'
            ], 400);
            }

            $payload = [
                "messaging_product" => "whatsapp",
                "to" => $validated['to'],
                "type" => "text",
                "text" => [
                    "preview_url" => false,
                    "body" => $validated['message']
                ]
            ];
        }

        $response = Http::withToken($token)->post($url, $payload);

        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'message' => ucfirst($validated['type']) . ' message sent successfully.',
                'response' => $response->json()
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to send WhatsApp message.',
            'error' => $response->json()
        ], $response->status());
    }
}
