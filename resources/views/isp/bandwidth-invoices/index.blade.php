@extends('isp.layouts.admin')
@section('title', 'Bandwidth invoices')
@section('eyebrow', 'Bandwidth Only billing')
@section('content')
<div class="page-actions"><div><h2>Monthly bandwidth invoices</h2><p>Previous-month bandwidth charges, GST and payment status.</p></div></div>
<section class="form-card">
    <div class="table-wrap"><table>
        <thead><tr><th>Invoice</th><th>Branch</th><th>Service month</th><th>Bandwidth</th><th>Total</th><th>Due date</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($invoices as $invoice)
            <tr>
                <td>{{ $invoice->number() }}</td><td>{{ $invoice->branch_name }}</td>
                <td>{{ $invoice->billing_month->format('F Y') }}</td><td>{{ number_format($invoice->bandwidth_mbps, 2) }} Mbps</td>
                <td>₹{{ number_format($invoice->total, 2) }}</td><td>{{ $invoice->due_date->format('d M Y') }}</td>
                <td>{{ $invoice->paid_at ? 'Paid' : ($invoice->isOverdue() ? 'Overdue — internet blocked' : 'Unpaid') }}</td>
                <td><a class="button secondary" href="{{ route('isp.bandwidth-invoices.show', $invoice) }}">View invoice</a></td>
            </tr>
        @empty
            <tr><td colspan="8">No bandwidth invoices yet. The first invoice is generated on the 1st of the following month.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $invoices->links('isp.partials.pagination') }}
</section>
@endsection
