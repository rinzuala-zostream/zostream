<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice['invoice_no'] }} - Zo Stream WIFI Invoice</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 30px; color: #172033; font: 13px/1.5 DejaVu Sans, sans-serif; }
        .header { padding: 22px; color: white; background: #075985; }
        .logo { width: 46px; height: 46px; margin-right: 10px; vertical-align: middle; object-fit: contain; }
        .brand { display: inline-block; font-size: 25px; font-weight: bold; vertical-align: middle; }
        .title { float: right; text-align: right; }
        .title h1 { margin: 0; font-size: 28px; }
        .grid, .items { width: 100%; margin-top: 24px; border-collapse: collapse; }
        .grid td { width: 50%; padding: 14px; border: 1px solid #bae6fd; vertical-align: top; }
        .label { margin-bottom: 8px; color: #0369a1; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .items th { padding: 12px; color: white; background: #075985; text-align: left; }
        .items td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
        .items th:last-child, .items td:last-child { text-align: right; }
        .total { margin-top: 20px; text-align: right; font-size: 17px; }
        .total strong { margin-left: 20px; color: #0369a1; font-size: 24px; }
        .footer { position: fixed; right: 30px; bottom: 24px; left: 30px; padding-top: 10px; border-top: 1px solid #cbd5e1; color: #64748b; }
    </style>
</head>
<body>
    <header class="header">
        <img class="logo" src="{{ public_path('images/zostream-invoice-logo.png') }}" alt="Zo Stream logo">
        <span class="brand">Zo Stream WIFI</span>
        <div class="title">
            <h1>Invoice</h1>
            <div>{{ $invoice['invoice_no'] }}</div>
            <div>{{ $invoice['invoice_date']->format('M d, Y') }}</div>
        </div>
    </header>

    <table class="grid"><tr>
        <td>
            <div class="label">Billed To</div>
            <strong>{{ $invoice['customer_name'] }}</strong><br>
            @if($invoice['customer_phone']){{ $invoice['customer_phone'] }}<br>@endif
            @if($invoice['customer_email']){{ $invoice['customer_email'] }}<br>@endif
            {{ $invoice['customer_address'] }}
        </td>
        <td>
            <div class="label">Invoice Details</div>
            <strong>Billing Period:</strong> {{ $invoice['billing_period'] }}<br>
            <strong>Due Date:</strong> {{ $invoice['due_date'] ? $invoice['due_date']->format('M d, Y') : 'N/A' }}<br>
            <strong>Status:</strong> {{ ucfirst($invoice['status']) }}<br>
            <strong>Transaction:</strong> {{ $invoice['transaction_id'] }}
        </td>
    </tr></table>

    <table class="items">
        <thead><tr><th>Description</th><th>Billing Period</th><th>Amount</th></tr></thead>
        <tbody><tr>
            <td><strong>Zo Stream WIFI</strong><br>{{ $invoice['item_name'] }}</td>
            <td>{{ $invoice['billing_period'] }}</td>
            <td>{{ strtoupper($invoice['currency']) === 'INR' ? 'Rs. ' : strtoupper($invoice['currency']).' ' }}{{ number_format($invoice['amount'], 2) }}</td>
        </tr></tbody>
    </table>

    <div class="total">Total <strong>{{ strtoupper($invoice['currency']) === 'INR' ? 'Rs. ' : strtoupper($invoice['currency']).' ' }}{{ number_format($invoice['amount'], 2) }}</strong></div>
    <footer class="footer">Thank you for choosing Zo Stream WIFI.</footer>
</body>
</html>
