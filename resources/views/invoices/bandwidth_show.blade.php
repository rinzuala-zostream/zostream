<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->number() }} · ZoStream Bandwidth</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#edf1ed;color:#142b25;font:15px/1.6 Arial,sans-serif;padding:32px 16px}.invoice{max-width:920px;margin:auto;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 18px 65px #193b2420}.hero{background:#123d31;color:#fff;padding:36px 40px;position:relative}.brand{font-size:23px;font-weight:800;letter-spacing:-.6px}.brand small{color:#bfd8cd;font-size:10px;letter-spacing:2px;display:block;margin-top:3px}.hero-row{display:flex;justify-content:space-between;gap:20px;margin-top:38px}.eyebrow{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:#c6e4b7}.hero h1{font-size:38px;line-height:1.1;margin:9px 0 12px;letter-spacing:-1px}.hero p{margin:0;color:#c4d8d1}.status{display:inline-block;padding:6px 13px;border-radius:30px;background:#d9f5b1;color:#214023;font-size:11px;font-weight:700}.status.overdue{background:#ffe2c6;color:#7d3515}.reference{text-align:right;padding-top:10px;font-size:12px}.reference strong{display:block;color:#fff;font-size:16px}.content{padding:32px 40px}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}.toolbar span{font-size:12px;color:#6c7d75}.download{padding:10px 17px;border:1px solid #d7e0d9;border-radius:9px;color:#194934;text-decoration:none;font-weight:700;font-size:12px}.summary{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:30px}.info{background:#f7f9f5;border:1px solid #e1e8de;border-radius:12px;padding:20px}.info h2{margin:0 0 12px;text-transform:uppercase;font-size:10px;letter-spacing:1.5px;color:#70806e}.info strong{font-size:20px}.info p{margin:5px 0;font-size:13px;color:#52665a}.section-title{font-size:12px;text-transform:uppercase;letter-spacing:1.5px;margin:28px 0 12px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th{text-align:left;padding:12px 10px;color:#657369;background:#f1f5ed;font-size:10px;text-transform:uppercase;letter-spacing:.7px}td{padding:20px 10px;border-bottom:1px solid #e4e9e1;font-size:14px}th:last-child,td:last-child{text-align:right}.description small{display:block;font-size:11px;color:#7d8980}.totals{margin:22px 0 24px auto;width:min(100%,350px)}.totals p{display:flex;justify-content:space-between;gap:15px;margin:0;padding:9px 10px;font-size:13px}.totals .grand{padding:17px 16px;background:#e9f3dc;border-radius:10px;margin-top:10px;align-items:center;font-weight:700}.grand strong{font-size:25px;letter-spacing:-.8px}.notice{padding:17px 20px;border-left:3px solid #adc293;background:#f7f9f4;color:#56694e;font-size:12px}.notice.overdue{border-color:#d29b63;background:#fff7ee;color:#82562a}.footer{margin-top:30px;padding-top:20px;border-top:1px solid #e3e9df;display:flex;justify-content:space-between;font-size:11px;color:#7a887c}.footer strong{color:#3a5745}@media(max-width:600px){body{padding:12px 8px}.hero,.content{padding:25px 20px}.hero h1{font-size:29px}.hero-row{display:block;margin-top:25px}.reference{text-align:left;margin-top:18px}.summary{grid-template-columns:1fr}.toolbar,.footer{align-items:flex-start;flex-direction:column}.footer{gap:6px}}@media print{body{padding:0;background:#fff}.invoice{box-shadow:none;border-radius:0}.toolbar{display:none}}
    </style>
</head>
<body>
<main class="invoice">
    <header class="hero">
        <div class="brand">ZoStream<small>BUSINESS CONNECTIVITY</small></div>
        <div class="hero-row">
            <div><span class="eyebrow">Monthly bandwidth billing</span><h1>Bandwidth invoice</h1><p>{{ $invoice->billing_month->format('F Y') }} service period</p></div>
            <div class="reference"><span>Invoice number</span><strong>{{ $invoice->number() }}</strong><span class="status {{ $invoice->isOverdue() ? 'overdue' : '' }}">{{ $invoice->paid_at ? 'Paid' : ($invoice->isOverdue() ? 'Overdue' : 'Payment due') }}</span></div>
        </div>
    </header>
    <section class="content">
        <div class="toolbar"><span>Bandwidth Only · Monthly service invoice</span><a class="download" href="{{ $pdfUrl }}">↓ Download PDF</a></div>
        <div class="summary">
            <div class="info"><h2>Billed to</h2><strong>{{ $invoice->branch_name }}</strong><p>Bandwidth Only operator</p><p>{{ number_format($invoice->bandwidth_mbps, 2) }} Mbps allocated bandwidth</p></div>
            <div class="info"><h2>Invoice details</h2><p>Issued <b>{{ $invoice->issued_on->format('d M Y') }}</b></p><p>Payment due <b>{{ $invoice->due_date->format('d M Y') }}</b></p><p>Service {{ $invoice->billing_month->format('d M') }} – {{ $invoice->billing_month->copy()->endOfMonth()->format('d M Y') }}</p></div>
        </div>
        <h2 class="section-title">Your bandwidth charges</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Service</th><th>Bandwidth</th><th>Rate / Mbps</th><th>Amount</th></tr></thead>
            <tbody><tr><td class="description"><b>Internet bandwidth</b><small>Monthly allocated capacity</small></td><td>{{ number_format($invoice->bandwidth_mbps, 2) }} Mbps</td><td>₹{{ number_format($invoice->rate, 2) }}</td><td>₹{{ number_format($invoice->subtotal, 2) }}</td></tr></tbody>
        </table></div>
        <div class="totals"><p><span>Subtotal</span><b>₹{{ number_format($invoice->subtotal, 2) }}</b></p><p><span>GST ({{ $invoice->gst_percentage }}%)</span><b>₹{{ number_format($invoice->gst_amount, 2) }}</b></p><p class="grand"><span>Invoice total</span><strong>₹{{ number_format($invoice->total, 2) }}</strong></p></div>
        <div class="notice {{ $invoice->isOverdue() ? 'overdue' : '' }}">
            @if($invoice->paid_at)Payment recorded on {{ $invoice->paid_at->format('d M Y') }}. Internet access is subject to customer status, expiry and any other overdue branch invoice.
            @elseif($invoice->isOverdue())This invoice is overdue. Branch internet access is blocked until overdue invoices are settled. Contact ZoStream to record your payment.
            @else Please settle this invoice by {{ $invoice->due_date->format('d M Y') }}. Unpaid overdue invoices block branch internet access. Contact ZoStream for payment assistance.@endif
        </div>
        <footer class="footer"><strong>ZoStream · Business Bandwidth</strong><span>{{ $invoice->number() }} · All amounts in INR</span></footer>
    </section>
</main>
</body>
</html>
