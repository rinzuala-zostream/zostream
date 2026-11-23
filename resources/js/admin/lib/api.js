const API_ROOT = '/api/v4';
const SESSION_KEY = 'zostream_admin_session_v1';

export function getSession() {
    try { return JSON.parse(localStorage.getItem(SESSION_KEY) || 'null'); }
    catch { return null; }
}
export const saveSession = (session) => localStorage.setItem(SESSION_KEY, JSON.stringify(session));
export const clearSession = () => localStorage.removeItem(SESSION_KEY);
export const unwrap = (payload) => payload && Object.hasOwn(payload, 'success') ? payload.data : payload;

function errorMessage(payload, status) {
    const details = payload?.error?.details || payload?.errors || payload?.data?.errors;
    const first = details && typeof details === 'object' ? Object.values(details).flat(Infinity).find(value => typeof value === 'string' && value.trim()) : null;
    return first || payload?.error?.message || payload?.message || payload?.data?.message || `Request failed (${status})`;
}

const pendingGets = new Map();
let refreshFlight = null;

async function refreshSession(session) {
    const latest = getSession();
    if (latest?.access_token && latest.access_token !== session.access_token) {
        if (latest.uid && session.uid && latest.uid !== session.uid) throw new DOMException('Session changed', 'AbortError');
        return latest;
    }
    if (!latest || latest.refresh_token !== session.refresh_token) throw Object.assign(new Error('Session changed. Please sign in again.'), { status: 401 });
    if (refreshFlight?.token === session.refresh_token) return refreshFlight.promise;
    const flight = { token: session.refresh_token };
    flight.promise = (async () => {
        try {
            const refreshed = await api('/auth/tokens/refresh', { method: 'POST', body: { refresh_token: session.refresh_token } }, true);
            const current = getSession();
            if (!current || current.refresh_token !== session.refresh_token) throw new DOMException('Session changed', 'AbortError');
            const next = { ...current, ...(refreshed?.data || refreshed) };
            saveSession(next);
            return next;
        } catch (error) {
            if (getSession()?.refresh_token === session.refresh_token) {
                clearSession();
                window.dispatchEvent(new Event('zostream:session-expired'));
            }
            throw error;
        } finally { if (refreshFlight === flight) refreshFlight = null; }
    })();
    refreshFlight = flight;
    return flight.promise;
}

async function requestJson(path, options, retried) {
    const session = getSession();
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Client-Platform', 'admin');
    headers.set('X-Client-Version', '1.0');
    if (session?.access_token) headers.set('Authorization', `Bearer ${session.access_token}`);
    let body = options.body;
    if (body && !(body instanceof FormData) && typeof body !== 'string') {
        headers.set('Content-Type', 'application/json'); body = JSON.stringify(body);
    }
    const response = await fetch(path.startsWith('http') ? path : `${API_ROOT}${path}`, { ...options, headers, body });
    const payload = await response.json().catch(() => null);
    if (response.status === 401 && !retried && session?.refresh_token && !path.includes('/auth/tokens/refresh')) {
        await refreshSession(session);
        return api(path, options, true);
    }
    const result = unwrap(payload);
    if (!response.ok || payload?.success === false || payload?.status === 'error' || result?.status === 'error') {
        const failure = result?.status === 'error' ? result : payload;
        const error = new Error(errorMessage(failure, response.status));
        error.status = response.status; error.details = failure?.error?.details || failure?.errors;
        throw error;
    }
    return result;
}

export async function api(path, options = {}, retried = false) {
    const method = String(options.method || 'GET').toUpperCase();
    if (method !== 'GET') {
        // A refresh following a write must never reuse a read started before that write.
        pendingGets.clear();
        return requestJson(path, options, retried).finally(() => pendingGets.clear());
    }
    if (options.signal || options.body || options.dedupe === false) return requestJson(path, options, retried);
    const key = JSON.stringify([path, getSession()?.access_token, [...new Headers(options.headers || {})], options.cache, options.credentials, options.mode, retried]);
    let entry = pendingGets.get(key);
    if (!entry) {
        entry = { shared: false };
        entry.promise = requestJson(path, options, retried).then(value => {
            if (pendingGets.get(key) === entry) pendingGets.delete(key);
            return value;
        }, error => {
            if (pendingGets.get(key) === entry) pendingGets.delete(key);
            throw error;
        });
        pendingGets.set(key, entry);
    } else entry.shared = true;
    // Only shared reads need copies; ordinary large reports avoid extra JSON work.
    return entry.promise.then(value => entry.shared && value != null ? JSON.parse(JSON.stringify(value)) : value);
}

export async function apiBlob(path, retried = false) {
    const session = getSession();
    const headers = new Headers({ Accept: '*/*', 'X-Client-Platform': 'admin', 'X-Client-Version': '1.0' });
    if (session?.access_token) headers.set('Authorization', `Bearer ${session.access_token}`);
    const response = await fetch(path.startsWith('http') ? path : `${API_ROOT}${path}`, { headers });
    if (response.status === 401 && !retried && session?.refresh_token) {
        await refreshSession(session);
        return apiBlob(path, true);
    }
    if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(errorMessage(payload, response.status));
    }
    return response.blob();
}

export function queryString(values = {}) {
    const query = new URLSearchParams();
    Object.entries(values).forEach(([key, value]) => {
        if (value !== '' && value != null) query.set(key, value);
    });
    return query.size ? `?${query}` : '';
}
