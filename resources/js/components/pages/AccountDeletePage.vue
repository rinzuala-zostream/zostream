<script setup>
import { reactive, ref } from 'vue';
import { links } from '../../data/landing';

const form = reactive({ countryCode: '+91', phone: '', otp: '', confirmed: false });
const step = ref('request');
const busy = ref(false);
const error = ref('');
const notice = ref('');
const deletionToken = ref('');
const phoneHint = ref('');

const request = async (url, options) => {
    const response = await fetch(url, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'X-Client-Platform': 'web',
            'X-Client-Version': '1.0',
        },
    });
    const json = await response.json().catch(() => ({}));
    const payload = json?.data || json;

    if (!response.ok || json?.success === false || payload?.status === 'error') {
        throw new Error(json?.error?.message || json?.message || payload?.message || 'Request failed. Please try again.');
    }

    return payload;
};

const sendOtp = async () => {
    busy.value = true;
    error.value = '';
    notice.value = '';

    try {
        const payload = await request('/api/v4/account-deletion/otp', {
            method: 'POST',
            body: JSON.stringify({
                country_code: form.countryCode,
                phone_number: form.phone.replace(/\D/g, ''),
            }),
        });

        deletionToken.value = payload.deletion_token;
        phoneHint.value = payload.phone_hint || form.phone.slice(-4);
        form.otp = '';
        form.confirmed = false;
        step.value = 'verify';
        notice.value = payload.message || 'Verification code sent.';
    } catch (reason) {
        error.value = reason.message;
    } finally {
        busy.value = false;
    }
};

const deleteAccount = async () => {
    busy.value = true;
    error.value = '';
    notice.value = '';

    try {
        const payload = await request('/api/v4/account-deletion', {
            method: 'DELETE',
            body: JSON.stringify({
                deletion_token: deletionToken.value,
                otp: form.otp,
                confirmed: form.confirmed,
            }),
        });

        notice.value = payload.message || 'Your account has been permanently deleted.';
        deletionToken.value = '';
        form.phone = '';
        form.otp = '';
        form.confirmed = false;
        step.value = 'success';
    } catch (reason) {
        error.value = reason.message;
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <main class="inner-page account-delete-page">
        <section class="page-hero section-pad">
            <div data-reveal>
                <a href="/">Home</a><span>/</span><b>Account & privacy</b>
                <h1>Delete your<br />account.</h1>
                <p>Zo Stream account leh a kaihhnawih personal data delete turin app atang emaw, app i access theih loh chuan hetah hian request i siam thei.</p>
                <small>Permanent action · Account ownership verification required</small>
            </div>
        </section>

        <section class="delete-account-layout section-pad">
            <div class="delete-account-guide" data-reveal>
                <span class="delete-kicker">DELETE FROM THE APP</span>
                <h2>App i access theih chuan hei hi a rang ber.</h2>
                <ol>
                    <li><b>01</b><div><strong>Zo Stream app hawng rawh</strong><p>I account hmanga login la, Profile/Account Settings-ah lut rawh.</p></div></li>
                    <li><b>02</b><div><strong>Delete Account thlang rawh</strong><p>Delete Account button hmet la, registered phone-a OTP lo thleng enter rawh.</p></div></li>
                    <li><b>03</b><div><strong>Permanent deletion confirm rawh</strong><p>Confirmation hnuah account access leh account data te delete a ni ang.</p></div></li>
                </ol>

                <div class="delete-impact">
                    <span>BEFORE YOU CONTINUE</span>
                    <h3>He action hi undo theih a ni lo.</h3>
                    <p>Profile, active sessions, registered devices leh service access te i hloh ang. Active subscription emaw PPV access awm chu deletion hmaa en fel phawt rawh.</p>
                </div>
            </div>

            <form v-if="step !== 'success'" class="contact-form delete-request-form" data-reveal @submit.prevent="step === 'request' ? sendOtp() : deleteAccount()">
                <header>
                    <span>DELETE FROM THE WEB</span>
                    <h2>{{ step === 'request' ? 'I account verify rawh.' : 'Permanent deletion confirm rawh.' }}</h2>
                    <p v-if="step === 'request'">Account-a registered phone number enter la. WhatsApp-a verification code kan rawn thawn ang.</p>
                    <p v-else>Verification code chu {{ phoneHint }}-a tawp registered number-ah kan thawn.</p>
                </header>

                <p v-if="error" class="delete-form-message error" role="alert">{{ error }}</p>
                <p v-if="notice" class="delete-form-message success" role="status">{{ notice }}</p>

                <template v-if="step === 'request'">
                    <div class="delete-phone-row">
                        <label>Country code
                            <input v-model.trim="form.countryCode" type="tel" inputmode="tel" autocomplete="tel-country-code" placeholder="+91" required />
                        </label>
                        <label>Registered phone number
                            <input v-model.trim="form.phone" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="Phone number" required />
                        </label>
                    </div>
                    <button class="primary-button" type="submit" :disabled="busy || !form.phone">{{ busy ? 'Sending…' : 'Send verification code' }} <span>→</span></button>
                </template>

                <template v-else>
                    <label>6-digit verification code
                        <input v-model.trim="form.otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required />
                    </label>
                    <label class="delete-consent">
                        <input v-model="form.confirmed" type="checkbox" required />
                        <span>Ka account leh a kaihhnawih data te permanently delete a ni dawn tih leh undo theih a nih loh ka hrethiam.</span>
                    </label>
                    <button class="primary-button delete-button" type="submit" :disabled="busy || !form.confirmed || form.otp.length !== 6">{{ busy ? 'Deleting…' : 'Permanently delete my account' }}</button>
                    <button class="delete-back-button" type="button" :disabled="busy" @click="step = 'request'; error = ''; notice = ''">Phone number thlak / code resend</button>
                </template>

                <p class="delete-support-note">Problem i tawh chuan <a :href="`mailto:${links.supportEmail}`">{{ links.supportEmail }}</a> ah min rawn ziak rawh.</p>
            </form>

            <section v-else class="delete-success-card" data-reveal>
                <i>✓</i><span>ACCOUNT DELETED</span><h2>Your account has been deleted.</h2><p>{{ notice }}</p><a href="/">Zo Stream home-ah kir rawh →</a>
            </section>
        </section>

        <section class="delete-data-note section-pad" data-reveal>
            <span>DATA RETENTION</span>
            <p>Account deletion hnuah service hman nan mamawh personal data te kan delete ang. Payment, fraud-prevention emaw legal compliance records thenkhat chu applicable law-in a phut chhung chauh retain theih a ni.</p>
            <a href="/privacy-policy">Privacy Policy en rawh ↗</a>
        </section>
    </main>
</template>
