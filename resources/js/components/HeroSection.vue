<script setup>
import AppIcon from './AppIcon.vue';
import { links } from '../data/landing';
defineEmits(['navigate']);
</script>

<template>
    <section id="home" class="hero">
        <div class="hero-aura" aria-hidden="true"></div>
        <div class="hero-grid">
            <div class="hero-content" data-reveal>
                <div class="eyebrow"><i></i> Mizoram's streaming home</div>
                <h1>Kan tawng.<br />Kan culture.<br /><span>Kan stories.</span></h1>
                <p>Mizo movie, series leh world-class entertainment—Mizo tawnga i en duh zawng zawng hmun khatah.</p>
                <div class="hero-buttons">
                    <a class="primary-button" :href="links.playStore" target="_blank" rel="noreferrer"><AppIcon name="play-store" /> Get Zo Stream</a>
                    <button class="play-button" type="button" @click="$emit('navigate', '#featured')"><span>▶</span> Explore content</button>
                </div>
                <div class="hero-trust"><div class="avatar-stack"><span>Z</span><span>M</span><span>+</span></div><p><strong>50,000+ viewers</strong><small>Zo Stream family-ah tel ve rawh</small></p></div>
            </div>
            <div class="hero-showcase" data-reveal>
                <div class="poster-frame"><img :src="'/images/featured-poster.jpg'" alt="Featured on Zo Stream" /><div class="poster-shade"></div><div class="poster-copy"><span>#1 TRENDING</span><h2>K-Pop Demon Hunters</h2><p>Mizo Dubbed · 4K UHD</p></div><button type="button" aria-label="Play K-Pop Demon Hunters">▶</button></div>
                <div class="floating-note note-rating"><span>★</span><div><b>4.2 rating</b><small>1K+ reviews</small></div></div>
                <div class="floating-note note-quality"><b>4K</b><small>ULTRA HD</small></div>
            </div>
        </div>
        <div class="hero-foot"><span>SCROLL TO DISCOVER</span><i></i><p>Available on <b>Android</b> · iOS · Web · TV</p></div>
    </section>
</template>
