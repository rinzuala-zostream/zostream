<script setup>
import { reactive } from 'vue';
import { links } from '../../data/landing';

const form = reactive({ name: '', email: '', subject: 'Subscription & Payment Issue', message: '' });
const submit = () => {
    const body = `Name: ${form.name}\nEmail: ${form.email}\n\n${form.message}`;
    window.location.href = `mailto:${links.supportEmail}?subject=${encodeURIComponent(form.subject)}&body=${encodeURIComponent(body)}`;
};
</script>

<template>
    <main class="inner-page contact-page">
        <section class="page-hero section-pad"><div data-reveal><a href="/">Home</a><span>/</span><b>Contact us</b><h1>Kan pui thei<br />che em?</h1><p>Login, subscription, technical support emaw content suggestion—kan team-in kan lo ngaithla reng che.</p><small>Customer support · 10:00 AM – 8:00 PM</small></div></section>
        <section class="contact-layout section-pad">
            <div class="contact-options" data-reveal>
                <a href="https://wa.me/918837076347" target="_blank" rel="noreferrer"><i>↗</i><span>FASTEST RESPONSE</span><h2>WhatsApp us</h2><p>+91 88370 76347</p></a>
                <a :href="`mailto:${links.supportEmail}`"><i>↗</i><span>BUSINESS & TECHNICAL</span><h2>Email support</h2><p>{{ links.supportEmail }}</p></a>
                <article><i>✦</i><span>CREATOR PROGRAM OPEN</span><h2>Content siamtu i ni em?</h2><p>I work Zo Stream-a host tur leh distribution partnership atan min rawn contact rawh.</p></article>
            </div>
            <form class="contact-form" data-reveal @submit.prevent="submit"><header><span>SEND A MESSAGE</span><h2>Min rawn ziak rawh.</h2><p>Form fill la; i email app kan hawnsak ang.</p></header><div class="form-row"><label>Hming<input v-model="form.name" required type="text" placeholder="Full name" /></label><label>Email<input v-model="form.email" required type="email" placeholder="you@example.com" /></label></div><label>Subject<select v-model="form.subject"><option>Subscription & Payment Issue</option><option>Technical Support</option><option>Content Suggestion</option><option>Creator Partnership</option><option>Other</option></select></label><label>I thuchah<textarea v-model="form.message" required rows="6" placeholder="Engnge kan puih theih che?"></textarea></label><button class="primary-button" type="submit">Send message <span>↗</span></button></form>
        </section>
    </main>
</template>
