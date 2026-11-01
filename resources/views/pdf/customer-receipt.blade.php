<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payment['receipt_number'] }}</title>
    <style>
        @page { margin: 48px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #243044; }
        .brand { font-size: 25px; font-weight: bold; color: #1769cf; }
        .logo { width: 130px; margin-bottom: 18px; }
        h1 { font-size: 24px; margin-bottom: 6px; }
        .muted { color: #667085; }
        .paid { color: #16815c; font-weight: bold; }
        .header { border-bottom: 2px solid #1769cf; padding-bottom: 24px; }
        .customer { margin: 25px 0; line-height: 1.8; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 12px 8px; border-bottom: 1px solid #e5e9f0; vertical-align: top; overflow-wrap: break-word; }
        td:first-child { color: #667085; width: 35%; }
        .amount { background: #edf5ff; padding: 22px; margin: 28px 0; font-size: 22px; font-weight: bold; }
        footer { margin-top: 30px; font-size: 10px; color: #667085; line-height: 1.8; }
    </style>
</head>
<body>
    <div class="header">
        @if($logo)<img class="logo" src="{{ $logo }}" alt="Zo Stream">@else<div class="brand">Zo Stream</div>@endif
        <h1>Payment receipt</h1>
        <span class="muted">{{ $payment['receipt_number'] }}</span> &nbsp; <span class="paid">PAID</span>
    </div>
    <div class="customer"><strong>{{ $customer }}</strong>@if($username)<br><span class="muted">{{ '@'.$username }}</span>@endif</div>
    <table>
        <tr><td>Subscription plan</td><td>{{ $payment['plan'] }}</td></tr>
        <tr><td>Device type</td><td>{{ ucfirst($payment['device_type'] ?? 'Not recorded') }}</td></tr>
        <tr><td>Payment date</td><td>{{ $payment['payment_date'] ? \Carbon\Carbon::parse($payment['payment_date'])->format('d M Y, h:i A T') : 'Not recorded' }}</td></tr>
        <tr><td>Valid until</td><td>{{ $payment['valid_until'] ? \Carbon\Carbon::parse($payment['valid_until'])->format('d M Y') : 'Not recorded' }}</td></tr>
        <tr><td>Purchase type</td><td>{{ ucfirst($payment['payment_type'] ?? 'Subscription') }}</td></tr>
        <tr><td>Payment method</td><td>{{ $payment['payment_method'] ?: 'Not recorded' }}</td></tr>
        <tr><td>Payment gateway</td><td>{{ $payment['payment_gateway'] ?: 'Not recorded' }}</td></tr>
        <tr><td>Transaction ID</td><td>{{ $payment['transaction_id'] ?: 'Not recorded' }}</td></tr>
    </table>
    <div class="amount">{{ $payment['currency'] ?: '' }} {{ number_format((float) $payment['amount'], 2) }} <small style="font-size:11px">paid</small></div>
    <footer>Thank you for choosing Zo Stream.<br>
        This receipt confirms the recorded payment. Payment status and current subscription status are separate.
        Validity is taken from this payment record. This is a payment receipt, not a tax invoice.</footer>
</body>
</html>
