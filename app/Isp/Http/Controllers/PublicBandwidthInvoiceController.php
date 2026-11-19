<?php

namespace App\Isp\Http\Controllers;

use App\Isp\Models\BandwidthInvoice;
use App\Isp\Services\BandwidthInvoiceWhatsAppService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PublicBandwidthInvoiceController extends Controller
{
    public function show(BandwidthInvoice $bandwidthInvoice, BandwidthInvoiceWhatsAppService $links): View
    {
        return view('invoices.bandwidth_show', ['invoice' => $bandwidthInvoice, 'pdfUrl' => $links->pdfUrl($bandwidthInvoice)]);
    }

    public function pdf(BandwidthInvoice $bandwidthInvoice): Response
    {
        return Pdf::loadView('invoices.bandwidth_pdf', ['invoice' => $bandwidthInvoice])
            ->setPaper('a4')->stream($bandwidthInvoice->number().'.pdf');
    }
}
