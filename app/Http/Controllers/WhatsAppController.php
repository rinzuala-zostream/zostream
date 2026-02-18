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
            'type' => 'required|string|in:template,text', // 'template' or 'text'
            'template_name' => 'nullable|string',
            'template_params' => 'nullable|array',
            'language' => 'nullable|string',
            'message' => 'nullable|string', // for text messages
        ]);

        if (empty($this->whatsappPhoneId)) {
            return response()->json(['error' => 'Missing WHATSAPP_PHONE_ID in .env'], 400);
        }

        if (empty($this->whatsappToken)) {
            return response()->json(['error' => 'Missing WHATSAPP_TOKEN in .env'], 400);
        }

        $url = "https://graph.facebook.com/v22.0/197447096794083/messages";
        $token = "EAATU6fjeZAd0BQpeRdLHH60jZCmfg5FMoK3YRxAoEixEb8uxZC8WaAuZAWrxlTecWjSTAfnbuv3zdL3F9LCiuImr3xSbVQyzkoDHcRYWHY7vFZCu67TjWnYCZAaRhdwHaNVJR6C1KjOLpNjBfXUlnsePJDzjZAsZAZAZC8iMfEwdkD10SVmsyRGHZCDciVJAHysnAZDZD";


        // ----------------------------
        // 📩 Build Payload Based on Type
        // ----------------------------
        if ($validated['type'] === 'template') {
            if (empty($validated['template_name']) || empty($validated['template_params'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Template name and parameters are required for template messages.'
                ], 400);
            }

            // 🧩 Build body parameters
            $bodyParameters = [];
            foreach ($validated['template_params'] as $param) {
                $bodyParameters[] = [
                    "type" => "text",
                    "text" => $param
                ];
            }

            // 🧩 Base template payload
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

            // 💡 Auto-add button if template is 'zostream_auth_otp'
            if ($validated['template_name'] === 'zostream_auth_otp') {
                // Take first parameter (OTP) as button param
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

        // ----------------------------
        // 🚀 Send Message via API
        // ----------------------------
        $response = Http::withToken($token)->post($url, $payload);

        // ----------------------------
        // ✅ Handle Response
        // ----------------------------
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
