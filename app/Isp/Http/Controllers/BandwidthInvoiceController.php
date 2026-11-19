<?php

namespace App\Isp\Http\Controllers;

use App\Isp\Models\BandwidthInvoice;
use App\Isp\Services\BandwidthBillingService;
use App\Isp\Services\BandwidthInvoiceWhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BandwidthInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        return view('isp.bandwidth-invoices.index', [
            'invoices' => BandwidthInvoice::with(['branch', 'paidBy'])
                ->when($request->user()->isBranchOperator(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
                ->orderByDesc('billing_month')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function show(Request $request, BandwidthInvoice $bandwidthInvoice, BandwidthInvoiceWhatsAppService $links): View
    {
        abort_if($request->user()->isBranchOperator() && $bandwidthInvoice->branch_id !== $request->user()->branch_id, 403);

        return view('isp.bandwidth-invoices.show', [
            'invoice' => $bandwidthInvoice->load(['branch', 'paidBy', 'deliveries']),
            'publicUrl' => $links->viewUrl($bandwidthInvoice), 'pdfUrl' => $links->pdfUrl($bandwidthInvoice),
        ]);
    }

    public function update(Request $request, BandwidthInvoice $bandwidthInvoice, BandwidthBillingService $billing): RedirectResponse
    {
        $data = $request->validate(['due_date' => ['required', 'date_format:Y-m-d']]);
        DB::transaction(function () use ($bandwidthInvoice, $data): void {
            $invoice = BandwidthInvoice::lockForUpdate()->findOrFail($bandwidthInvoice->id);
            abort_if($invoice->paid_at !== null, 422, 'A paid invoice cannot be edited.');
            abort_if($data['due_date'] < $invoice->issued_on->toDateString(), 422, 'Due date cannot be before the invoice issue date.');
            $invoice->update($data);
        });
        $failed = $billing->reconcile($bandwidthInvoice->branch, force: true);

        return back()->with($failed ? 'warning' : 'success', $failed
            ? 'Due date saved. Some access updates failed and will be retried automatically.' : 'Invoice due date updated; access synchronized.');
    }

    public function whatsapp(BandwidthInvoice $bandwidthInvoice, BandwidthInvoiceWhatsAppService $whatsApp): RedirectResponse
    {
        $failed = $whatsApp->sendInvoice($bandwidthInvoice);

        if (! $bandwidthInvoice->deliveries()->exists()) {
            return back()->with('warning', 'No eligible recipient found. Add a WhatsApp phone number to an active Bandwidth Only operator in this branch.');
        }

        return back()->with($failed ? 'warning' : 'success', $failed
            ? 'WhatsApp attempt failed. Delivery status is shown below; failed attempts retry after 15 minutes.'
            : 'WhatsApp delivery checked. Existing accepted messages are not sent again; pending retries respect their retry time.');
    }

    public function paid(Request $request, BandwidthInvoice $bandwidthInvoice, BandwidthBillingService $billing): RedirectResponse
    {
        $data = $request->validate(['payment_reference' => ['nullable', 'string', 'max:255']]);
        DB::transaction(function () use ($request, $bandwidthInvoice, $data): void {
            $invoice = BandwidthInvoice::lockForUpdate()->findOrFail($bandwidthInvoice->id);
            if (! $invoice->paid_at) {
                $invoice->update([
                    'paid_at' => now(), 'paid_by' => $request->user()->id,
                    'payment_reference' => $data['payment_reference'] ?? null,
                ]);
            }
        });
        $failed = $billing->reconcile($bandwidthInvoice->branch, force: true);

        return back()->with($failed ? 'warning' : 'success', $failed
            ? 'Payment recorded. Some access updates failed and will be retried automatically.'
            : 'Invoice marked as paid. Access restored for eligible customers if no other invoice is overdue.');
    }
}
