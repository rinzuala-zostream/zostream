import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { api } from '../../resources/js/admin/lib/api.js';
const previousFetch = globalThis.fetch;
const previousStorage = globalThis.localStorage;
globalThis.localStorage = { getItem: () => null };
afterEach(() => { globalThis.fetch = previousFetch; });
process.on('exit', () => { globalThis.localStorage = previousStorage; });

test('Laravel validation failures show the field message rather than a generic error', async () => {
    globalThis.fetch = async () => new Response(JSON.stringify({ message: 'Validation failed', errors: { name: ['A name is required.'] } }), { status: 422 });
    await assert.rejects(api('/admin/users', { method: 'POST', body: {} }), error => error.message === 'A name is required.' && error.status === 422);
});

test('legacy business failures returned with HTTP 200 cannot be reported as successful saves', async () => {
    for (const payload of [{ status: 'error', message: 'Save was rejected.' }, { success: true, data: { status: 'error', message: 'Save was rejected.' } }]) {
        globalThis.fetch = async () => new Response(JSON.stringify(payload));
        await assert.rejects(api('/admin/subscriptions', { method: 'POST', body: {} }), /Save was rejected/);
    }
});

test('V4 validation details are displayed and successful data still unwraps normally', async () => {
    globalThis.fetch = async () => new Response(JSON.stringify({ success: false, error: { message: 'Invalid data', details: { amount: ['Amount must be positive.'] } } }), { status: 422 });
    await assert.rejects(api('/admin/subscriptions'), /Amount must be positive/);
    globalThis.fetch = async () => new Response(JSON.stringify({ success: true, data: { id: 7 } }));
    assert.deepEqual(await api('/admin/users/7'), { id: 7 });
});
