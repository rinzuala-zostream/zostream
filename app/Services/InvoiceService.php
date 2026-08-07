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
use Illuminate\Support\Str;

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
        $meta = is_array($payment->meta) ? $payment->meta : [];
        $paymentType = strtolower((string) $payment->app_payment_type) === 'ppv'
            ? 'Rent'
            : 'Subscription';
        $deviceLabel = $this->resolveDeviceLabel($payment);

        $invoiceDate = $payment->payment_date
            ? Carbon::parse($payment->payment_date)
            : Carbon::parse($payment->updated_at ?? now());
        $dueDate = $payment->expiry_date ? Carbon::parse($payment->expiry_date) : null;
        $invoiceAmount = $this->isZoStreamWifiPayment($payment)
            && is_numeric($meta['actual_amount'] ?? null)
                ? (float) $meta['actual_amount']
                : (float) $payment->amount;

        return [
            'invoice_no' => $this->invoiceNumber($payment),
            'invoice_date' => $invoiceDate,
            'customer_name' => $user?->name ?: ($meta['name'] ?? 'Zo Stream User'),
            'customer_phone' => $this->resolvePhone($user),
            'customer_email' => $user?->mail ?: null,
            'customer_address' => $user?->address ?: 'Aizawl',
            'payment_type' => $paymentType,
            'access_type' => $paymentType,
            'device_type' => $this->resolveDeviceType($payment),
            'device_label' => $deviceLabel,
            'item_name' => $item['name'],
            'item_description' => $item['description'],
            'amount' => $invoiceAmount,
            'currency' => $payment->currency ?: 'INR',
            'payment_gateway' => $payment->payment_gateway ?: 'N/A',
            'payment_method' => $payment->payment_method ?: $payment->payment_type ?: 'N/A',
            'transaction_id' => $payment->transaction_id ?: 'N/A',
            'valid_till' => $dueDate,
            'billing_period' => $dueDate
                ? $invoiceDate->format('M d, Y').' - '.$dueDate->format('M d, Y')
                : $invoiceDate->format('M d, Y'),
            'due_date' => $dueDate,
            'status' => $payment->status ?: 'success',
            'view_url' => $this->invoiceUrl($payment),
            'pdf_url' => $this->invoicePdfUrl($payment),
        ];
    }

    public function invoiceUrl(PaymentHistory $payment): string
    {
        return URL::signedRoute('invoice.payments.show', ['payment' => $payment->id]);
    }

    public function invoicePdfUrl(PaymentHistory $payment): string
    {
        return URL::signedRoute('invoice.payments.pdf', ['payment' => $payment->id]);
    }

    public function sendWhatsAppInvoice(PaymentHistory $payment): bool
    {
        if ($payment->status !== 'success') {
            return false;
        }

        if ($this->invoiceWhatsAppAlreadySent($payment)) {
            Log::info('Invoice WhatsApp skipped: already sent', [
                'payment_id' => $payment->id,
                'invoice_sent_at' => $payment->meta['invoice_whatsapp_sent_at'] ?? null,
            ]);

            return true;
        }

        $data = $this->buildInvoiceData($payment);
        $isWifiInvoice = $this->isZoStreamWifiPayment($payment);
        $templateName = $isWifiInvoice
            ? 'zostream_wifi_invoice'
            : config('app.whatsapp_invoice_template', 'zostream_invoice');

        if ($data['customer_phone'] === '') {
            Log::warning('Invoice WhatsApp skipped: phone not found', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
            ]);

            return false;
        }

        try {
            $payload = [
                'to' => $data['customer_phone'],
                'type' => 'template',
                'template_name' => $templateName,
                'language' => 'en',
            ];

            if ($isWifiInvoice) {
                $payload['template_params'] = [
                    $data['customer_name'],
                    $data['invoice_no'],
                    $data['billing_period'],
                    number_format($data['amount'], 2, '.', ''),
                    $data['due_date'] ? $data['due_date']->format('M d, Y') : 'N/A',
                ];
                $buttonParameter = $this->invoiceButtonParameter(
                    $data['view_url'],
                    (string) config('app.whatsapp_wifi_invoice_button_parameter', 'path')
                );
            } else {
                $payload['template_header_document_url'] = $data['pdf_url'];
                $payload['template_header_document_name'] = $data['invoice_no'].'.pdf';
                $payload['template_params'] = [
                    $data['customer_name'],
                    $data['payment_type'],
                    $data['item_name'],
                    $this->formatMoney($data['amount'], $data['currency']),
                    $data['transaction_id'],
                    $data['valid_till'] ? $data['valid_till']->format('M d, Y') : 'N/A',
                ];
                $buttonParameter = $this->invoiceButtonParameter($data['view_url']);
            }

            if ($buttonParameter !== '') {
                $payload['template_button_url'] = $buttonParameter;
            }

            $response = $this->whatsAppController->send(new Request($payload));

            if ($response->getStatusCode() >= 400) {
                Log::warning('Invoice WhatsApp failed', [
                    'payment_id' => $payment->id,
                    'template' => $templateName,
                    'button_parameter' => $buttonParameter,
                    'status' => $response->getStatusCode(),
                    'response' => method_exists($response, 'getData') ? $response->getData(true) : null,
                ]);

                return false;
            }

            $this->markInvoiceWhatsAppSent($payment, $data['view_url']);

            Log::info('Invoice WhatsApp sent', [
                'payment_id' => $payment->id,
                'invoice_no' => $data['invoice_no'],
                'template' => $templateName,
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

    private function invoiceWhatsAppAlreadySent(PaymentHistory $payment): bool
    {
        $meta = is_array($payment->meta) ? $payment->meta : [];

        return ! empty($meta['invoice_whatsapp_sent_at']);
    }

    private function markInvoiceWhatsAppSent(PaymentHistory $payment, string $invoiceUrl): void
    {
        $meta = is_array($payment->meta) ? $payment->meta : [];
        $meta['invoice_whatsapp_sent_at'] = now()->toIso8601String();
        $meta['invoice_url'] = $invoiceUrl;
        $meta['invoice_pdf_url'] = $this->invoicePdfUrl($payment);

        $payment->forceFill(['meta' => $meta])->save();
    }

    private function invoiceButtonParameter(string $invoiceUrl, ?string $mode = null): string
    {
        $mode ??= (string) config('app.whatsapp_invoice_button_parameter', 'none');

        if ($mode === 'none' || $mode === '') {
            return '';
        }

        if ($mode === 'url') {
            return $invoiceUrl;
        }

        $parts = parse_url($invoiceUrl);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        $fragment = $parts['fragment'] ?? '';

        $value = ltrim($path, '/');

        if ($query !== '') {
            $value .= '?'.$query;
        }

        if ($fragment !== '') {
            $value .= '#'.$fragment;
        }

        return $value !== '' ? $value : $invoiceUrl;
    }

    private function resolveItem(PaymentHistory $payment): array
    {
        if (strtolower((string) $payment->app_payment_type) !== 'ppv') {
            $planName = $payment->plan?->name ?: 'Zo Stream Plan';

            return [
                'name' => $planName,
                'description' => 'Subscription plan',
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
                'name' => $seriesTitle.' - '.($episode->title ?: 'Episode'),
                'description' => 'Pay-per-view episode rental',
            ];
        }

        $season = Season::with('movie')->where('id', $movieId)->first();
        if ($season) {
            $seriesTitle = $season->movie?->title ?: 'Series';

            return [
                'name' => $seriesTitle.' - '.($season->title ?: 'Season '.$season->season_number),
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
        $prefix = $this->isZoStreamWifiPayment($payment) ? 'WIFI-INV-' : 'INV-';

        return $prefix.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT);
    }

    public function isZoStreamWifiPayment(PaymentHistory $payment): bool
    {
        $meta = is_array($payment->meta) ? $payment->meta : [];

        return ($meta['provider'] ?? null) === 'zostream_wifi'
            && ($meta['subscription_origin'] ?? null) === 'zostream_wifi_connection';
    }

    private function resolveDeviceType(PaymentHistory $payment): string
    {
        $meta = is_array($payment->meta) ? $payment->meta : [];

        return strtolower(trim((string) (
            $payment->device_type
            ?: ($meta['device_type'] ?? '')
            ?: ($payment->plan?->device_type ?? '')
            ?: 'unknown'
        )));
    }

    private function resolveDeviceLabel(PaymentHistory $payment): string
    {
        $deviceType = $this->resolveDeviceType($payment);

        return match ($deviceType) {
            'mobile' => 'Mobile',
            'browser', 'web' => 'Web / Browser',
            'tv', 'android_tv' => 'Android TV',
            'samsung', 'samsung_tv' => 'Samsung TV',
            'lg', 'webos', 'lg_webos' => 'LG webOS',
            default => $deviceType !== 'unknown'
                ? Str::of($deviceType)->replace(['_', '-'], ' ')->title()->toString()
                : 'N/A',
        };
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

        return $hasCountryCode ? $phone : $countryCode.$phone;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone));
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $symbol = strtoupper($currency) === 'INR' ? '₹' : strtoupper($currency).' ';

        return $symbol.number_format($amount, 2);
    }
}
