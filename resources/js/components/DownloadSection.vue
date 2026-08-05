<script setup>
import { links } from '../data/landing';
</script>

<template>
    <section class="download section-pad">
        <div class="download-glow"></div>
        <div class="download-copy" data-reveal><span class="section-kicker">ENTERTAINMENT ANYWHERE</span><h2>Watch Anywhere,<br /><b>Anytime.</b></h2><p>I device duh berah Zo Stream app download la, zin kawngah pawh entertainment unlimited-in enjoy rawh.</p>
            <div class="download-buttons"><a :href="links.downloadPage">↓ <span><small>ALL DEVICES</small>Download options</span></a></div>
        </div>
        <div class="device-stage" aria-hidden="true" data-reveal><div class="laptop"><div class="device-ui"><span>ZO STREAM</span><h3>Kan stories.<br />Kan tawng.</h3><div><i></i><i></i><i></i></div></div></div><div class="phone"><div class="mobile-ui"><span>Z</span><i></i><i></i><i></i></div><span class="phone-logo">Z</span></div></div>
    </section>
</template>
