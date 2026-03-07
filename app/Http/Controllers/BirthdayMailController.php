<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class BirthdayMailController extends Controller
{
    public function send(Request $request)
    {
        $request->validate([
            'recipient' => 'required|email',
            'subject'   => 'required|string',
            'body'      => 'required|string',
        ]);

        $recipient = $request->input('recipient');
        $subject   = $request->input('subject');
        $bodyText  = $request->input('body');
        
        $templatePath = resource_path('views/mails/email_template.html');

        if (!file_exists($templatePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email template not found',
            ], 500);
        }

        $template = file_get_contents($templatePath);
        $bodyHtml = str_replace('{BODY_CONTENT}', nl2br(e($bodyText)), $template);

        // Initialize PHPMailer
        $mail = new PHPMailer(true);

        try {
            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host       = 'smtp.hostinger.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'support@zostream.in';
            $mail->Password   = 'Remruata@2024'; // ⚠️ Move this to .env (see below)
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            // From/To
            $mail->setFrom('support@zostream.in', 'Zo Stream Support');
            $mail->addAddress($recipient);

            // Email Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;

            $mail->send();

            return response()->json([
                'status' => 'success',
                'message' => '🎉 Birthday email sent successfully!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send email',
                'error' => $mail->ErrorInfo,
            ], 500);
        }
    }
}
