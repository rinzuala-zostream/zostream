<?php

namespace App\Services;

use App\Http\Controllers\WhatsAppController;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Season;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class InvoiceService
{
    public function __construct(
        private readonly WhatsAppController $whatsAppController
    ) {}

    public function buildInvoiceData(PaymentHistory $payment): array
    {
        $payment->loadMissing(['plan', 'subscription']);

        $user = UserModel::query()
            ->where('uid', $payment->user_id)
            ->first();

        $item = $this->resolveItem($payment);
        $paymentType = strtolower((string) $payment->app_payment_type) === 'ppv'
            ? 'Rent'
            : 'Subscription';

        return [
            'invoice_no' => $this->invoiceNumber($payment),
            'invoice_date' => $payment->payment_date
                ? Carbon::parse($payment->payment_date)
                : Carbon::parse($payment->updated_at ?? now()),
            'customer_name' => $user?->name ?: 'Zo Stream User',
            'customer_phone' => $this->resolvePhone($user),
            'customer_email' => $user?->mail ?: null,
            'customer_address' => $user?->address ?: 'Aizawl',
            'payment_type' => $paymentType,
            'item_name' => $item['name'],
            'item_description' => $item['description'],
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency ?: 'INR',
            'payment_gateway' => $payment->payment_gateway ?: 'N/A',
            'payment_method' => $payment->payment_method ?: $payment->payment_type ?: 'N/A',
            'transaction_id' => $payment->transaction_id ?: 'N/A',
            'valid_till' => $payment->expiry_date ? Carbon::parse($payment->expiry_date) : null,
            'status' => $payment->status ?: 'success',
            'view_url' => $this->invoiceUrl($payment),
        ];
    }

    public function invoiceUrl(PaymentHistory $payment): string
    {
        return URL::signedRoute('invoice.payments.show', ['payment' => $payment->id]);
    }

    public function sendWhatsAppInvoice(PaymentHistory $payment): bool
    {
        if ($payment->status !== 'success') {
            return false;
        }

        $data = $this->buildInvoiceData($payment);

        if ($data['customer_phone'] === '') {
            Log::warning('Invoice WhatsApp skipped: phone not found', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
            ]);
            return false;
        }

        try {
            $response = $this->whatsAppController->send(new Request([
                'to' => $data['customer_phone'],
                'type' => 'template',
                'template_name' => config('app.whatsapp_invoice_template', 'zostream_invoice'),
                'language' => 'en',
                'template_params' => [
                    $data['customer_name'],
                    $data['payment_type'],
                    $data['item_name'],
                    $this->formatMoney($data['amount'], $data['currency']),
                    $data['transaction_id'],
                    $data['valid_till'] ? $data['valid_till']->format('M d, Y') : 'N/A',
                ],
                'template_button_url' => $data['view_url'],
            ]));

            if ($response->getStatusCode() >= 400) {
                Log::warning('Invoice WhatsApp failed', [
                    'payment_id' => $payment->id,
                    'status' => $response->getStatusCode(),
                    'response' => method_exists($response, 'getData') ? $response->getData(true) : null,
                ]);
                return false;
            }

            Log::info('Invoice WhatsApp sent', [
                'payment_id' => $payment->id,
                'invoice_no' => $data['invoice_no'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Invoice WhatsApp exception', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveItem(PaymentHistory $payment): array
    {
        if (strtolower((string) $payment->app_payment_type) !== 'ppv') {
            $planName = $payment->plan?->name ?: 'Zo Stream Plan';
            $deviceType = $payment->plan?->device_type ?: $payment->device_type;

            return [
                'name' => $planName,
                'description' => $deviceType ? 'Subscription for ' . ucfirst((string) $deviceType) : 'Subscription',
            ];
        }

        $movieId = (string) $payment->movie_id;

        $movie = MovieModel::query()->where('id', $movieId)->first();
        if ($movie) {
            return [
                'name' => $movie->title ?: 'PPV Movie',
                'description' => 'Pay-per-view movie rental',
            ];
        }

        $episode = Episode::with('season.movie')->where('id', $movieId)->first();
        if ($episode) {
            $seriesTitle = $episode->season?->movie?->title ?: 'Series';
            return [
                'name' => $seriesTitle . ' - ' . ($episode->title ?: 'Episode'),
                'description' => 'Pay-per-view episode rental',
            ];
        }

        $season = Season::with('movie')->where('id', $movieId)->first();
        if ($season) {
            $seriesTitle = $season->movie?->title ?: 'Series';
            return [
                'name' => $seriesTitle . ' - ' . ($season->title ?: 'Season ' . $season->season_number),
                'description' => 'Pay-per-view season rental',
            ];
        }

        return [
            'name' => 'Zo Stream Rental',
            'description' => 'Pay-per-view rental',
        ];
    }

    private function invoiceNumber(PaymentHistory $payment): string
    {
        return 'INV-' . str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT);
    }

    private function resolvePhone(?UserModel $user): string
    {
        if (! $user) {
            return '';
        }

        $phone = $this->normalizePhone((string) $user->auth_phone);
        $countryCode = $this->normalizePhone((string) $user->country_code);

        if ($phone === '') {
            return '';
        }

        if ($countryCode === '') {
            return $phone;
        }

        $hasCountryCode = str_starts_with($phone, $countryCode) && strlen($phone) > 10;

        return $hasCountryCode ? $phone : $countryCode . $phone;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone));
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $symbol = strtoupper($currency) === 'INR' ? '₹' : strtoupper($currency) . ' ';

        return $symbol . number_format($amount, 2);
    }
}
