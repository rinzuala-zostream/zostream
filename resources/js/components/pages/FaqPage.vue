<script setup>
import { ref } from 'vue';
import { faqs } from '../../data/pages';
const active = ref(0);
</script>

<template>
    <main class="inner-page">
        <section class="page-hero section-pad"><div data-reveal><a href="/">Home</a><span>/</span><b>Help centre</b><h1>I hriat duh te.</h1><p>Zo Stream chungchanga zawhna kan dawn tam berte chhanna.</p><small>Quick answers · Simple guidance</small></div></section>
        <section class="faq-layout section-pad"><div class="faq-intro" data-reveal><span>FAQ</span><h2>Zawhna tam berte.</h2><p>I zawhna chhanna i hmu lo a nih chuan support team min rawn be rawh.</p><a href="/contact-us">Contact support ↗</a></div><div class="faq-list" data-reveal><article v-for="(faq, index) in faqs" :key="faq[1]" :class="{ open: active === index }"><button type="button" @click="active = active === index ? -1 : index"><span>{{ faq[0] }}</span><h3>{{ faq[1] }}</h3><i>{{ active === index ? '−' : '+' }}</i></button><p v-if="active === index">{{ faq[2] }}</p></article></div></section>
    </main>
</template>
