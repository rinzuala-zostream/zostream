<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import StatusPanel from '../components/StatusPanel.vue';
import QrAdminLogin from '../components/QrAdminLogin.vue';
const route = useRoute(); const router = useRouter(); const auth = useAuth();
const step = ref('phone'); const countryCode = ref('+91'); const phoneNumber = ref(''); const userId = ref(''); const otp = ref(''); const notice = ref(''); const error = ref('');
const canPhone = computed(() => phoneNumber.value.replace(/\D/g, '').length >= 8);
const canOtp = computed(() => /^\d{6}$/.test(otp.value));
const mode = ref('qr');
onMounted(() => { if (auth.authenticated.value) router.replace('/dashboard'); });
async function request() {
    error.value = '';
    try {
        const response = await auth.requestOtp({ countryCode: countryCode.value, phoneNumber: phoneNumber.value.replace(/\D/g, ''), userId: userId.value });
        userId.value = response.user_id || userId.value;
        if (response.otp) notice.value = `Development OTP: ${response.otp}`;
        step.value = 'otp';
    } catch (reason) { error.value = reason.message; }
}
async function verify() {
    error.value = '';
    try {
        await auth.verifyOtp({ userId: userId.value, otp: otp.value });
        router.replace(String(route.query.redirect || '/dashboard'));
    } catch (reason) { error.value = reason.message; }
}
</script>

<template>
    <main class="admin-login">
        <div class="admin-login-orb one" /><div class="admin-login-orb two" />
        <section class="admin-login-card">
            <div class="admin-login-brand"><img :src="'/images/zostream-logo.jpg'" alt="Zo Stream"><span>ZO STREAM<small>ADMIN WORKSPACE</small></span></div>
            <div class="admin-login-copy"><p>SECURE ACCESS</p><h1>Welcome back.</h1><span>Manage content, subscribers and every Zo Stream surface from one focused workspace.</span></div>
            <div class="admin-login-tabs"><button :class="{ active: mode === 'qr' }" @click="mode = 'qr'">QR login</button><button :class="{ active: mode === 'phone' }" @click="mode = 'phone'">WhatsApp OTP</button></div>
            <QrAdminLogin v-if="mode === 'qr'" />
            <template v-else>
            <StatusPanel tone="error" :message="error || auth.state.error" /><StatusPanel tone="success" :message="notice" />
            <form v-if="step === 'phone'" class="admin-login-form" @submit.prevent="request">
                <label>WhatsApp number</label><div class="admin-phone-field"><input v-model="countryCode" aria-label="Country code"><input v-model="phoneNumber" inputmode="tel" autocomplete="tel" placeholder="Phone number" autofocus></div>
                <button class="admin-primary" :disabled="!canPhone || auth.state.busy">{{ auth.state.busy ? 'Sending…' : 'Send secure code' }}</button>
            </form>
            <form v-else class="admin-login-form" @submit.prevent="verify">
                <div class="admin-step-row"><button type="button" @click="step = 'phone'">← Change number</button><span>Code sent to {{ countryCode }} {{ phoneNumber }}</span></div>
                <label>6-digit verification code</label><input v-model="otp" class="admin-otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" autofocus>
                <button class="admin-primary" :disabled="!canOtp || auth.state.busy">{{ auth.state.busy ? 'Verifying…' : 'Open workspace' }}</button>
            </form>
            </template>
            <p class="admin-login-foot">Only approved administrator numbers can continue.</p>
        </section>
    </main>
</template>
