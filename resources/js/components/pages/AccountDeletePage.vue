<script setup>
import { reactive } from 'vue';
import { links } from '../../data/landing';

const form = reactive({ name: '', phone: '', reason: '', confirmed: false });

const submitRequest = () => {
    const subject = 'Zo Stream account deletion request';
    const body = [
        'I would like to permanently delete my Zo Stream account.',
        '',
        `Account name: ${form.name || 'Not provided'}`,
        `Registered phone: ${form.phone}`,
        `Reason: ${form.reason || 'Not provided'}`,
        '',
        'I understand that Zo Stream will verify that I own this account before deleting it.',
    ].join('\n');

    window.location.href = `mailto:${links.supportEmail}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
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

            <form class="contact-form delete-request-form" data-reveal @submit.prevent="submitRequest">
                <header>
                    <span>CAN'T ACCESS THE APP?</span>
                    <h2>Deletion request siam rawh.</h2>
                    <p>Form hi submit chuan i email app a hawng ang. Kan support team-in account ownership an verify hnuah request an process ang.</p>
                </header>

                <label>Account-a hming
                    <input v-model.trim="form.name" type="text" autocomplete="name" placeholder="Full name (optional)" />
                </label>
                <label>Registered phone number
                    <input v-model.trim="form.phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="Country code nen, entirnan +91…" required />
                </label>
                <label>Reason
                    <textarea v-model.trim="form.reason" rows="4" placeholder="Reason (optional)"></textarea>
                </label>
                <label class="delete-consent">
                    <input v-model="form.confirmed" type="checkbox" required />
                    <span>He request hian ka Zo Stream account permanently delete tur a ni tih ka hrethiam.</span>
                </label>
                <button class="primary-button" type="submit" :disabled="!form.confirmed">Email deletion request <span>↗</span></button>
                <p class="delete-support-note">Email app i nei lo em? <a :href="`mailto:${links.supportEmail}`">{{ links.supportEmail }}</a> ah registered phone number nen min rawn ziak rawh.</p>
            </form>
        </section>

        <section class="delete-data-note section-pad" data-reveal>
            <span>DATA RETENTION</span>
            <p>Account deletion hnuah service hman nan mamawh personal data te kan delete ang. Payment, fraud-prevention emaw legal compliance records thenkhat chu applicable law-in a phut chhung chauh retain theih a ni.</p>
            <a href="/privacy-policy">Privacy Policy en rawh ↗</a>
        </section>
    </main>
</template>
