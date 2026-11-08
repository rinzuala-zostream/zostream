<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class PaymentMailController extends Controller
{
    public function sendPaymentSuccess(Request $request)
    {
        $mail = new PHPMailer(true);

        try {
            // SMTP configuration
            $mail->isSMTP();
            $mail->Host = 'smtp.hostinger.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'payment@zostream.in';
            $mail->Password = 'Remruata@2024'; // 🔒 You can move this to .env for safety
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;

            // Set sender
            $mail->setFrom('payment@zostream.in', 'Zo Stream Payment');

            // Collect request data safely
            $recipient = filter_var($request->input('recipient', ''), FILTER_SANITIZE_EMAIL);
            $subject = htmlspecialchars($request->input('subject', 'Payment Successful'));
            $paymentAmount = htmlspecialchars($request->input('amount', '0.00'));
            $paymentDate = htmlspecialchars($request->input('date', now()->format('F j, Y H:i:s')));
            $paymentMethod = htmlspecialchars($request->input('method', 'Unknown'));
            $type = htmlspecialchars($request->input('type', 'Unknown'));
            $device = htmlspecialchars($request->input('platform', 'Unknown'));
            $plan = htmlspecialchars($request->input('plan', 'Unknown'));
            $transactionId = htmlspecialchars($request->input('transaction_id', 'N/A'));

            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['status' => 'error', 'message' => 'Invalid recipient email address.'], 400);
            }

            $mail->addAddress($recipient);

            // Load and prepare email template
            $templatePath = resource_path('views/emails/success_template.html');
            if (!file_exists($templatePath)) {
                return response()->json(['status' => 'error', 'message' => 'Email template not found.'], 500);
            }

            $template = file_get_contents($templatePath);

            // Replace placeholders
            $body = str_replace(
                ['{SUBJECT}', '{BODY_CONTENT}', '{PAYMENT_AMOUNT}', '{PAYMENT_DATE}', '{PAYMENT_METHOD}', '{TRANSACTION_ID}', '{TYPE}', '{PLATFORM}', '{PLAN}', '{FOOTER}'],
                [
                    $subject,
                    'We have successfully processed your payment. Below are the payment details:',
                    $paymentAmount,
                    $paymentDate,
                    $paymentMethod,
                    $transactionId,
                    $type,
                    $device,
                    $plan,
                    'Thank you for choosing Zo Stream!'
                ],
                $template
            );

            // Configure and send email
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $mail->send();

            return response()->json(['status' => 'success', 'message' => 'Email sent successfully.']);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email could not be sent. Error: ' . $mail->ErrorInfo
            ], 500);
        }
    }
}
