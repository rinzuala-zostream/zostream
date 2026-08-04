<script setup>
import { legalLinks, links } from '../data/landing';
defineEmits(['navigate']);
</script>

<template>
    <footer id="contact" class="footer section-pad">
        <div class="footer-main" data-reveal><div><a class="brand footer-brand" href="#home" @click.prevent="$emit('navigate', '#home')"><img :src="'/images/zostream-logo.jpg'" alt="" /><span>ZO <b>STREAM</b></span></a><h2>We are here<br />to entertain you<br /><b>non-stop.</b></h2></div>
            <div class="footer-links"><div><h4>Explore</h4><a href="#home">Home</a><a href="#featured">TV Shows</a><a href="#features">About us</a><a href="https://zostream.in/contact-us">Contact us</a></div><div><h4>Legal</h4><a v-for="item in legalLinks" :key="item.href" :href="item.href">{{ item.label }}</a></div><div><h4>Get in touch</h4><p>Support emaw zawhna emaw i neih chuan min rawn be rawh.</p><a class="contact-link" :href="`mailto:${links.supportEmail}`">{{ links.supportEmail }} ↗</a></div></div>
        </div>
        <div class="footer-bottom"><span>Copyright © 2026 Zo Stream</span><span>Small change. <b>Big change.</b></span></div>
    </footer>
</template>
