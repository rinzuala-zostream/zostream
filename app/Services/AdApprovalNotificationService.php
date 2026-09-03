<?php

namespace App\Services;

use App\Models\AdSubmission;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AdApprovalNotificationService
{
    public function __construct(private readonly WhatsAppCloudService $whatsApp) {}

    public function sendPaymentLink(AdSubmission $submission): bool
    {
        $submission->loadMissing(['user', 'campaign.invoices']);
        $user = $submission->user;

        if (! $user || blank($user->auth_phone)) {
            return $this->failed($submission, 'The submitting user has no WhatsApp auth_phone.');
        }

        $invoice = $submission->campaign?->invoices->sortByDesc('id')->first();
        if (! $invoice) {
            return $this->failed($submission, 'The campaign invoice is not available.');
        }

        try {
            $token = Crypt::decryptString((string) $submission->public_token_encrypted);
        } catch (Throwable) {
            return $this->failed($submission, 'The payment-link token could not be read.');
        }

        $paymentUrl = url('/advertise/payment/'.$token);
        $phone = $this->fullPhone((string) $user->auth_phone, (string) $user->country_code);
        $amount = number_format((float) $invoice->total, 2, '.', '');
        $template = trim((string) config('ads.payment_whatsapp_template', ''));

        try {
            if ($template !== '') {
                $this->whatsApp->sendTemplate(
                    $phone,
                    $template,
                    [$submission->reference_no, $amount.' '.$invoice->currency, $paymentUrl],
                    $token,
                    (string) config('ads.payment_whatsapp_template_language', 'en'),
                );
            } else {
                $this->whatsApp->sendText(
                    $phone,
                    "Zo Stream Ads: I ad {$submission->reference_no} chu approve a ni. "
                    ."Campaign activate turin {$amount} {$invoice->currency} pay rawh: {$paymentUrl}",
                );
            }

            $submission->forceFill([
                'approval_whatsapp_sent_at' => now(),
                'approval_whatsapp_error' => null,
            ])->save();
            $submission->events()->create([
                'action' => 'payment_link_sent',
                'from_status' => AdSubmission::STATUS_APPROVED,
                'to_status' => AdSubmission::STATUS_APPROVED,
                'note' => 'Payment link sent to the submitting user WhatsApp number.',
                'actor_type' => 'system',
                'actor_id' => 'whatsapp',
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Ad approval WhatsApp payment link failed.', [
                'submission_id' => $submission->id,
                'user_id' => $submission->user_id,
                'error' => $exception->getMessage(),
            ]);

            return $this->failed($submission, $exception->getMessage());
        }
    }

    private function failed(AdSubmission $submission, string $message): bool
    {
        $submission->forceFill([
            'approval_whatsapp_sent_at' => null,
            'approval_whatsapp_error' => Str::limit($message, 2000),
        ])->save();

        return false;
    }

    private function fullPhone(string $phone, string $countryCode): string
    {
        $phone = preg_replace('/\D+/', '', $phone);
        $countryCode = preg_replace('/\D+/', '', $countryCode)
            ?: preg_replace('/\D+/', '', (string) config('ads.default_country_code', '91'));

        return $countryCode !== '' && ! str_starts_with($phone, $countryCode)
            ? $countryCode.$phone
            : $phone;
    }
}
