import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { api, apiBlob, getSession, saveSession } from '../../resources/js/admin/lib/api.js';
const previousFetch = globalThis.fetch;
const storage = new Map(); const events = [];
globalThis.localStorage = { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
globalThis.window = { dispatchEvent: event => events.push(event.type) };
const reply = data => new Response(JSON.stringify({ success: true, data }));
const tick = () => new Promise(setImmediate);
afterEach(() => { globalThis.fetch = previousFetch; storage.clear(); events.length = 0; });

test('concurrent identical reads share one request and receive independent records', async () => {
    let finish; let calls = 0;
    globalThis.fetch = () => { calls++; return new Promise(resolve => { finish = resolve; }); };
    const first = api('/admin/users/viewer'); const second = api('/admin/users/viewer');
    assert.equal(calls, 1);
    finish(reply({ uid: 'viewer', nested: { name: 'Original' } }));
    const [a, b] = await Promise.all([first, second]); a.nested.name = 'Changed';
    assert.equal(b.nested.name, 'Original');
    globalThis.fetch = async () => reply({ uid: 'fresh' });
    assert.equal((await api('/admin/users/viewer')).uid, 'fresh');
});

test('a post-save refresh cannot reuse a pre-save read or lose the new in-flight read', async () => {
    const reads = []; let calls = 0;
    globalThis.fetch = (path, options) => options.method === 'PUT' ? Promise.resolve(reply({ saved: true })) : new Promise(resolve => { calls++; reads.push(resolve); });
    const old = api('/admin/users/viewer');
    await api('/admin/users/viewer', { method: 'PUT', body: { name: 'New' } });
    const fresh = api('/admin/users/viewer');
    reads[0](reply({ name: 'Old' })); await old;
    const joined = api('/admin/users/viewer'); assert.equal(calls, 2);
    reads[1](reply({ name: 'New' }));
    assert.deepEqual(await Promise.all([fresh, joined]), [{ name: 'New' }, { name: 'New' }]);
});

test('JSON and media requests share a single refresh and retry with the new token', async () => {
    saveSession({ uid: 'admin', access_token: 'old', refresh_token: 'refresh-old' });
    let refreshes = 0; let finish;
    globalThis.fetch = async (path, options) => {
        if (path.endsWith('/auth/tokens/refresh')) { refreshes++; return new Promise(resolve => { finish = resolve; }); }
        if (options.headers.get('Authorization') === 'Bearer old') return new Response('{}', { status: 401 });
        return path.endsWith('/media') ? new Response('image') : reply({ ok: true });
    };
    const requests = [api('/admin/a'), api('/admin/b'), apiBlob('/admin/media')];
    await tick(); assert.equal(refreshes, 1);
    finish(reply({ access_token: 'new', refresh_token: 'refresh-new' }));
    const values = await Promise.all(requests);
    assert.equal(values[0].ok, true); assert.equal(await values[2].text(), 'image');
    assert.equal(getSession().access_token, 'new'); assert.deepEqual(events, []);
});

test('a failed shared refresh expires the session once', async () => {
    saveSession({ uid: 'admin', access_token: 'old', refresh_token: 'refresh-old' });
    let refreshes = 0; let finish;
    globalThis.fetch = async path => {
        if (path.endsWith('/auth/tokens/refresh')) { refreshes++; return new Promise(resolve => { finish = resolve; }); }
        return new Response('{}', { status: 401 });
    };
    const settled = Promise.allSettled([api('/admin/a'), api('/admin/b')]);
    await tick(); finish(new Response('{}', { status: 401 })); await settled;
    assert.equal(refreshes, 1); assert.equal(getSession(), null);
    assert.deepEqual(events, ['zostream:session-expired']);
});

test('cancelling an old search while refresh is pending does not log out the other requests', async () => {
    saveSession({ uid: 'admin', access_token: 'old', refresh_token: 'refresh-old' });
    let finish;
    globalThis.fetch = async (path, options) => {
        if (path.endsWith('/auth/tokens/refresh')) return new Promise(resolve => { finish = resolve; });
        if (options.signal?.aborted) throw new DOMException('Cancelled', 'AbortError');
        return options.headers.get('Authorization') === 'Bearer old' ? new Response('{}', { status: 401 }) : reply({ ok: true });
    };
    const controller = new AbortController();
    const settled = Promise.allSettled([api('/admin/search', { signal: controller.signal }), api('/admin/detail')]);
    await tick(); controller.abort(); finish(reply({ access_token: 'new', refresh_token: 'refresh-new' }));
    const results = await settled;
    assert.equal(results[0].reason.name, 'AbortError'); assert.equal(results[1].status, 'fulfilled');
    assert.equal(getSession().access_token, 'new'); assert.deepEqual(events, []);
});

test('a late token refresh cannot restore a signed-out session', async () => {
    saveSession({ uid: 'admin', access_token: 'old', refresh_token: 'refresh-old' });
    let finish;
    globalThis.fetch = async path => path.endsWith('/auth/tokens/refresh') ? new Promise(resolve => { finish = resolve; }) : new Response('{}', { status: 401 });
    const request = api('/admin/detail'); const checked = assert.rejects(request, error => error.name === 'AbortError');
    await tick(); storage.clear(); finish(reply({ access_token: 'new', refresh_token: 'refresh-new' }));
    await checked; assert.equal(getSession(), null); assert.deepEqual(events, []);
});
