<script setup>
import { onMounted, ref } from 'vue';
import AppIcon from '../AppIcon.vue';
import { links } from '../../data/landing';
import { api } from '../../admin/lib/api';

const desktopPlatforms = [
    { key: 'macos_update', name: 'Mac', label: 'macOS', icon: '', description: 'Mac computer-ah Zo Stream app install la, i account hmangin en rawh.' },
    { key: 'windows_update', name: 'Windows', label: 'Windows', icon: '▦', description: 'Windows PC-ah Zo Stream app install la, i account hmangin en rawh.' },
];
const desktopReleases = ref({});

onMounted(async () => {
    await Promise.all(desktopPlatforms.map(async ({ key }) => {
        try {
            const response = await api(`/app-releases/${key}`);
            desktopReleases.value[key] = response?.data || response;
        } catch {
            desktopReleases.value[key] = null;
        }
    }));
});
</script>

<template>
    <main class="inner-page download-page">
        <section class="page-hero section-pad"><div data-reveal><a href="/">Home</a><span>/</span><b>Download</b><h1>Zo Stream.<br />I kutphahah.</h1><p>I device duh berah app install la, Mizo entertainment experience awlsam leh chak takin enjoy rawh.</p><small>Android · Web · TV · Mac · Windows</small></div></section>

        <section class="app-download-showcase section-pad" data-reveal>
            <div class="download-card-copy"><span>ANDROID APP</span><h2>Entertainment chu<br /><b>kalpui zel rawh.</b></h2><p>Zo Stream Android app-ah streaming quality thlan, offline access, device management leh content update thar te i dawng ang.</p><div class="download-meta"><div><strong>100K+</strong><small>Downloads</small></div><div><strong>4.2 ★</strong><small>User rating</small></div><div><strong>3+</strong><small>Content rating</small></div></div><a class="primary-button play-store-button" :href="links.playStore" target="_blank" rel="noreferrer"><AppIcon name="play-store" /><span><small>GET IT ON</small>Google Play</span></a></div>
            <div class="download-phone" aria-hidden="true"><div class="download-phone-screen"><div class="download-phone-brand">Z</div><span>Zo Stream</span><h3>Kan stories.<br />Kan tawng.</h3><div class="download-phone-rail"><i></i><i></i><i></i></div><div class="download-phone-nav"><i></i><i></i><i></i><i></i></div></div></div>
        </section>

        <section class="device-options section-pad">
            <div class="device-heading" data-reveal><span>WATCH YOUR WAY</span><h2>Device tinah.<br /><b>Experience pakhat.</b></h2></div>
            <div class="device-option-grid" data-reveal><article class="available"><i>✓</i><span>AVAILABLE NOW</span><h3>Android</h3><p>Phone leh tablet atan official Zo Stream app.</p><a :href="links.playStore" target="_blank" rel="noreferrer">Open Google Play ↗</a></article><article><i>◌</i><span>APP STORE</span><h3>iPhone & iPad</h3><p>iOS download link update turin kan buatsaih mek.</p><small>Coming soon</small></article><article><i>↗</i><span>NO INSTALL NEEDED</span><h3>Web browser</h3><p>Modern browser atangin Zo Stream service access rawh.</p><a href="/">Explore Zo Stream ↗</a></article><article><i>▣</i><span>BIG SCREEN</span><h3>Smart TV</h3><p>Supported TV devices-ah account hmangin sign in rawh.</p><a href="/contact-us">Get setup help ↗</a></article><article v-for="platform in desktopPlatforms" :key="platform.key" :class="{ available: desktopReleases[platform.key]?.enabled && desktopReleases[platform.key]?.url }">
                    <i>{{ desktopReleases[platform.key]?.enabled && desktopReleases[platform.key]?.url ? '✓' : platform.icon }}</i>
                    <span>{{ desktopReleases[platform.key]?.enabled && desktopReleases[platform.key]?.url ? 'AVAILABLE NOW' : `${platform.label.toUpperCase()} APP` }}</span>
                    <h3>{{ platform.name }}</h3><p>{{ platform.description }}</p>
                    <a v-if="desktopReleases[platform.key]?.enabled && desktopReleases[platform.key]?.url" :href="desktopReleases[platform.key].url">Download {{ platform.label }} app{{ desktopReleases[platform.key].version ? ` · v${desktopReleases[platform.key].version}` : '' }} ↗</a>
                    <small v-else>Coming soon</small>
                </article></div>
        </section>

        <section class="install-guide section-pad" data-reveal><header><span>QUICK START</span><h2>Minute khat lek.</h2></header><ol><li><span>01</span><div><h3>Store open rawh</h3><p>Google Play-ah Zo Stream page open rawh.</p></div></li><li><span>02</span><div><h3>Install rawh</h3><p>Install button hmet la, download zawh nghak rawh.</p></div></li><li><span>03</span><div><h3>Sign in & enjoy</h3><p>I phone number hmangin sign in la, streaming tan rawh.</p></div></li></ol></section>
    </main>
</template>
