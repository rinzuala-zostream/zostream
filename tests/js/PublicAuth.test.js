import test from 'node:test';
import assert from 'node:assert/strict';
import { getPublicSession, savePublicSession, clearPublicSession, publicDeviceId, authenticatedFetch } from '../../resources/js/lib/publicAuth.js';

test('blocked browser storage does not interrupt app bridge sign-in or browser authentication', async () => {
    const storageDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
    const originalFetch = globalThis.fetch;
    Object.defineProperty(globalThis, 'localStorage', { configurable: true, get() { throw new DOMException('Storage blocked', 'SecurityError'); } });
    try {
        assert.equal(getPublicSession(), null);
        assert.doesNotThrow(() => clearPublicSession());
        const id = publicDeviceId();
        assert.ok(id);
        assert.equal(publicDeviceId(), id);
        savePublicSession({ access_token: 'test-token' });
        globalThis.fetch = async (_path, options) => {
            assert.equal(options.headers.get('Authorization'), 'Bearer test-token');
            return new Response('{}');
        };
        assert.equal((await authenticatedFetch('/api/v4/account/stats/overview')).status, 200);
        clearPublicSession();
        assert.equal(getPublicSession(), null);
    } finally {
        globalThis.fetch = originalFetch;
        if (storageDescriptor) Object.defineProperty(globalThis, 'localStorage', storageDescriptor);
        else delete globalThis.localStorage;
    }
});
