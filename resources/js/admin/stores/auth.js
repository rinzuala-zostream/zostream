import { computed, reactive } from 'vue';
import { api, clearSession, getSession, saveSession } from '../lib/api';

const state = reactive({ session: getSession(), busy: false, error: '' });
function deviceId() {
    let id = localStorage.getItem('zostream_admin_device_id');
    if (!id) {
        id = crypto.randomUUID?.() || `web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        localStorage.setItem('zostream_admin_device_id', id);
    }
    return id;
}
export function useAuth() {
    function acceptSession(session) {
        if (!session?.access_token || !session?.refresh_token) {
            throw new Error('Login session was not returned.');
        }
        state.session = session;
        state.error = '';
        saveSession(session);
        return session;
    }

    async function requestOtp({ countryCode, phoneNumber, userId }) {
        state.busy = true; state.error = '';
        try {
            const response = await api('/auth/admin/otp/request', { method: 'POST', body: { country_code: countryCode, phone_number: phoneNumber, user_id: userId || undefined } });
            return response?.data || response;
        } catch (error) { state.error = error.message; throw error; }
        finally { state.busy = false; }
    }
    async function verifyOtp({ userId, otp }) {
        state.busy = true; state.error = '';
        try {
            const response = await api('/auth/otp/verify', { method: 'POST', body: { user_id: userId, otp, device_id: deviceId(), device_name: navigator.userAgent.slice(0, 120), device_type: 'browser' } });
            const session = response?.data || response;
            if (!session?.access_token || !session?.refresh_token) throw new Error('Login session was not returned.');
            return acceptSession({ ...session, uid: session.uid || userId });
        } catch (error) { state.error = error.message; throw error; }
        finally { state.busy = false; }
    }
    async function logout() {
        try { if (state.session?.access_token) await api('/auth/logout', { method: 'POST' }); } catch {}
        clearSession(); state.session = null;
    }
    return { state, authenticated: computed(() => Boolean(state.session?.access_token)), acceptSession, requestOtp, verifyOtp, logout };
}
