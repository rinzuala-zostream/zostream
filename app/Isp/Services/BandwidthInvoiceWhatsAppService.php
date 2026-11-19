<?php

namespace App\Isp\Services;

use App\Http\Controllers\WhatsAppController;
use App\Isp\Models\BandwidthInvoice;
use App\Isp\Models\BandwidthInvoiceDelivery;
use App\Isp\Models\Branch;
use App\Isp\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BandwidthInvoiceWhatsAppService
{
    public function __construct(private readonly WhatsAppController $whatsApp) {}

    public function viewUrl(BandwidthInvoice $invoice): string
    {
        return URL::signedRoute('invoice.bandwidth.show', ['bandwidthInvoice' => $invoice->id]);
    }

    public function pdfUrl(BandwidthInvoice $invoice): string
    {
        return URL::signedRoute('invoice.bandwidth.pdf', ['bandwidthInvoice' => $invoice->id]);
    }

    public function sendPending(Branch $branch): int
    {
        $failed = 0;
        if ($branch->isBandwidthOnly()) {
            $branch->bandwidthInvoices()->whereNull('paid_at')->eachById(function ($invoice) use (&$failed): void {
                $failed += $this->sendInvoice($invoice);
            });
        }

        return $failed;
    }

    /** Returns the number of failed attempts; successful recipients are never sent again. */
    public function sendInvoice(BandwidthInvoice $invoice): int
    {
        $branch = $invoice->branch;
        if (! $branch?->isBandwidthOnly()) {
            return 0;
        }
        $failed = 0;
        $recipients = $branch->users()->where('role', 'branch_operator')->where('is_active', true)
            ->whereNotNull('phone')->orderBy('id')->get();
        foreach ($recipients as $recipient) {
            $phone = PhoneNumber::normalize($recipient->phone);
            if (! $phone) {
                continue;
            }
            $delivery = BandwidthInvoiceDelivery::firstOrCreate([
                'bandwidth_invoice_id' => $invoice->id, 'phone' => $phone,
            ], ['recipient_name' => $recipient->name]);

            // Claim atomically across scheduler/manual requests; release stale claims after 15 minutes.
            $claimed = BandwidthInvoiceDelivery::whereKey($delivery->id)->whereNull('sent_at')
                ->where(fn ($query) => $query->whereNull('processing_at')->orWhere('processing_at', '<=', now()->subMinutes(15)))
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->update(['processing_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
            if (! $claimed) {
                continue;
            }
            try {
                $payload = [
                    'to' => $phone,
                    'type' => 'template',
                    'template_name' => config('services.isp_bandwidth_invoice.template', 'zostream_wifi_invoice_v3'),
                    'language' => config('services.isp_bandwidth_invoice.language', 'en'),
                    'template_header_document_url' => $this->pdfUrl($invoice),
                    'template_header_document_name' => $invoice->number().'.pdf',
                    'template_params' => [
                        $recipient->name,
                        $invoice->number(),
                        $invoice->billing_month->format('M d, Y').' - '.$invoice->billing_month->copy()->endOfMonth()->format('M d, Y'),
                        number_format((float) $invoice->total, 2, '.', ''),
                        $invoice->due_date->format('M d, Y'),
                    ],
                ];
                $mode = config('app.whatsapp_invoice_button_parameter', 'none');
                if ($mode && $mode !== 'none') {
                    $url = $this->viewUrl($invoice);
                    $payload['template_button_url'] = $mode === 'url' ? $url : ltrim(parse_url($url, PHP_URL_PATH), '/').'?'.parse_url($url, PHP_URL_QUERY);
                }
                $response = $this->whatsApp->send(new Request($payload));
                $result = $response->getData(true);
                if ($response->getStatusCode() >= 400 || data_get($result, 'status') !== 'success') {
                    throw new RuntimeException((string) (data_get($result, 'error.error.message') ?? data_get($result, 'message', 'WhatsApp rejected the invoice.')));
                }
                $delivery->update([
                    'sent_at' => now(), 'processing_at' => null, 'next_attempt_at' => null, 'last_error' => null,
                    'provider_message_id' => data_get($result, 'response.messages.0.id'),
                ]);
            } catch (Throwable $e) {
                $failed++;
                $delivery->update([
                    'processing_at' => null, 'next_attempt_at' => now()->addMinutes(15),
                    'last_error' => Str::limit($e->getMessage(), 1000),
                ]);
                Log::warning('Bandwidth invoice WhatsApp attempt failed', ['invoice_id' => $invoice->id, 'delivery_id' => $delivery->id]);
            }
        }

        return $failed;
    }
}
