const SESSION_KEY = 'zostream_public_session_v1';
const DEVICE_KEY = 'zostream_public_device_id';
// App bridge sign-in must keep working when private browser storage is denied.
let memorySession = null;
let memoryDeviceId = null;
let sessionStorageUnavailable = false;

export function getPublicSession() {
    if (sessionStorageUnavailable) return memorySession;
    try { memorySession = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null'); }
    catch { sessionStorageUnavailable = true; }
    return memorySession;
}

export function savePublicSession(session) {
    memorySession = session;
    try { localStorage.setItem(SESSION_KEY, JSON.stringify(session)); }
    catch { sessionStorageUnavailable = true; }
}

export function clearPublicSession() {
    memorySession = null;
    try { localStorage.removeItem(SESSION_KEY); }
    catch { sessionStorageUnavailable = true; }
}

export function publicDeviceId() {
    let value = memoryDeviceId;
    try { value = localStorage.getItem(DEVICE_KEY) || value; }
    catch { /* Use a stable device ID for this tab when storage is blocked. */ }
    if (!value) {
        value = globalThis.crypto?.randomUUID?.() || `web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        try { localStorage.setItem(DEVICE_KEY, value); }
        catch { /* Retain the ID in memory instead. */ }
    }
    memoryDeviceId = value;
    return value;
}

export function publicHeaders(json = false) {
    const headers = new Headers({
        Accept: 'application/json',
        'X-Client-Platform': 'web',
        'X-Client-Version': '1.0',
        'X-Device-Type': 'browser',
    });
    if (json) headers.set('Content-Type', 'application/json');
    const session = getPublicSession();
    if (session?.access_token) headers.set('Authorization', `Bearer ${session.access_token}`);
    return headers;
}

export async function authenticatedFetch(path, options = {}, retried = false) {
    const headers = new Headers(options.headers || publicHeaders(false));
    const session = getPublicSession();
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    headers.set('X-Client-Platform', 'web');
    headers.set('X-Client-Version', '1.0');
    headers.set('X-Device-Type', 'browser');
    if (session?.access_token) headers.set('Authorization', `Bearer ${session.access_token}`);

    const response = await fetch(path, { ...options, headers });
    if (response.status !== 401 || retried || !session?.refresh_token) return response;

    const refresh = await fetch('/api/v4/auth/tokens/refresh', {
        method: 'POST',
        headers: publicHeaders(true),
        body: JSON.stringify({ refresh_token: session.refresh_token }),
    });
    const payload = await refresh.json().catch(() => null);
    const tokens = payload?.data || payload;
    if (!refresh.ok || !tokens?.access_token) {
        clearPublicSession();
        window.dispatchEvent(new Event('zostream:public-session-expired'));
        return response;
    }

    savePublicSession({ ...session, ...tokens });
    return authenticatedFetch(path, options, true);
}

