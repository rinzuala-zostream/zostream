<script setup>
import { computed, ref } from 'vue';
import { publicDeviceId, savePublicSession } from '../lib/publicAuth';

const emit = defineEmits(['authenticated']);
const countryCode = ref('+91');
const phone = ref('');
const otp = ref('');
const userId = ref('');
const step = ref('phone');
const busy = ref(false);
const error = ref('');
const canRequest = computed(() => /^\d{6,15}$/.test(phone.value.replace(/\D/g, '')));
const canVerify = computed(() => userId.value && /^\d{6}$/.test(otp.value));

async function requestOtp() {
    busy.value = true; error.value = '';
    try {
        const response = await fetch('/api/v4/auth/otp/request', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Client-Platform': 'web', 'X-Client-Version': '1.0', 'X-Device-Type': 'browser' },
            body: JSON.stringify({
                country_code: countryCode.value,
                phone_number: phone.value.replace(/\D/g, ''),
                device_id: publicDeviceId(),
                device_name: navigator.userAgent.slice(0, 120),
                device_type: 'browser',
            }),
        });
        const payload = await response.json().catch(() => null);
        const data = payload?.data || payload;
        if (!response.ok || payload?.success === false || data?.status === 'error' || !data?.user_id) throw new Error(payload?.error?.message || data?.message || 'OTP thawn theih lo.');
        userId.value = data.user_id;
        step.value = 'otp';
    } catch (reason) { error.value = reason.message || 'OTP thawn theih lo.'; }
    finally { busy.value = false; }
}

async function verifyOtp() {
    busy.value = true; error.value = '';
    try {
        const response = await fetch('/api/v4/auth/otp/verify', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Client-Platform': 'web', 'X-Client-Version': '1.0', 'X-Device-Type': 'browser' },
            body: JSON.stringify({ user_id: userId.value, otp: otp.value, device_id: publicDeviceId(), device_name: navigator.userAgent.slice(0, 120), device_type: 'browser' }),
        });
        const payload = await response.json().catch(() => null);
        const legacy = payload?.data || payload;
        const session = legacy?.data || legacy;
        if (!response.ok || payload?.success === false || legacy?.status === 'error' || !session?.access_token) throw new Error(payload?.error?.message || legacy?.message || 'OTP verify theih lo.');
        const saved = { ...session, uid: session.uid || userId.value };
        savePublicSession(saved);
        emit('authenticated', saved);
    } catch (reason) { error.value = reason.message || 'OTP verify theih lo.'; }
    finally { busy.value = false; }
}
</script>

<template>
    <section class="public-login-card">
        <span>LOGIN REQUIRED</span>
        <h1>Advertise tur chuan login rawh</h1>
        <p>I Zo Stream account WhatsApp number hmangin OTP kan thawn ang. Submission leh payment chu i account nen kan link ang.</p>
        <p v-if="error" class="login-error">{{ error }}</p>
        <form v-if="step === 'phone'" @submit.prevent="requestOtp">
            <label>Country code<input v-model.trim="countryCode" inputmode="tel" maxlength="5" required></label>
            <label>WhatsApp phone<input v-model.trim="phone" inputmode="tel" maxlength="15" required></label>
            <button class="primary-button" :disabled="busy || !canRequest">{{ busy ? 'Sending…' : 'WhatsApp OTP send' }}</button>
        </form>
        <form v-else @submit.prevent="verifyOtp">
            <label>6-digit OTP<input v-model.trim="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label>
            <button class="primary-button" :disabled="busy || !canVerify">{{ busy ? 'Verifying…' : 'Login' }}</button>
            <button type="button" class="login-back" @click="step = 'phone'; otp = ''">Phone number thlak</button>
        </form>
    </section>
</template>

<style scoped>
.public-login-card{width:min(100%,620px);margin:60px auto 110px;padding:clamp(25px,5vw,48px);border:1px solid rgba(23,191,243,.25);border-radius:24px;background:rgba(16,21,29,.78);box-shadow:var(--glass-shadow)}.public-login-card>span{color:var(--cyan);font-size:9px;font-weight:900;letter-spacing:2px}.public-login-card h1{margin:12px 0;font:800 clamp(28px,5vw,42px) var(--display)}.public-login-card>p{color:var(--muted);font-size:13px;line-height:1.7}.public-login-card form{display:grid;grid-template-columns:140px 1fr;gap:14px;margin-top:26px}.public-login-card label{display:grid;gap:7px;color:#b6bec6;font-size:11px;font-weight:750}.public-login-card input{min-height:48px;padding:11px 13px;border:1px solid var(--line);border-radius:10px;outline:none;background:#090d12;color:#fff}.public-login-card form .primary-button{grid-column:1/-1}.login-error{padding:11px 13px;border-radius:9px;background:rgba(255,100,110,.1);color:#ffadb3!important}.login-back{grid-column:1/-1;background:transparent;color:var(--cyan);cursor:pointer;font-weight:800}@media(max-width:560px){.public-login-card{margin-top:30px}.public-login-card form{grid-template-columns:1fr}}
</style>
