<!DOCTYPE html>
<html>
<head>
    <title>Zo Stream Invoice</title>
    <style>
        body { font-family: sans-serif; font-size: 14px; color: #000; }
        .heading { font-size: 20px; font-weight: bold; margin-bottom: 10px; }
        .section-title { font-weight: bold; margin-top: 25px; margin-bottom: 5px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f0f0f0; }

        .no-border td { border: none; padding: 2px; }

        .total { font-weight: bold; margin-top: 20px; }
        .footer { margin-top: 40px; font-size: 13px; }
    </style>
</head>
<body>
    <div>
        <p class="heading">INVOICE</p>

        <p><strong>Zo Stream</strong><br>
        Zuangtui, Aizawl<br>
        GSTIN: AUEPL9421AA1Z1<br>
        Contact: +91 9607027681<br>
        Email: support@zostream.in</p>

        <p><strong>Invoice No:</strong> {{ $data->invoice_no ?? 'N/A' }}<br>
        <strong>Invoice Date:</strong> {{ \Carbon\Carbon::parse($data->created_at ?? now())->format('M d, Y') }}</p>

        <p class="section-title">Billed To:</p>
        <table class="no-border">
            <tr><td><strong>Customer Name</strong></td><td>{{ $data->hming ?? 'N/A' }}</td></tr>
            <tr><td><strong>Address</strong></td><td>{{ $data->address ?? 'N/A' }}</td></tr>
            <tr><td><strong>Email</strong></td><td>{{ $data->mail ?? 'N/A' }}</td></tr>
        </table>

        <p class="section-title">Description of Service:</p>
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Price</th>
                    <th>Total Pay</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $data->plan }}</td>
                    <td>₹ {{ number_format($data->amount) }}</td>
                    <td>₹ {{ number_format($data->total_pay) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="total">Total Payable Amount: ₹ {{ number_format($data->amount) }}</p>

        <p class="section-title">Payment Details:</p>
        <table>
            <thead>
                <tr><td><strong>Payment Method</strong></td><td>{{ $data->pg ?? 'N/A' }}</td></tr>
                <tr><td><strong>Transaction ID</strong></td><td>{{ $data->pid ?? 'N/A' }}</td></tr>
            </thead>
            
        </table>

        <p class="footer">For any queries, contact <a href="mailto:support@zostream.com">support@zostream.in</a></p>
    </div>
</body>
</html>
