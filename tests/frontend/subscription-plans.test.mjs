import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadSubscriptionPlans } from '../../resources/js/admin/lib/subscription-plans.js';

test('subscription choices include desktop plans beyond the first API page', async () => {
    const calls = [];
    const mobile = { id: 1, name: 'Mobile', device_type: 'mobile' };
    const desktop = { id: 102, name: 'Desktop', device_type: 'desktop' };
    const result = await loadSubscriptionPlans(async path => {
        calls.push(path);
        const page = Number(new URL(path, 'https://example.invalid').searchParams.get('page'));
        return { data: page === 1 ? [mobile] : [mobile, desktop], last_page: 2, current_page: page };
    });
    assert.deepEqual(result, [mobile, desktop]);
    assert.deepEqual(calls, ['/admin/plans?per_page=100&page=1', '/admin/plans?per_page=100&page=2']);
});

test('accepts legacy paginated and unpaginated plan responses', async () => {
    const plan = { id: 7, device_type: 'desktop' };
    assert.deepEqual(await loadSubscriptionPlans(async () => ({ status: 'success', data: { data: [plan], last_page: 1 } })), [plan]);
    assert.deepEqual(await loadSubscriptionPlans(async () => [plan]), [plan]);
    assert.deepEqual(await loadSubscriptionPlans(async () => ({ data: [], last_page: 1 })), []);
});
