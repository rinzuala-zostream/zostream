<?php

namespace App\Http\Controllers;

use App\Models\New\PaymentHistory;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends Controller
{
    public function show(PaymentHistory $payment, InvoiceService $invoices)
    {
        abort_unless($payment->status === 'success', 404);

        return view($invoices->isZoStreamWifiPayment($payment) ? 'invoices.wifi_show' : 'invoices.show', [
            'invoice' => $invoices->buildInvoiceData($payment),
        ]);
    }

    public function pdf(PaymentHistory $payment, InvoiceService $invoices)
    {
        abort_unless($payment->status === 'success', 404);

        $invoice = $invoices->buildInvoiceData($payment);
        $pdf = Pdf::loadView($invoices->isZoStreamWifiPayment($payment) ? 'invoices.wifi_pdf' : 'invoices.pdf', [
            'invoice' => $invoice,
        ]);

        return $pdf->stream($invoice['invoice_no'].'.pdf');
    }
}
