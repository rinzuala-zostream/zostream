<script setup>
import QRCodeStyling from 'qr-code-styling';
import { onValue } from 'firebase/database';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { qrSessionRef } from '../lib/firebase';
import { useAuth } from '../stores/auth';
import StatusPanel from './StatusPanel.vue';

const router = useRouter();
const auth = useAuth();
const mount = ref(null); const token = ref(''); const seconds = ref(0); const error = ref('');
const state = ref('Creating secure session…'); const sessionStatus = ref('creating'); const realtime = ref(false);
let qr; let timer; let unsubscribe; let completed = false;

function deviceId() {
    let value = localStorage.getItem('zostream_admin_device_id');
    if (!value) {
        value = crypto.randomUUID?.() || `web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        localStorage.setItem('zostream_admin_device_id', value);
    }
    return value;
}

function stopSessionWatchers() {
    clearInterval(timer);
    unsubscribe?.(); unsubscribe = undefined; realtime.value = false;
}

async function completeLogin(value) {
    if (completed) return;
    const session = value?.response?.data;
    if (!session?.access_token || !session?.refresh_token) {
        error.value = 'Approved QR session did not include login tokens.';
        return;
    }
    completed = true; stopSessionWatchers();
    try {
        auth.acceptSession({ ...session, uid: session.uid || value.user_id });
        // Do not leave the QR screen until the API that issued the token has
        // accepted it and confirmed admin access. This prevents an approved
        // Firebase response with an unusable/stale token from briefly opening
        // the dashboard and immediately bouncing back to login.
        await api('/admin/dashboard?period=monthly');
        await router.replace('/dashboard');
    } catch (reason) {
        await auth.logout();
        completed = false;
        error.value = reason?.status === 401 || reason?.status === 403
            ? 'QR approval could not be authenticated. Generate a new code and approve it again.'
            : reason instanceof Error ? reason.message : 'Could not open the authenticated session.';
    }
}

function applySession(value) {
    if (!value) {
        sessionStatus.value = 'ready';
        state.value = 'QR is ready. Waiting for a mobile device to scan it.';
        return;
    }
    const status = value.status || value.session_status || 'initialized';
    sessionStatus.value = status;
    if (status === 'initialized') state.value = 'QR is ready. Waiting for a mobile device to scan it.';
    if (status === 'pending') state.value = 'QR scanned. Waiting for approval on the mobile device.';
    if (status === 'completed') {
        state.value = 'Login approved. Opening the dashboard…';
        void completeLogin(value);
    }
    if (status === 'failed' || status === 'expired') {
        error.value = value.response?.message || `QR session ${status}.`;
    }
}

function subscribeToFirebase(value) {
    unsubscribe?.();
    try {
        unsubscribe = onValue(
            qrSessionRef(value),
            (snapshot) => {
                realtime.value = true;
                applySession(snapshot.val());
            },
            () => {
                realtime.value = false;
                error.value = 'Realtime QR connection failed. Generate a new code and try again.';
            },
        );
    } catch {
        realtime.value = false;
        error.value = 'Realtime QR connection could not be started.';
    }
}

async function create() {
    stopSessionWatchers(); completed = false; error.value = ''; sessionStatus.value = 'creating'; state.value = 'Creating secure session…';
    try {
        const response = await api('/admin/qr-sessions', { method: 'POST', body: { device_type: 'browser', device_id: deviceId(), device_name: navigator.userAgent.slice(0, 120) } });
        token.value = response.token; seconds.value = Number(response.expires_in || 120);
        sessionStatus.value = 'ready';
        state.value = 'QR is ready. Waiting for a mobile device to scan it.';
        timer = setInterval(() => {
            seconds.value = Math.max(0, seconds.value - 1);
            if (!seconds.value) { clearInterval(timer); unsubscribe?.(); }
        }, 1000);
        subscribeToFirebase(token.value);
    } catch (reason) { error.value = reason.message; }
}

watch(token, async (value) => {
    await nextTick();
    if (!value || !mount.value) return;
    if (!qr) {
        qr = new QRCodeStyling({ width: 220, height: 220, type: 'svg', data: value, margin: 8, image: '/images/zostream-logo.jpg', dotsOptions: { type: 'rounded', color: '#0d2b2e' }, cornersSquareOptions: { type: 'extra-rounded', color: '#0d2b2e' }, backgroundOptions: { color: '#ffffff' }, imageOptions: { margin: 5, imageSize: .25 } });
        qr.append(mount.value);
    } else qr.update({ data: value });
});

onMounted(create);
onBeforeUnmount(stopSessionWatchers);
</script>

<template>
    <div class="admin-qr-login">
        <StatusPanel tone="error" :message="error" />
        <div v-if="token" ref="mount" class="admin-qr-code" /><div v-else class="admin-qr-loading">Preparing QR…</div>
        <strong class="admin-qr-status" :class="`is-${sessionStatus}`">
            {{ sessionStatus === 'pending' ? 'Scanned' : sessionStatus === 'completed' ? 'Approved' : sessionStatus === 'failed' || sessionStatus === 'expired' ? 'Ended' : sessionStatus === 'creating' ? 'Preparing' : 'Waiting for scan' }}
        </strong>
        <p>{{ state }}</p>
        <span v-if="seconds">Expires in {{ seconds }} seconds · {{ realtime ? 'Realtime connected' : 'Connecting realtime…' }}</span>
        <button v-if="!seconds || error" class="admin-secondary" @click="create">Generate a new code</button>
    </div>
</template>
