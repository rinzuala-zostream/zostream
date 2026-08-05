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
    const details = payload?.error?.details;
    const first = details && typeof details === 'object' ? Object.values(details).flat()[0] : null;
    return first || payload?.error?.message || payload?.message || payload?.data?.message || `Request failed (${status})`;
}

export async function api(path, options = {}, retried = false) {
    const session = getSession();
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Client-Platform', 'admin');
    headers.set('X-Client-Version', '1.0');
    if (session?.access_token) headers.set('Authorization', `Bearer ${session.access_token}`);
    let body = options.body;
    if (body && !(body instanceof FormData) && typeof body !== 'string') {
        headers.set('Content-Type', 'application/json');
        body = JSON.stringify(body);
    }
    const response = await fetch(path.startsWith('http') ? path : `${API_ROOT}${path}`, { ...options, headers, body });
    const payload = await response.json().catch(() => null);
    if (response.status === 401 && !retried && session?.refresh_token && !path.includes('/auth/tokens/refresh')) {
        try {
            const refreshed = await api('/auth/tokens/refresh', { method: 'POST', body: { refresh_token: session.refresh_token } }, true);
            saveSession({ ...session, ...(refreshed?.data || refreshed) });
            return api(path, options, true);
        } catch {
            clearSession();
            window.dispatchEvent(new Event('zostream:session-expired'));
        }
    }
    if (!response.ok || payload?.success === false) {
        const error = new Error(errorMessage(payload, response.status));
        error.status = response.status; error.details = payload?.error?.details;
        throw error;
    }
    return unwrap(payload);
}

export function queryString(values = {}) {
    const query = new URLSearchParams();
    Object.entries(values).forEach(([key, value]) => {
        if (value !== '' && value != null) query.set(key, value);
    });
    return query.size ? `?${query}` : '';
}
