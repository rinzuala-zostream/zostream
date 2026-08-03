<?php

namespace App\Http\Controllers;

use App\Models\New\PaymentHistory;
use App\Services\InvoiceService;

class InvoiceController extends Controller
{
    public function show(PaymentHistory $payment, InvoiceService $invoices)
    {
        abort_unless($payment->status === 'success', 404);

        return view('invoices.show', [
            'invoice' => $invoices->buildInvoiceData($payment),
        ]);
    }
}
