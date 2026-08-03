<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice['invoice_no'] }} - Zo Stream Invoice</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 28px;
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            background: #ffffff;
            font-size: 13px;
            line-height: 1.45;
        }
        .top {
            padding-bottom: 18px;
            border-bottom: 3px solid #ff3448;
        }
        .brand {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .brand span { color: #ff3448; }
        .invoice-title {
            float: right;
            text-align: right;
            margin-top: -34px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
        }
        .muted { color: #64748b; }
        .status {
            display: inline-block;
            margin-top: 8px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .6px;
        }
        .grid {
            width: 100%;
            margin-top: 24px;
        }
        .grid td {
            width: 50%;
            vertical-align: top;
            padding: 14px;
            border: 1px solid #e2e8f0;
        }
        .box-title {
            margin-bottom: 8px;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
        }
        .items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 24px;
        }
        .items th {
            background: #0f172a;
            color: #ffffff;
            text-align: left;
            padding: 12px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .7px;
        }
        .items td {
            padding: 13px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .items th:last-child,
        .items td:last-child {
            text-align: right;
        }
        .total {
            margin-top: 18px;
            text-align: right;
            font-size: 15px;
        }
        .total strong {
            display: inline-block;
            min-width: 150px;
            font-size: 24px;
            color: #ff3448;
        }
        .footer {
            position: fixed;
            left: 28px;
            right: 28px;
            bottom: 22px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 11px;
        }
        .right { text-align: right; }
    </style>
</head>
<body>
    <div class="top">
        <div class="brand">Zo <span>Stream</span></div>
        <div class="invoice-title">
            <h1>Invoice</h1>
            <div>{{ $invoice['invoice_no'] }}</div>
            <div class="muted">{{ $invoice['invoice_date']->format('M d, Y') }}</div>
            <div class="status">Payment Successful</div>
        </div>
    </div>

    <table class="grid">
        <tr>
            <td>
                <div class="box-title">Billed To</div>
                <strong>{{ $invoice['customer_name'] }}</strong><br>
                @if($invoice['customer_phone'])
                    {{ $invoice['customer_phone'] }}<br>
                @endif
                @if($invoice['customer_email'])
                    {{ $invoice['customer_email'] }}<br>
                @endif
                <span class="muted">{{ $invoice['customer_address'] }}</span>
            </td>
            <td>
                <div class="box-title">Payment Details</div>
                <strong>Status:</strong> {{ ucfirst($invoice['status']) }}<br>
                <strong>Gateway:</strong> {{ ucfirst($invoice['payment_gateway']) }}<br>
                <strong>Method:</strong> {{ ucfirst($invoice['payment_method']) }}<br>
                <strong>Transaction:</strong> {{ $invoice['transaction_id'] }}<br>
                @if($invoice['valid_till'])
                    <strong>Valid till:</strong> {{ $invoice['valid_till']->format('M d, Y') }}
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th>Type</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>{{ $invoice['item_name'] }}</strong><br>
                    <span class="muted">{{ $invoice['item_description'] }}</span>
                </td>
                <td>{{ $invoice['payment_type'] }}</td>
                <td>
                    @if(strtoupper($invoice['currency']) === 'INR')
                        Rs. {{ number_format($invoice['amount'], 2) }}
                    @else
                        {{ strtoupper($invoice['currency']) }} {{ number_format($invoice['amount'], 2) }}
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    <div class="total">
        Total Paid
        <strong>
            @if(strtoupper($invoice['currency']) === 'INR')
                Rs. {{ number_format($invoice['amount'], 2) }}
            @else
                {{ strtoupper($invoice['currency']) }} {{ number_format($invoice['amount'], 2) }}
            @endif
        </strong>
    </div>

    <div class="footer">
        Zo Stream, Zuangtui, Aizawl · GSTIN: AUEPL9421AA1Z1
        <span class="right" style="float:right;">support@zostream.in</span>
    </div>
</body>
</html>
