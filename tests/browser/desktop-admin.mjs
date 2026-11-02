// All API requests are intercepted. No real admin login, device clearing or settings changes.
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', error => errors.push(error.message));
if (process.env.DEBUG_ADMIN) {
    page.on('console', message => console.log(message.type(), message.text()));
    page.on('request', request => console.log('request', request.url()));
    page.on('pageerror', error => console.log('pageerror', error.message));
}
const devices = [
    { id: 4, user_id: 'alice', device_name: 'Studio Mac', device_type: 'desktop', platform: 'macos', status: 'active' },
    { id: 5, user_id: 'alice', device_name: 'Windows PC', device_type: 'desktop', platform: 'windows', status: 'active' },
];
const plans = [{ id: 7, name: 'Desktop Plan', device_type: 'desktop', price: 149, duration_days: 30, quality: 'HD' }];
await page.route('**/api/v4/**', async route => {
    const url = new URL(route.request().url());
    let data = {};
    if (url.pathname === '/api/v4/admin/dashboard') data = { status: 'success', data: {} };
    if (url.pathname === '/api/v4/admin/devices' || url.pathname === '/api/v4/admin/devices/search') {
        const rows = devices.filter(row => !url.searchParams.get('platform') || row.platform === url.searchParams.get('platform'));
        data = { status: 'success', data: { data: rows, current_page: 1, last_page: 1 } };
    }
    if (url.pathname === '/api/v4/admin/devices/4') data = { status: 'success', data: devices[0] };
    if (url.pathname === '/api/v4/admin/plans') data = { status: 'success', data: { data: plans, last_page: 1 } };
    if (url.pathname === '/api/v4/admin/analytics/config') data = { enabled: false };
    if (url.pathname === '/api/v4/analytic/reports/overview') data = { overview: {}, engagement: {}, watch_trend: [], streaming_health: [], platforms: [], top_content: [], product_events: [] };
    if (url.pathname === '/api/v4/analytic/reports/quality') data = [];
    if (url.pathname === '/api/v4/analytic/reports/presence') data = { online_users: 0, online_devices: 0, platforms: [], devices: [] };
    await route.fulfill({ json: { success: true, data, error: null, meta: {} } });
});
const base = process.env.ADMIN_PREVIEW_URL || 'http://127.0.0.1:5184/tests/browser/desktop-admin.html';
const go = route => page.goto(`${base}#${route}`);
const requestFor = (path, key, value) => page.waitForRequest(request => {
    const url = new URL(request.url());
    return url.pathname.endsWith(path) && url.searchParams.get(key) === value;
});
try {
    await go('/dashboard');
    await page.locator('.admin-filter-bar select').nth(1).waitFor();
    let sent = requestFor('/admin/dashboard', 'device_type', 'desktop');
    await page.locator('.admin-filter-bar select').nth(1).selectOption('desktop'); await sent;
    sent = requestFor('/admin/dashboard', 'chart_device_type', 'desktop');
    await page.getByLabel(/^Device type/).selectOption('desktop'); await sent;

    await go('/manage/devices');
    await page.getByText('Studio Mac', { exact: true }).waitFor();
    await page.getByLabel(/^Device type/).selectOption('desktop');
    sent = requestFor('/admin/devices', 'platform', 'macos');
    await page.getByLabel(/^Desktop platform/).selectOption('macos'); await sent;
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1);
    assert.match(await page.locator('tbody').textContent(), /Mac \(macOS\)/);
    sent = requestFor('/admin/devices/search', 'platform', 'macos');
    await page.locator('.admin-search input').fill('Alice'); await sent;

    await go('/manage/devices/4');
    await page.getByLabel('Platform reported by app').waitFor();
    assert.equal(await page.getByLabel('Platform reported by app').isEditable(), false);
    const saved = page.waitForRequest(request => request.method() === 'PUT' && request.url().endsWith('/admin/devices/4'));
    await page.getByRole('button', { name: 'Save changes' }).click();
    assert.equal('platform' in (await saved).postDataJSON(), false);

    await go('/manage/plans/new');
    await page.getByLabel(/^Device type/).selectOption('desktop');
    await go('/manage/subscriptions/new');
    await page.getByText('Desktop Plan', { exact: true }).waitFor();
    assert.match(await page.locator('.admin-plan-picker header').textContent(), /Desktop \(Mac \/ Windows\)/);

    await go('/analytics');
    await page.getByLabel(/^Platform/).selectOption('macos');
    sent = requestFor('/analytic/reports/overview', 'platform', 'macos');
    await page.getByRole('button', { name: 'Apply filters' }).click(); await sent;
    await page.getByLabel(/^Platform/).selectOption('windows');

    await go('/tools/operations');
    await page.getByLabel(/^Device type/).selectOption('desktop');
    await page.getByLabel(/^Desktop platform/).selectOption('windows');
    const form = page.locator('form').filter({ has: page.getByRole('heading', { name: 'Clear user devices' }) });
    await form.getByLabel('User ID', { exact: true }).fill('fixture-user');
    const cleared = page.waitForRequest(request => request.url().endsWith('/admin/devices/clear'));
    await form.getByRole('button', { name: 'Clear matching devices' }).click();
    assert.equal((await cleared).postDataJSON().platform, 'windows');
    await go('/tools/notifications');
    await page.getByLabel(/^Platform/).selectOption('macos');
    await page.getByLabel(/^Platform/).selectOption('windows');
    assert.deepEqual(errors, []);
    console.log('Vue desktop admin smoke passed: dashboard, devices/search/edit, plans, subscriptions, analytics, tools and warnings.');
} finally { await browser.close(); }
