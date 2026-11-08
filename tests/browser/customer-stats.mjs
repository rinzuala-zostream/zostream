// Uses mocked account data only. See docs/MY_STATS.md for the preview/test commands.
import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const engines = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await engines[process.env.STATS_BROWSER || 'chromium'].launch({ headless: true });
const context = await browser.newContext({ acceptDownloads: true });
await context.addInitScript(() => localStorage.setItem('zostream_public_session_v1', JSON.stringify({ access_token: 'fixture-token' })));
const page = await context.newPage();
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let failWatching = true;
const titles = [
    { type: 'movie', content_id: 'a', title: 'Alpha', seconds_watched: 3660, bandwidth_mb: 512, last_watched_at: '2026-11-01T08:00:00Z', watch_url: 'https://app.zostream.in/watch/a' },
    { type: 'episode', content_id: 'b', title: 'The Hills', subtitle: 'Episode Two', seconds_watched: 1800, bandwidth_mb: null, last_watched_at: '2026-11-01T09:00:00Z' },
    { type: 'movie', content_id: 'c', title: 'Beta', seconds_watched: 420, bandwidth_mb: 0, last_watched_at: '2026-11-01T07:00:00Z' },
];
const stats = { period: { from: '2026-11-01', to: '2026-11-01' }, watch_seconds: 5880, viewed_titles: 3, bandwidth_mb: 512,
    most_watched: titles, activity: [{ date: '2026-11-01', seconds_watched: 5880 }] };
async function statsRoute(route) {
    const url = new URL(route.request().url());
    const path = url.pathname.split('/stats/')[1];
    if (path === 'payments/export') return route.fulfill({ contentType: 'text/csv', body: 'Plan,Amount\nWeekly Mobile,99\n' });
    if (path.endsWith('/receipt')) return route.fulfill({ contentType: 'application/pdf', body: '%PDF-1.4\nfixture' });
    let data;
    if (path === 'overview') data = { profile: { name: 'Lalhminga', username: 'lalhminga' }, stats,
        continue_watching: [{ id: 1, movie_id: 'a', movie: { title: 'Alpha' }, position: 1200, duration: 5400 }] };
    if (path === 'watching') {
        if (failWatching) { failWatching = false; return route.fulfill({ status: 503, json: { message: 'History is temporarily unavailable.' } }); }
        const items = titles.filter(item => (!url.searchParams.get('q') || item.title.toLowerCase().includes(url.searchParams.get('q').toLowerCase())) && (url.searchParams.get('type') === 'all' || item.type === url.searchParams.get('type')));
        data = { ...stats, content_usage: items, content_pagination: { page: 1, last_page: 1, total: items.length } };
    }
    if (path === 'account') data = { subscriptions: [{ id: 1, plan: 'Weekly Mobile', device_type: 'mobile', status: 'active', valid_from: '2026-11-01', valid_until: '2026-11-07' }],
        devices: [{ id: 1, name: 'My Android phone', type: 'mobile', status: 'active', owner: true, last_active: '2026-11-01T09:00:00Z' }] };
    if (path === 'payments') {
        const items = ['paid', 'pending', 'failed'].map((status, index) => ({ id: index + 1, plan: 'Weekly Mobile', receipt_number: `INV-000000000${index + 1}`, status, device_type: 'mobile',
            amount: 99, currency: 'INR', payment_date: '2026-11-01T09:00:00Z', valid_until: '2026-11-07', payment_type: 'renew', transaction_id: `transaction-${index + 1}`, payment_method: 'upi', payment_gateway: 'razorpay', receipt_available: status === 'paid' }))
            .filter(row => url.searchParams.get('status') === 'all' || row.status === url.searchParams.get('status'));
        data = { period: stats.period, items, pagination: { page: 1, last_page: 1, total: items.length } };
    }
    return route.fulfill({ json: { success: true, data, message: null, meta: {}, error: null } });
}
await page.route('**/api/v4/account/stats/**', statsRoute);
try {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(process.env.STATS_PREVIEW_URL || 'http://127.0.0.1:5179/tests/browser/customer-stats.html');
    await page.getByText('Hello, Lalhminga').waitFor();
    assert.equal(await page.getByText('@lalhminga').count(), 1);
    await page.getByRole('heading', { name: 'Weekly Mobile' }).waitFor();
    await page.screenshot({ path: join(tmpdir(), 'zostream-stats-overview-desktop.png'), fullPage: true });
    await page.getByRole('button', { name: 'Watching', exact: true }).click();
    await page.getByRole('button', { name: 'Retry history' }).click();
    await page.locator('.watch-record').first().waitFor();
    assert.equal(await page.locator('.watch-record').count(), 3);
    await page.getByLabel('Search titles').fill('Beta');
    await page.waitForFunction(() => document.querySelectorAll('.watch-record').length === 1);
    assert.match(await page.locator('.watch-record').textContent(), /Beta/);
    await page.getByLabel('Show activity for').selectOption('custom');
    await page.getByLabel('From', { exact: true }).fill('2026-10-01');
    await page.getByLabel('To', { exact: true }).fill('2026-10-31');
    const queryRequest = page.waitForRequest(request => request.url().includes('/watching?') && request.url().includes('from=2026-10-01'));
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await queryRequest;
    await page.getByRole('button', { name: 'Account', exact: true }).click();
    await page.locator('.payment-record').first().waitFor();
    assert.equal(await page.locator('.payment-record').count(), 3);
    await page.locator('.payment-record').first().locator('summary').click();
    const pdfReady = page.waitForEvent('download');
    await page.getByRole('button', { name: '↓ Download receipt (PDF)' }).click();
    assert.match((await pdfReady).suggestedFilename(), /\.pdf$/);
    const csvReady = page.waitForEvent('download');
    await page.getByRole('button', { name: '↓ Download CSV' }).click();
    assert.match((await csvReady).suggestedFilename(), /\.csv$/);
    await page.getByLabel('Payment status', { exact: true }).selectOption('paid');
    await page.waitForFunction(() => document.querySelectorAll('.payment-record').length === 1);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: join(tmpdir(), 'zostream-stats-account-mobile.png'), fullPage: true });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Mobile account must not overflow');
    await page.getByRole('button', { name: 'Overview', exact: true }).click();
    await page.getByText('Hello, Lalhminga').waitFor();
    await page.screenshot({ path: join(tmpdir(), 'zostream-stats-overview-mobile.png'), fullPage: true });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Mobile overview must not overflow');
    assert.deepEqual(errors, []);
    for (const blockedStorage of [false, true]) {
        const appContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        await appContext.addInitScript(blocked => {
            if (blocked) Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Storage blocked', 'SecurityError'); } });
            else localStorage.setItem('zostream_public_session_v1', JSON.stringify({ access_token: 'stale-browser-token' }));
        }, blockedStorage);
        const appPage = await appContext.newPage();
        const appErrors = [];
        const apiRequests = [];
        appPage.on('pageerror', error => appErrors.push(error.message));
        appPage.on('request', request => { if (request.url().includes('/api/v4/account/stats/')) apiRequests.push(request.url()); });
        await appPage.route('**/account/stats/**', statsRoute);
        const preview = process.env.STATS_PREVIEW_URL || 'http://127.0.0.1:5179/tests/browser/customer-stats.html';
        await appPage.goto(`${preview}?app=1`);
        await appPage.getByText('Hello, Lalhminga').waitFor();
        assert.deepEqual(appErrors, [], 'App sign-in must work when browser storage is blocked');
        assert.deepEqual(apiRequests, [], 'App sign-in must use its server session instead of a stale browser token');
        await appContext.close();
    }
    console.log('Browser checks passed: tabs, retries, search, dates, payment filters, PDF/CSV downloads, and mobile layout.');
} catch (error) {
    await page.screenshot({ path: join(tmpdir(), 'zostream-stats-test-failure.png'), fullPage: true });
    if (errors.length) console.error(errors);
    throw error;
} finally { await browser.close(); }
