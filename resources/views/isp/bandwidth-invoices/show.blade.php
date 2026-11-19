@extends('isp.layouts.admin')
@section('title', $invoice->number())
@section('eyebrow', 'Bandwidth invoice')
@section('content')
<div class="page-actions"><a class="button secondary" href="{{ route('isp.bandwidth-invoices.index') }}">← Bandwidth invoices</a><button class="button secondary" onclick="window.print()" type="button">Print invoice</button></div>
<section class="form-card bandwidth-invoice">
    <h2>ZoStream — Bandwidth invoice {{ $invoice->number() }}</h2>
    <p>Bill to: <strong>{{ $invoice->branch_name }}</strong></p>
    <p>Service month: {{ $invoice->billing_month->format('F Y') }} · Issued: {{ $invoice->issued_on->format('d M Y') }} · Due: {{ $invoice->due_date->format('d M Y') }}</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Description</th><th>Quantity</th><th>Monthly rate / Mbps</th><th>Amount</th></tr></thead>
        <tbody><tr><td>Internet bandwidth</td><td>{{ number_format($invoice->bandwidth_mbps, 2) }} Mbps</td><td>₹{{ number_format($invoice->rate, 2) }}</td><td>₹{{ number_format($invoice->subtotal, 2) }}</td></tr></tbody>
    </table></div>
    <p>Subtotal: ₹{{ number_format($invoice->subtotal, 2) }}</p>
    <p>GST ({{ $invoice->gst_percentage }}%): ₹{{ number_format($invoice->gst_amount, 2) }}</p>
    <h3>Total: ₹{{ number_format($invoice->total, 2) }}</h3>
    @if($invoice->paid_at)
        <p>Paid on {{ $invoice->paid_at->format('d M Y H:i') }} · Recorded by {{ $invoice->paidBy?->name ?? 'Administrator' }} @if($invoice->payment_reference) · Reference: {{ $invoice->payment_reference }} @endif</p>
    @else
        <p>{{ $invoice->isOverdue() ? 'Overdue: branch internet is blocked until all overdue invoices are paid.' : 'Unpaid: branch internet will be blocked after the due date.' }}</p>
        @if(auth()->user()->isAdmin())
            <form method="POST" action="{{ route('isp.bandwidth-invoices.update', $invoice) }}" class="form-grid">
                @csrf @method('PUT')
                <label>Due date<input name="due_date" type="date" min="{{ $invoice->issued_on->toDateString() }}" value="{{ old('due_date', $invoice->due_date->toDateString()) }}" required></label>
                <div><button class="button secondary">Update due date</button></div>
            </form>
            <form method="POST" action="{{ route('isp.bandwidth-invoices.paid', $invoice) }}" class="form-grid" data-confirm="Confirm payment of ₹{{ number_format($invoice->total, 2) }} for this invoice?">
                @csrf
                <label>Payment reference (optional)<input name="payment_reference" maxlength="255" value="{{ old('payment_reference') }}"></label>
                <div><button class="button primary">Mark as Paid</button></div>
            </form>
        @endif
    @endif
</section>
@endsection
