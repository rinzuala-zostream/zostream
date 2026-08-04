<script setup>
import { links } from '../data/landing';
</script>

<template>
    <section class="download section-pad">
        <div class="download-glow"></div>
        <div class="download-copy" data-reveal><span class="section-kicker">ENTERTAINMENT ANYWHERE</span><h2>Watch Anywhere,<br /><b>Anytime.</b></h2><p>I device duh berah Zo Stream app download la, zin kawngah pawh entertainment unlimited-in enjoy rawh.</p>
            <div class="download-buttons"><a :href="links.playStore" target="_blank" rel="noreferrer">▶ <span><small>GET IT ON</small>Google Play</span></a><span class="store-coming-soon" aria-label="App Store download link is not configured">● <span><small>DOWNLOAD ON THE</small>App Store</span></span></div>
        </div>
        <div class="device-stage" aria-hidden="true" data-reveal><div class="laptop"><div><img :src="'/images/featured-poster.jpg'" alt="" /></div></div><div class="phone"><img :src="'/images/featured-poster.jpg'" alt="" /><span class="phone-logo">Z</span></div></div>
    </section>
</template>
