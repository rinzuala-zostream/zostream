<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice['invoice_no'] }} - Zo Stream Invoice</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #05070d;
            --card: #0f172a;
            --muted: #94a3b8;
            --line: rgba(148, 163, 184, .22);
            --accent: #ff3448;
            --accent2: #38bdf8;
            --text: #f8fafc;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 52, 72, .18), transparent 34rem),
                radial-gradient(circle at bottom right, rgba(56, 189, 248, .14), transparent 30rem),
                var(--bg);
            color: var(--text);
            padding: 28px 14px;
        }

        .invoice {
            width: min(920px, 100%);
            margin: 0 auto;
            background: linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(2, 6, 23, .96));
            border: 1px solid var(--line);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 28px 90px rgba(0,0,0,.45);
        }

        .hero {
            padding: 34px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
            background:
                linear-gradient(135deg, rgba(255, 52, 72, .22), rgba(56, 189, 248, .08)),
                rgba(255,255,255,.02);
            border-bottom: 1px solid var(--line);
        }

        .brand-row { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
        .logo-mark {
            width: 58px;
            height: 58px;
            flex: 0 0 auto;
            border-radius: 14px;
            object-fit: contain;
            box-shadow: 0 14px 34px rgba(0,0,0,.24);
        }
        .brand { font-size: 28px; font-weight: 900; letter-spacing: -.04em; }
        .brand span { color: var(--accent); }
        .label {
            display: inline-flex;
            margin-bottom: 12px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(34, 197, 94, .14);
            color: #86efac;
            font-weight: 800;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .hero h1 { margin: 0 0 8px; font-size: clamp(30px, 5vw, 54px); letter-spacing: -.06em; }
        .muted { color: var(--muted); }
        .right { text-align: right; min-width: 210px; }
        .invoice-no { font-weight: 900; font-size: 20px; }

        .content { padding: 34px; }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .box {
            padding: 20px;
            background: rgba(255,255,255,.035);
            border: 1px solid var(--line);
            border-radius: 20px;
        }
        .box h3 {
            margin: 0 0 12px;
            color: #e2e8f0;
            font-size: 13px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .box p { margin: 6px 0; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; overflow: hidden; border-radius: 18px; }
        th, td { padding: 18px; border-bottom: 1px solid var(--line); text-align: left; }
        th { color: #cbd5e1; font-size: 13px; text-transform: uppercase; letter-spacing: .08em; background: rgba(255,255,255,.04); }
        td:last-child, th:last-child { text-align: right; }
        .total {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 22px;
            padding: 24px 0 4px;
            font-size: 18px;
        }
        .total strong { font-size: 30px; }
        .footer {
            margin-top: 30px;
            padding-top: 22px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 16px;
            color: var(--muted);
            font-size: 14px;
        }
        a { color: #7dd3fc; }
        .actions {
            width: min(920px, 100%);
            margin: 0 auto 16px;
            display: flex;
            justify-content: flex-end;
        }
        .print-button {
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 900;
            color: white;
            background: linear-gradient(135deg, var(--accent), #fb7185);
            box-shadow: 0 12px 30px rgba(255, 52, 72, .28);
            cursor: pointer;
        }

        @media (max-width: 720px) {
            .hero, .footer { flex-direction: column; }
            .right { text-align: left; }
            .grid { grid-template-columns: 1fr; }
            .content, .hero { padding: 24px; }
            th, td { padding: 14px 10px; }
        }

        @media print {
            body { background: white; padding: 0; }
            .actions { display: none; }
            .invoice { box-shadow: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button class="print-button" onclick="window.print()">Print / Save PDF</button>
    </div>

    <main class="invoice">
        <section class="hero">
            <div>
                <div class="brand-row">
                    <img class="logo-mark" src="{{ asset('images/zostream-invoice-logo.png') }}" alt="Zo Stream logo">
                    <div class="brand">Zo <span>Stream</span></div>
                </div>
                <span class="label">Payment Successful</span>
                <h1>Invoice</h1>
                <p class="muted">Thank you for your {{ strtolower($invoice['payment_type']) }} purchase.</p>
            </div>
            <div class="right">
                <div class="muted">Invoice No</div>
                <div class="invoice-no">{{ $invoice['invoice_no'] }}</div>
                <p class="muted">{{ $invoice['invoice_date']->format('M d, Y') }}</p>
            </div>
        </section>

        <section class="content">
            <div class="grid">
                <div class="box">
                    <h3>Billed To</h3>
                    <p><strong>{{ $invoice['customer_name'] }}</strong></p>
                    @if($invoice['customer_phone'])
                        <p class="muted">{{ $invoice['customer_phone'] }}</p>
                    @endif
                    @if($invoice['customer_email'])
                        <p class="muted">{{ $invoice['customer_email'] }}</p>
                    @endif
                    <p class="muted">{{ $invoice['customer_address'] }}</p>
                </div>
                <div class="box">
                    <h3>Payment Details</h3>
                    <p><strong>Status:</strong> {{ ucfirst($invoice['status']) }}</p>
                    <p><strong>Gateway:</strong> {{ ucfirst($invoice['payment_gateway']) }}</p>
                    <p><strong>Method:</strong> {{ ucfirst($invoice['payment_method']) }}</p>
                    <p><strong>Device:</strong> {{ $invoice['device_label'] }}</p>
                    <p><strong>Transaction:</strong> {{ $invoice['transaction_id'] }}</p>
                    @if($invoice['valid_till'])
                        <p><strong>Valid till:</strong> {{ $invoice['valid_till']->format('M d, Y') }}</p>
                    @endif
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Device</th>
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
                        <td>{{ $invoice['device_label'] }}</td>
                        <td>
                            @if(strtoupper($invoice['currency']) === 'INR')
                                ₹{{ number_format($invoice['amount'], 2) }}
                            @else
                                {{ strtoupper($invoice['currency']) }} {{ number_format($invoice['amount'], 2) }}
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="total">
                <span class="muted">Total Paid</span>
                <strong>
                    @if(strtoupper($invoice['currency']) === 'INR')
                        ₹{{ number_format($invoice['amount'], 2) }}
                    @else
                        {{ strtoupper($invoice['currency']) }} {{ number_format($invoice['amount'], 2) }}
                    @endif
                </strong>
            </div>

            <div class="footer">
                <span>Zo Stream, Zuangtui, Aizawl</span>
                <span>Need help? <a href="tel:8837076347">8837076347</a></span>
            </div>
        </section>
    </main>
</body>
</html>
