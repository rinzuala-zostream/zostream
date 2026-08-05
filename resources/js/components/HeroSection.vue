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
                <p>Mizo entertainment leh world-class content—i tawng, i screen leh i hun remchang mila experience thar.</p>
                <div class="hero-buttons">
                    <a class="primary-button" :href="links.downloadPage"><AppIcon name="play-store" /> Download Zo Stream</a>
                    <button class="play-button" type="button" @click="$emit('navigate', '#featured')"><span>▶</span> Explore content</button>
                </div>
                <div class="hero-trust"><div class="avatar-stack"><span>Z</span><span>M</span><span>+</span></div><p><strong>50,000+ viewers</strong><small>Zo Stream family-ah tel ve rawh</small></p></div>
            </div>
            <div class="hero-showcase" data-reveal>
                <div class="product-preview">
                    <div class="preview-top"><span class="preview-brand"><b>Z</b> ZO STREAM</span><i></i><i></i><em></em></div>
                    <div class="preview-body"><span class="preview-label">WELCOME HOME</span><h2>Entertainment<br /><b>Mizo tawngin.</b></h2><p>One app. Every screen. Your language.</p><div class="preview-actions"><span>Start watching</span><i>▶</i></div></div>
                    <div class="preview-rail"><span></span><span></span><span></span><span></span></div>
                    <div class="preview-glow"></div>
                </div>
                <div class="floating-note note-rating"><span>★</span><div><b>4.2 rating</b><small>1K+ reviews</small></div></div>
                <div class="floating-note note-quality"><b>4K</b><small>ULTRA HD</small></div>
            </div>
        </div>
        <div class="hero-foot"><span>SCROLL TO DISCOVER</span><i></i><p>Available on <b>Android</b> · iOS · Web · TV</p></div>
    </section>
</template>
