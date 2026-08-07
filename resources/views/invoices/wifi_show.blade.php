<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice['invoice_no'] }} - Zo Stream WIFI Invoice</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 14px; background: #f1f5f9; color: #172033; font: 15px/1.55 Arial, sans-serif; }
        .page { width: min(880px, 100%); margin: auto; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(15, 23, 42, .12); }
        .header { display: flex; justify-content: space-between; gap: 24px; padding: 34px; color: white; background: linear-gradient(135deg, #075985, #0ea5e9); }
        .brand { display: flex; align-items: center; gap: 14px; font-size: 26px; font-weight: 800; }
        .brand img { width: 54px; height: 54px; object-fit: contain; border-radius: 12px; }
        .header h1 { margin: 18px 0 0; font-size: 38px; }
        .invoice-no { text-align: right; }
        .invoice-no strong { display: block; font-size: 18px; }
        .content { padding: 34px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .box { padding: 20px; border: 1px solid #dbeafe; border-radius: 14px; background: #f8fafc; }
        .box h3 { margin: 0 0 12px; color: #0369a1; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
        .box p { margin: 5px 0; }
        table { width: 100%; margin-top: 24px; border-collapse: collapse; }
        th, td { padding: 15px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #e0f2fe; color: #075985; }
        th:last-child, td:last-child { text-align: right; }
        .total { padding: 22px 15px; text-align: right; font-size: 18px; }
        .total strong { margin-left: 18px; color: #0369a1; font-size: 26px; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 24px; }
        .button { display: inline-block; padding: 11px 17px; border-radius: 999px; color: white; background: #0284c7; text-decoration: none; font-weight: 700; }
        .footer { margin-top: 28px; padding-top: 18px; border-top: 1px solid #e2e8f0; color: #64748b; }
        @media (max-width: 650px) { .header, .grid { display: block; } .invoice-no { margin-top: 20px; text-align: left; } .box + .box { margin-top: 14px; } .content, .header { padding: 22px; } }
        @media print { body { padding: 0; background: white; } .page { box-shadow: none; } .actions { display: none; } }
    </style>
</head>
<body>
    <main class="page">
        <header class="header">
            <div>
                <div class="brand">
                    <img src="{{ asset('images/zostream-invoice-logo.png') }}" alt="Zo Stream logo">
                    <span>Zo Stream WIFI</span>
                </div>
                <h1>Invoice</h1>
            </div>
            <div class="invoice-no">
                <span>Invoice No</span>
                <strong>{{ $invoice['invoice_no'] }}</strong>
                <span>{{ $invoice['invoice_date']->format('M d, Y') }}</span>
            </div>
        </header>

        <section class="content">
            <div class="actions">
                <a class="button" href="{{ $invoice['pdf_url'] }}">Download PDF Invoice</a>
            </div>

            <div class="grid">
                <div class="box">
                    <h3>Billed To</h3>
                    <p><strong>{{ $invoice['customer_name'] }}</strong></p>
                    @if($invoice['customer_phone'])<p>{{ $invoice['customer_phone'] }}</p>@endif
                    @if($invoice['customer_email'])<p>{{ $invoice['customer_email'] }}</p>@endif
                    <p>{{ $invoice['customer_address'] }}</p>
                </div>
                <div class="box">
                    <h3>Invoice Details</h3>
                    <p><strong>Billing Period:</strong> {{ $invoice['billing_period'] }}</p>
                    <p><strong>Due Date:</strong> {{ $invoice['due_date'] ? $invoice['due_date']->format('M d, Y') : 'N/A' }}</p>
                    <p><strong>Status:</strong> {{ ucfirst($invoice['status']) }}</p>
                    <p><strong>Transaction:</strong> {{ $invoice['transaction_id'] }}</p>
                </div>
            </div>

            <table>
                <thead><tr><th>Description</th><th>Billing Period</th><th>Amount</th></tr></thead>
                <tbody><tr>
                    <td><strong>Zo Stream WIFI</strong><br>{{ $invoice['item_name'] }}</td>
                    <td>{{ $invoice['billing_period'] }}</td>
                    <td>{{ strtoupper($invoice['currency']) === 'INR' ? '₹' : strtoupper($invoice['currency']).' ' }}{{ number_format($invoice['amount'], 2) }}</td>
                </tr></tbody>
            </table>

            <div class="total">Total <strong>{{ strtoupper($invoice['currency']) === 'INR' ? '₹' : strtoupper($invoice['currency']).' ' }}{{ number_format($invoice['amount'], 2) }}</strong></div>
            <div class="footer">Thank you for choosing Zo Stream WIFI.</div>
        </section>
    </main>
</body>
</html>
