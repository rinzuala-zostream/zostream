<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zo Stream · UPI QR payment</title>
    <style>
        :root { color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f7f8fa; color: #202124; }
        main { max-width: 360px; padding: 32px; text-align: center; }
        h1 { font-size: 24px; letter-spacing: -.5px; }
        p { color: #62666c; line-height: 1.6; }
        button { border: 0; border-radius: 12px; padding: 14px 24px; background: #202124; color: white; font: inherit; cursor: pointer; }
        button:disabled { opacity: .5; cursor: default; }
        button:focus-visible { outline: 3px solid #777; outline-offset: 3px; }
    </style>
</head>
<body>
<main>
    <h1>Scan &amp; pay with UPI</h1>
    <p id="status" role="status">Loading Razorpay…</p>
    <button id="open" disabled>Show UPI QR</button>
    <p>Scan the payment QR using Google Pay, PhonePe, BHIM or another UPI app on your phone.</p>
</main>
<script nonce="{{ $nonce }}">
(() => {
    const options = {!! json_encode($options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!};
    const returnUrl = {!! json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!};
    const button = document.getElementById('open');
    const status = document.getElementById('status');
    let submitting = false;
    let checkout;
    options.handler = result => {
        if (submitting) return;
        submitting = true;
        button.disabled = true;
        status.textContent = 'Returning to Zo Stream to confirm your payment…';
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = returnUrl;
        for (const name of ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']) {
            const field = document.createElement('input');
            field.type = 'hidden'; field.name = name; field.value = result[name] || '';
            form.appendChild(field);
        }
        document.body.appendChild(form);
        form.submit();
    };
    options.modal = { ondismiss: () => { if (!submitting) window.location.replace(returnUrl); } };
    function openCheckout() {
        if (!checkout || submitting) return;
        try {
            checkout.open();
            status.textContent = 'Scan the UPI QR in Razorpay to complete payment.';
        } catch (_) {
            status.textContent = 'Could not open checkout. Try again.';
        }
    }
    button.addEventListener('click', openCheckout);
    const sdk = document.createElement('script');
    sdk.src = 'https://checkout.razorpay.com/v1/checkout.js';
    sdk.onload = () => {
        try {
            checkout = new Razorpay(options);
            button.disabled = false;
            openCheckout();
        } catch (_) {
            status.textContent = 'Payment options are unavailable. Close checkout and try again.';
        }
    };
    sdk.onerror = () => { status.textContent = 'Unable to load Razorpay. Check your connection and reopen checkout.'; };
    document.head.appendChild(sdk);
})();
</script>
</body>
</html>
