const KEY = 'zostream_creator_session_v1';
export function getSession() {
    try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch { return null; }
}
export const saveSession = session => localStorage.setItem(KEY, JSON.stringify(session));
export const clearSession = () => localStorage.removeItem(KEY);
export function deviceId() {
    let id = localStorage.getItem('zostream_creator_device_id');
    if (!id) { id = crypto.randomUUID(); localStorage.setItem('zostream_creator_device_id', id); }
    return id;
}
// Existing Zo Stream auth tokens; separate browser storage keeps admin sessions intact.
export async function api(path, options = {}, retry = true) {
    const session = getSession();
    const multipart = options.body instanceof FormData;
    const response = await fetch(`/api/v4${path}`, {
        ...options,
        headers: { Accept: 'application/json', ...(multipart ? {} : { 'Content-Type': 'application/json' }), 'X-Client-Platform': 'web', 'X-Device-Type': 'browser', 'X-Client-Version': '1.0',
            ...(session?.access_token ? { Authorization: `Bearer ${session.access_token}` } : {}) },
        ...(options.body ? { body: multipart ? options.body : JSON.stringify(options.body) } : {}),
    });
    const payload = await response.json().catch(() => null);
    if (response.status === 401 && retry && session?.refresh_token && !path.startsWith('/auth/')) {
        try {
            const refreshed = await api('/auth/tokens/refresh', { method: 'POST', body: { refresh_token: session.refresh_token } }, false);
            saveSession({ ...session, ...(refreshed?.data || refreshed) });
        } catch {
            clearSession(); window.dispatchEvent(new Event('zostream:creator-expired'));
            throw new Error('Your session expired. Sign in again.');
        }
        return api(path, options, false);
    }
    if (response.status === 401 && !path.startsWith('/auth/')) {
        clearSession(); window.dispatchEvent(new Event('zostream:creator-expired'));
    }
    if (!response.ok || payload?.success === false) {
        const details = payload?.error?.details;
        throw new Error((details && Object.values(details).flat()[0]) || payload?.error?.message || payload?.message || `Request failed (${response.status})`);
    }
    return payload?.success !== undefined ? payload.data : payload;
}
export const authData = value => value?.data || value;
