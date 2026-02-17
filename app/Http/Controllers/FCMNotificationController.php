<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;

class FCMNotificationController extends Controller
{
    public function send(Request $request)
    {
        // 🔐 Load Firebase service account credentials
        $serviceAccountData = [
            "type" => "service_account",
            "project_id" => "zo-stream-f04ea",
            "private_key_id" => "7e7b5d2c16afe42a7bcc04b28e8d508b00625e76",
            "private_key" => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC31F8nqsJEURyI\nubL857Ii0XL+Ut1PyzAGK4bYUxAt1bgAf7OZHIQHSSZ1o9et62MFIB8E7bcfnmoD\nhHoirmo/gXh4VyelGjbC6XIp2ZHat/xGh+iCLs7rGIOX3wYTjOs5npn2g/WMabDp\n4IOUcVf4rSxPPEdvG75Ef83k3Np09vY8tOm85jxh3j6nmqLcsQ9M3qRICrfzl0CS\nL2aWhSKFJzKJZBYmyOWhRaaYCGPseMVwLNWoqm122ldq4oitjm9/yv/xOd27F4Qw\nc4FfsUyWPynyHCqVoBPGRqRvUvQQf1GqCAu+SPoOsYt75ueVxdoYBnrR61GnzJDu\nPmPb4dL/AgMBAAECggEACiX9QtFYfDEGJD4sNW4NFYL+mC+27ArJke2hOhwLzpv3\n1n82SQOb/lL5fpEW/RD7nHLTg5AkBejW7W7I11VNpEffgLU/CQxTbZs5pDnQpYR2\ntuYV3en7nlryGNZFHZsv+TRaR5OtYJ0NGTw9x1oigyX8RjuLrgSYEmwDz9ipbr+D\nf/55JaCa7e2xTTN5KiYeGqZsgFQVFXrhQSGdbGnUzSP6x5Y9jLO12lYKErSihDu6\nsLVnSSRw1nJOYXQ+0bk8uYLnPA/QW6R/l+ZdWKKo73vAkG5oLppl1TA6BJABK8PW\nYhek900U3ibkYYV/VVrxLRQHZHR3zMVv/MggOrkqIQKBgQDpkRvBCtIjil636r/K\nkt+oJjY7ZGWW2TKQ4LdRIcfaJzmNhN+HNKkbCgnesjkwOYHmmvdcvXznT+PJ8gw2\n/ZJ8Cb4IiCVk6sSbCK40+GLSAmRVMhAJbxKJvCdttK9LTrfUl3AbLRZaJSyQQgHP\nJMSz+LuSL/lHp8D/BCvrGcNu1wKBgQDJfFWTY/reN4rm54mW73DfPhynPnTF22v+\nO6rZKzRaIQWQ9Aepe97MAKtoLls3CLRvSc9w0E80t+9G34hTPw0vIJolMWP5NL0Z\nzucIQ6T5gYz+oCNLZFqQJ2lIOJXzfjSV+/MAER+mVadU6sv8pOu+G4cpIQkWgd47\nSrf46RQAGQKBgCbbbEmeWj1tbLqeRFAYRTs9ODKDTl9dPQtbR0QpIY2KjwmbPHDK\n8wM7lU7GSbtbJeBOka6NG7WD1fqn2R5g6zjVihbzR31VjWXZeNn5JL+ZhEWkMYTQ\nRL5DXi/jKnKV4wFsPEtZIenXW2WYhaKHlG34iIQWlRs1rmb+s6vGOnw7AoGAD8yb\nHUKiwlgSoUaYqGhALpE9R/QCzh0Fm9rr67mSklqyiApKq4SWFOMcjb/M0UTyeSON\ni6gZ/eVKcwFGPFjeXMquq6nyz/DNvz9VKHW9cv8woirGebv1ygX9IHencn97+iLW\njDPLiox+4Y7DzhzUi4S3FYeMoeIvHfEe+fq04ckCgYEAji8sULq1XcafEummVX+M\n611HqCVlBBZnF95nCfuac7+ILRNPCPJrSuW9absNJxr8TVAU3nhLrusrRazqO+FA\nDjmQpZxwrHIIsG234eLmqLTpJGeS+qPhrxyjFcOUGjXl2K5b/yiRPWFrygxKyol5\nTdg5ES2YxldEz4XyXjz3HtU=\n-----END PRIVATE KEY-----\n",
            "client_email" => "firebase-adminsdk-vuhvt@zo-stream-f04ea.iam.gserviceaccount.com",
            "client_id" => "105043771239722656350",
            "auth_uri" => "https://accounts.google.com/o/oauth2/auth",
            "token_uri" => "https://oauth2.googleapis.com/token",
            "auth_provider_x509_cert_url" => "https://www.googleapis.com/oauth2/v1/certs",
            "client_x509_cert_url" => "https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-vuhvt%40zo-stream-f04ea.iam.gserviceaccount.com"
        ];

        // 📨 Inputs
        $title = $request->input('title', '');
        $body  = $request->input('body', '');
        $image = $request->input('image', '');
        $token = $request->input('token');   // 🔹 Device token
        $topic = $request->input('topic', 'all'); // 🔹 Fallback to topic “all”
        $key   = $request->input('key');     // 🔹 Optional custom key/data

        // 🔑 Access Token
        $accessToken = $this->getAccessToken($serviceAccountData);

        // 🚀 Send Notification
        $response = $this->sendNotification($accessToken, $title, $body, $image, $token, $topic, $key);

        return response()->json($response);
    }

    private function getAccessToken($serviceAccountData)
    {
        $client = new Client();
        $url = "https://oauth2.googleapis.com/token";

        $now = time();
        $expires = $now + 3600;
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $claimSet = [
            'iss' => $serviceAccountData['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $serviceAccountData['token_uri'],
            'iat' => $now,
            'exp' => $expires,
        ];

        $jwt = $this->base64UrlEncode(json_encode($header)) . '.' . $this->base64UrlEncode(json_encode($claimSet));
        $privateKey = openssl_pkey_get_private($serviceAccountData['private_key']);
        openssl_sign($jwt, $signature, $privateKey, 'sha256');
        $jwt .= '.' . $this->base64UrlEncode($signature);

        $response = $client->post($url, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['access_token'];
    }

    private function sendNotification($accessToken, $title, $body, $image, $token = null, $topic = 'all', $key = null)
    {
        $client = new Client();
        $url = 'https://fcm.googleapis.com/v1/projects/zo-stream-f04ea/messages:send';

        // 🔹 Base Notification Payload
        $message = [
            "message" => [
                "notification" => [
                    "title" => $title,
                    "body"  => $body,
                    "image" => $image,
                ],
                "android" => [
                    "priority" => "high",
                    "notification" => [
                        "sound" => "default",
                        "color" => "#f45342",
                    ],
                ],
                "apns" => [
                    "headers" => ["apns-priority" => "10"],
                    "payload" => [
                        "aps" => [
                            "alert" => ["title" => $title, "body" => $body],
                            "sound" => "default",
                            "badge" => 1,
                            "content-available" => 1,
                        ],
                    ],
                ],
            ],
        ];

        // ✅ Target logic
        if (!empty($token)) {
            $message["message"]["token"] = $token;
        } else {
            $message["message"]["topic"] = $topic;
        }

        // ✅ Add custom key (data payload)
        if (!empty($key)) {
            $message["message"]["data"] = [
                "key" => $key,
            ];
        }

        // 📨 Send Request
        $response = $client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($message),
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
