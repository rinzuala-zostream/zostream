<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '../AppIcon.vue';
import { links } from '../../data/landing';
import { detectDownloadDevice, macInstallStep, releaseDownloadUrl } from '../../lib/downloadDevice';

const platforms = [
    { key: 'mac', name: 'Mac', icon: 'desktop', releaseKey: 'macos_update', note: 'macOS 14 or later', description: 'I Mac-ah Zo Stream. Screen lian zawkah, kan stories chu enjoy rawh.', action: 'Download for Mac' },
    { key: 'windows', name: 'Windows', icon: 'windows', releaseKey: 'windows_update', note: 'Windows PC', description: 'I PC-ah Zo Stream install la, i movie duh ber nen chawlh hahdam rawh.', action: 'Download for Windows' },
    { key: 'android', name: 'Android', icon: 'phone', note: 'Phones & tablets', description: 'I phone leh tablet-ah Zo Stream. I kalna apiangah kan stories chu kalpui rawh.', action: 'Get it on Google Play', url: links.playStore },
    { key: 'ios', name: 'iPhone & iPad', icon: 'apple', note: 'iOS & iPadOS', description: 'iPhone leh iPad atan download link kan buatsaih mek. A awm hunah hetah i hmu ang.' },
    { key: 'tv', name: 'Smart TV', icon: 'tv', note: 'Watch on the big screen', description: 'I chhungkua nen screen lianah en rawh. I TV mil setup tanpui i mamawh em?', action: 'Get TV setup help', url: '/contact-us' },
];
const detected = ref(null);
const selected = ref(null);
const downloadHeading = ref(null);
const releases = ref({});
const states = ref({});
const controllers = new Set();
const active = computed(() => platforms.find(device => device.key === selected.value));
const otherPlatforms = computed(() => platforms.filter(device => device.key !== selected.value));
const activeRelease = computed(() => releases.value[active.value?.releaseKey]);
const downloadUrl = computed(() => active.value?.url || releaseDownloadUrl(activeRelease.value));
const loading = computed(() => active.value?.releaseKey && states.value[active.value.releaseKey] === 'loading');
const failed = computed(() => active.value?.releaseKey && states.value[active.value.releaseKey] === 'error');
const steps = computed(() => {
    if (!active.value) return [];
    const install = {
        mac: ['Download rawh', 'I Mac atan installer download rawh.', 'Install rawh', macInstallStep(downloadUrl.value)],
        windows: ['Download rawh', 'Windows installer download rawh.', 'Install rawh', 'Installer hawng la, a hrilh angin install rawh.'],
        android: ['Google Play open rawh', 'A chunga Google Play button hmet rawh.', 'Install rawh', 'Zo Stream app page-ah Install hmet rawh.'],
        ios: ['Link lo nghak rawh', 'App Store download link kan buatsaih mek.', 'Device dang hmang rawh', 'A hnuaia Other devices atangin thlang rawh.'],
        tv: ['I TV check rawh', 'I TV model leh platform hriat chian rawh.', 'Setup help la rawh', 'Supported TV leh installation chungchangah min rawn rawh.'],
    }[active.value.key];
    return [{ title: install[0], text: install[1] }, { title: install[2], text: install[3] }, { title: 'Sign in & enjoy', text: 'I Zo Stream account hmangin sign in la, en tan rawh.' }];
});

async function loadRelease(key) {
    if (states.value[key] === 'loading') return;
    states.value[key] = 'loading';
    const controller = new AbortController();
    controllers.add(controller);
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
        const response = await fetch(`/api/v4/app-releases/${key}`, {
            headers: { Accept: 'application/json', 'X-Client-Platform': 'web', 'X-Client-Version': '1.0' },
            signal: controller.signal,
        });
        if (response.status === 404) {
            releases.value[key] = null;
        } else {
            if (!response.ok) throw new Error('Release unavailable');
            const payload = await response.json();
            if (payload.status === false || payload.success === false) throw new Error('Release unavailable');
            releases.value[key] = payload.data || payload;
        }
        states.value[key] = 'ready';
    } catch {
        states.value[key] = 'error';
    } finally {
        clearTimeout(timeout);
        controllers.delete(controller);
    }
}

onMounted(() => {
    detected.value = detectDownloadDevice(navigator);
    selected.value = detected.value;
    platforms.filter(device => device.releaseKey).forEach(device => loadRelease(device.releaseKey));
});
onBeforeUnmount(() => controllers.forEach(controller => controller.abort()));

async function selectDevice(key) {
    selected.value = key;
    await nextTick();
    downloadHeading.value?.focus({ preventScroll: true });
    downloadHeading.value?.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}
</script>

<template>
    <main class="get-app section-pad">
        <section class="get-app-hero" aria-labelledby="download-title">
            <div class="get-app-copy">
                <a class="download-breadcrumb" href="/">Home <span aria-hidden="true">/</span> <b>Download</b></a>
                <div class="download-eyebrow"><span></span> YOUR STORIES. YOUR SCREEN.</div>
                <h1 id="download-title">Kan stories.<br /><span>I screen-ah.</span></h1>
                <p class="get-app-intro">I device duh berah Zo Stream install la.<br />Mizo entertainment chu i bulah a awm reng ang.</p>
                <div v-if="active" class="recommended-download" aria-live="polite" :aria-busy="Boolean(loading)">
                    <div class="recommended-heading">
                        <div class="platform-symbol"><AppIcon :name="active.icon" /></div>
                        <div><span>{{ selected === detected ? 'FOR YOUR DEVICE' : 'SELECTED DEVICE' }}</span><h2 ref="downloadHeading" tabindex="-1">Zo Stream for {{ active.name }}</h2></div>
                        <span v-if="selected === detected" class="detected-mark" aria-label="Device detected">✓</span>
                    </div>
                    <p>{{ active.description }}</p>
                    <a v-if="downloadUrl" class="download-primary" :href="downloadUrl" :target="active.key === 'android' ? '_blank' : undefined" :rel="active.key === 'android' ? 'noopener noreferrer' : undefined">
                        <AppIcon :name="active.key === 'android' ? 'play-store' : active.key === 'tv' ? 'tv' : 'download'" />
                        {{ active.action }} <span aria-hidden="true">↗</span>
                    </a>
                    <button v-else-if="loading" class="download-primary" disabled><span class="download-spinner" aria-hidden="true"></span>Checking download…</button>
                    <button v-else-if="failed" class="download-primary" @click="loadRelease(active.releaseKey)">Try again <span aria-hidden="true">↻</span></button>
                    <button v-else class="download-primary" disabled>Download coming soon</button>
                    <div class="download-caption">
                        <span v-if="failed" role="status">Download link load a ni lo. Try again hmet rawh.</span>
                        <template v-else><span>{{ active.note }}</span><span v-if="downloadUrl && activeRelease?.version">v{{ activeRelease.version }}</span><span v-if="downloadUrl && active.key !== 'tv'">Official Zo Stream app</span></template>
                    </div>
                </div>
                <div v-else class="choose-device-prompt"><AppIcon name="download" /><div><strong>I device thlang rawh.</strong><p>I device OS kan hre thei lo. A hnuaiah thlang la, download link i hmu ang.</p></div></div>
                <a class="other-devices-link" href="#other-devices">{{ active ? 'Looking for another device?' : 'Choose your device' }} <span aria-hidden="true">↓</span></a>
            </div>
            <div class="download-art" aria-hidden="true">
                <div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div>
                <div class="art-label"><span></span> ALL IN MIZO</div>
                <div class="preview-laptop">
                    <div class="preview-screen">
                        <div class="preview-toolbar"><i></i><i></i><i></i><span>ZO STREAM</span></div>
                        <div class="preview-feature"><img :src="'/images/featured-poster.jpg'" alt="" fetchpriority="high" /><div><span>STORIES THAT FEEL LIKE HOME</span><h2>Kan tawng.<br />Kan entertainment.</h2><b>▶ &nbsp; Zo Stream</b></div></div>
                        <div class="preview-bottom"><span>Movies</span><span>Series</span><span>More to discover ↗</span></div>
                    </div>
                    <div class="preview-base"></div>
                </div>
                <div class="preview-mobile"><div class="mobile-camera"></div><img :src="'/images/featured-poster.jpg'" alt="" /><div><img :src="'/images/zostream-logo.jpg'" alt="" /><strong>Zo Stream</strong><span>Entertainment.<br />I kutphahah.</span><b>▶</b></div></div>
                <div class="art-footnote"><span>One account.</span> A screen for every story.</div>
            </div>
        </section>
        <section id="other-devices" class="other-devices" aria-labelledby="other-devices-title">
            <div class="other-devices-heading"><h2 id="other-devices-title">{{ active ? 'Other devices' : 'Choose your device' }}</h2><p>I enna tur device thlang rawh.</p></div>
            <div class="other-device-list">
                <button v-for="device in otherPlatforms" :key="device.key" class="other-device" @click="selectDevice(device.key)">
                    <AppIcon :name="device.icon" /><span><strong>{{ device.name }}</strong><small>{{ device.key === 'ios' ? 'Coming soon' : device.note }}</small></span><span class="device-arrow" aria-hidden="true">↗</span>
                </button>
            </div>
        </section>
        <section v-if="active" class="download-steps" aria-labelledby="install-title">
            <div class="steps-heading"><span>READY WHEN YOU ARE</span><h2 id="install-title">Install. Sign in. <b>Enjoy.</b></h2><p>{{ active.name }}-ah tan a awlsam.</p></div>
            <ol><li v-for="(step, index) in steps" :key="`${selected}-${index}`"><span class="step-number">0{{ index + 1 }}</span><h3>{{ step.title }}</h3><p>{{ step.text }}</p></li></ol>
        </section>
        <div class="download-support"><span>Installation-ah tanpui i mamawh em?</span><a href="/contact-us">We're here to help <span aria-hidden="true">↗</span></a></div>
    </main>
</template>

<style scoped src="../../../css/download.css"></style>
