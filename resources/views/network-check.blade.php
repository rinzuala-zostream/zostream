<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Zo Stream Network Check</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f6fc;color:#17243b;font:17px/1.6 system-ui,sans-serif;padding:28px 18px}main{max-width:620px;margin:30px auto;background:white;border:1px solid #dce4f0;border-radius:20px;padding:28px}h1{font-size:27px;line-height:1.2}h2{font-size:18px}p{margin:16px 0}.muted{color:#56647b}.reference{font-family:monospace;overflow-wrap:anywhere;background:#eef3fb;padding:12px;border-radius:8px}button{background:#176ce3;color:white;border:0;border-radius:9px;padding:12px 18px;font:inherit;cursor:pointer}button:disabled{opacity:.6}li{margin:8px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}#status{font-weight:650}details{margin-top:20px}
    </style>
</head>
<body>
<main>
    <h1>Zo Stream Network Check</h1>
    <p>Buaina nei Wi-Fi/network kha hmang reng rawh. He page hian connection a test anga, result chu support log-ah a save ang.</p>
    <p class="muted">This records your IP address, browser information and connection test results. No login or OTP is needed.</p>
    <h2>Support reference</h2>
    <p class="reference">{{ $id }}</p>
    <p id="status" role="status" aria-live="polite">Checking connection…</p>
    <ol id="tests"></ol>
    <button id="retry" type="button" disabled>Run again</button>
    <p class="muted">Reference ID hi support hnenah pe rawh. Browser test a pass pawhin app connection a tha tih a confirm lo. He domain a hawng theih loh chuan page/report pawh a thleng thei lo vang.</p>
    <noscript>JavaScript is needed to run the tests. Your page visit has been logged with the reference above.</noscript>
    <details><summary>Test details / copy if saving fails</summary><pre id="details"></pre></details>
</main>
<script>
(() => {
    const reference = @json($id);
    const probeUrl = @json($probeUrl);
    const reportUrl = @json($reportUrl);
    const csrf = @json(csrf_token());
    const status = document.getElementById('status');
    const list = document.getElementById('tests');
    const retry = document.getElementById('retry');
    const details = document.getElementById('details');

    async function request(url, options = {}) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(url, {...options, signal: controller.signal, cache: 'no-store', credentials: 'same-origin'});
            // Keep the HTTP status even when a proxy returns an HTML error page.
            const body = await response.json().catch(() => null);
            return {response, body};
        } finally {
            clearTimeout(timer);
        }
    }

    async function run() {
        retry.disabled = true;
        list.replaceChildren();
        status.textContent = 'Checking connection…';
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        const navigation = performance.getEntriesByType('navigation')[0];
        const report = {
            online: navigator.onLine,
            connection_type: connection?.type || null,
            effective_type: connection?.effectiveType || null,
            navigation_ms: navigation ? Math.min(3600000, Math.round(navigation.duration)) : null,
            tests: []
        };
        for (let i = 0; i < 3; i++) {
            const started = performance.now();
            const test = {ok: false, status: null, duration_ms: 0, error: null};
            try {
                const {response, body} = await request(probeUrl, {headers: {'Accept': 'application/json'}});
                test.status = response.status;
                test.ok = response.ok && body?.ok === true && body?.reference === reference;
                if (!test.ok) test.error = 'unexpected_response';
            } catch (error) {
                test.error = error.name === 'AbortError' ? 'timeout' : 'network_error';
            }
            test.duration_ms = Math.min(60000, Math.round(performance.now() - started));
            report.tests.push(test);
            const item = document.createElement('li');
            item.textContent = `Test ${i + 1}: ${test.ok ? 'Connected' : test.error} (${test.duration_ms} ms)`;
            list.appendChild(item);
        }
        details.textContent = JSON.stringify({reference, ...report}, null, 2);
        status.textContent = 'Saving results…';
        try {
            const {response, body} = await request(reportUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify(report)
            });
            if (!response.ok || body?.saved !== true || body?.reference !== reference) throw new Error('save_failed');
            const passed = report.tests.filter(test => test.ok).length;
            status.textContent = `Result saved. ${passed}/3 tests connected. Reference ID hi support hnenah pe rawh.`;
        } catch (_) {
            status.textContent = 'Result save a hlawhchham. Test details kha copy/screenshot la, support hnenah pe rawh. Network tha leh hunah Run again hmet rawh.';
        } finally {
            retry.disabled = false;
        }
    }
    retry.addEventListener('click', run);
    window.addEventListener('load', run, {once: true});
})();
</script>
</body>
</html>
