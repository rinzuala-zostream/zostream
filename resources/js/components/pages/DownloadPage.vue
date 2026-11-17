<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from '../AppIcon.vue';
import { downloadPlatforms, tvPlatforms } from '../../data/downloads';
import { detectDownloadDevice, detectTvRelease, installerFormat, macInstallStep, releaseDownloadUrl, releaseVersionLabel, unwrapAppRelease } from '../../lib/downloadDevice';

const detected = ref(null);
const selected = ref(null);
const tvRelease = ref(null);
const downloadHeading = ref(null);
const releases = ref({});
const states = ref({});
const controllers = new Set();
const active = computed(() => downloadPlatforms.find(device => device.key === selected.value));
const otherPlatforms = computed(() => downloadPlatforms.filter(device => device.key !== selected.value));
const releaseKey = computed(() => active.value?.key === 'tv' ? tvRelease.value : active.value?.releaseKey);
const activeRelease = computed(() => releases.value[releaseKey.value]);
const status = computed(() => states.value[releaseKey.value] || 'loading');
const downloadUrl = computed(() => status.value === 'ready' ? releaseDownloadUrl(activeRelease.value) : null);
const versionLabel = computed(() => releaseVersionLabel(activeRelease.value));
const format = computed(() => installerFormat(downloadUrl.value));
const tvName = computed(() => tvPlatforms.find(platform => platform.key === tvRelease.value)?.name);
const deviceName = computed(() => tvName.value && active.value?.key === 'tv' ? tvName.value : active.value?.name);
const isStore = computed(() => {
    if (!downloadUrl.value) return false;
    return ['play.google.com', 'apps.apple.com', 'apps.microsoft.com', 'www.microsoft.com', 'www.lgappstv.com', 'lgappstv.com'].includes(new URL(downloadUrl.value).hostname);
});
const actionLabel = computed(() => {
    if (!isStore.value) return `Download for ${deviceName.value}`;
    const host = new URL(downloadUrl.value).hostname;
    if (host === 'play.google.com') return 'Get it on Google Play';
    if (host === 'apps.apple.com') return 'Get it on the App Store';
    return 'Open app store';
});
const actionIcon = computed(() => {
    if (!isStore.value) return 'download';
    return new URL(downloadUrl.value).hostname === 'play.google.com' ? 'play-store' : active.value.icon;
});
const steps = computed(() => {
    if (!downloadUrl.value) return [];
    let install = ['Run the installer', 'Open the downloaded file and follow the installation instructions.'];
    if (isStore.value) install = ['Install the app', 'On the store page, select Install or Get to add Zo Stream to your device.'];
    else if (active.value?.key === 'mac') install = ['Install the app', macInstallStep(downloadUrl.value)];
    else if (format.value === 'ZIP') install = ['Extract the ZIP file', 'Open the ZIP file and follow the installation instructions inside.'];
    else if (active.value?.key === 'tv') install = ['Install on your TV', 'Follow the instructions for your TV. Contact support if you need help.'];
    return [
        { title: isStore.value ? 'Open the app store' : 'Download the app', text: isStore.value ? 'Use the button to open the Zo Stream store page.' : 'Select Download and wait for the file to finish downloading.' },
        { title: install[0], text: install[1] },
        { title: 'Sign in and start watching', text: 'Open Zo Stream and sign in with your account.' },
    ];
});

async function loadRelease(key, retry = false) {
    if (!key || states.value[key] === 'loading' || (!retry && states.value[key] === 'ready')) return;
    states.value[key] = 'loading';
    const controller = new AbortController();
    controllers.add(controller);
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
        const response = await fetch(`/api/v4/app-releases/${key}`, {
            headers: { Accept: 'application/json', 'X-Client-Platform': 'web', 'X-Client-Version': '1.0' },
            signal: controller.signal,
        });
        if (response.status === 404) releases.value[key] = null;
        else {
            if (!response.ok) throw new Error('Release unavailable');
            const payload = await response.json();
            releases.value[key] = unwrapAppRelease(payload);
        }
        states.value[key] = 'ready';
    } catch {
        states.value[key] = 'error';
    } finally {
        clearTimeout(timeout);
        controllers.delete(controller);
    }
}

watch(releaseKey, key => loadRelease(key));
onMounted(() => {
    detected.value = detectDownloadDevice(navigator);
    tvRelease.value = detectTvRelease(navigator.userAgent);
    selected.value = detected.value;
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
    <main class="download-center section-pad">
        <div class="download-topline"><a href="/">Home</a><span aria-hidden="true">/</span><span>Download</span><a class="topline-help" href="/contact-us">Need help? <span aria-hidden="true">↗</span></a></div>
        <header class="download-intro">
            <span class="download-kicker">ZO STREAM APP</span>
            <h1>Get Zo Stream.<br class="mobile-break" /> Start watching.</h1>
            <p>Download the app for your device, then sign in to start watching.</p>
        </header>

        <section v-if="active" class="download-workspace" aria-labelledby="selected-device-title" :aria-busy="status === 'loading' && Boolean(releaseKey)">
            <div class="download-main">
                <div class="download-selection"><span class="device-pill"><AppIcon :name="active.icon" />{{ active.name }}</span><span v-if="selected === detected" class="auto-detected"><span aria-hidden="true">✓</span> Recommended for you</span><span v-else class="selection-note">Selected device</span></div>
                <img class="download-app-logo" :src="'/images/zostream-logo.jpg'" alt="Zo Stream" width="64" height="64" />
                <h2 id="selected-device-title" ref="downloadHeading" tabindex="-1">Zo Stream for {{ deviceName }}</h2>
                <p class="download-device-note">{{ active.note }}</p>

                <label v-if="active.key === 'tv'" class="tv-choice"><span>Choose your TV platform</span><select v-model="tvRelease"><option :value="null" disabled>Choose your TV</option><option v-for="platform in tvPlatforms" :key="platform.key" :value="platform.key">{{ platform.name }}</option></select></label>

                <div class="download-action-area" aria-live="polite" aria-atomic="true">
                    <template v-if="downloadUrl">
                        <a class="download-primary" :href="downloadUrl" :target="isStore ? '_blank' : undefined" :rel="isStore ? 'noopener noreferrer' : undefined"><AppIcon :name="actionIcon" /><span>{{ actionLabel }}</span><span class="cta-arrow" aria-hidden="true">{{ isStore ? '↗' : '↓' }}</span></a>
                        <div class="download-file-info"><span v-if="versionLabel">{{ versionLabel }}</span><span v-if="format">{{ format }} file</span><span v-else>{{ isStore ? 'Install from the app store' : 'Official download' }}</span></div>
                    </template>
                    <template v-else-if="!releaseKey"><p class="download-status">Choose your TV platform to see the right download.</p></template>
                    <template v-else-if="status === 'loading'"><button class="download-primary" disabled><span class="download-spinner" aria-hidden="true"></span>Finding your download…</button><p class="download-status">This will only take a moment.</p></template>
                    <template v-else-if="status === 'error'"><p class="download-status error" role="status">We couldn’t load the download link. Please try again.</p><button class="download-primary" @click="loadRelease(releaseKey, true)"><span aria-hidden="true">↻</span>Try again</button></template>
                    <template v-else><div class="unavailable-label">Download not available yet</div><p class="download-status">A download for {{ deviceName }} isn’t available right now. You can choose another device below.</p></template>
                </div>
                <a class="change-device-link" href="#other-devices">Looking for another device? <span aria-hidden="true">↓</span></a>
            </div>

            <aside class="download-instructions" aria-labelledby="install-title">
                <template v-if="downloadUrl">
                    <div class="instructions-label"><span aria-hidden="true">01 — 03</span> HOW TO INSTALL</div>
                    <h3 id="install-title">Get started in three steps.</h3>
                    <ol><li v-for="(step, index) in steps" :key="`${releaseKey}-${index}`"><span class="instruction-number">{{ index + 1 }}</span><div><h4>{{ step.title }}</h4><p>{{ step.text }}</p></div></li></ol>
                    <p v-if="active.key === 'mac'" class="installer-note">This app hasn’t been notarized by Apple. If macOS blocks it from opening, go to <strong>System Settings → Privacy &amp; Security</strong> and click <strong>Open Anyway</strong>.</p>
                </template>
                <template v-else>
                    <div class="instructions-label">ZO STREAM DOWNLOAD</div>
                    <h3 id="install-title">The right app for your device.</h3>
                    <p class="instructions-placeholder">{{ status === 'loading' && releaseKey ? 'Installation steps will appear here when your download is ready.' : 'Choose an available device below to see its download and installation steps.' }}</p>
                    <a class="instructions-help" href="#other-devices">View other devices <span aria-hidden="true">↓</span></a>
                </template>
                <a class="installation-help" href="/contact-us"><AppIcon name="message" /><span><strong>Need a hand?</strong><small>Get help with installation.</small></span><span aria-hidden="true">↗</span></a>
            </aside>
        </section>

        <section v-else class="device-picker-empty" aria-labelledby="choose-device-title"><AppIcon name="desktop" /><h2 id="choose-device-title">Choose your device.</h2><p>We couldn’t identify your device. Choose it below to find the right download.</p></section>

        <section id="other-devices" class="other-devices" aria-labelledby="other-devices-title">
            <div class="other-devices-heading"><h2 id="other-devices-title">{{ active ? 'Other devices' : 'Choose your device' }}</h2><p>{{ active ? 'Choose a different device to download for.' : 'Select the device you want to install Zo Stream on.' }}</p></div>
            <div class="other-device-list"><button v-for="device in otherPlatforms" :key="device.key" class="other-device" @click="selectDevice(device.key)"><AppIcon :name="device.icon" /><span>{{ device.name }}</span><span aria-hidden="true">↗</span></button></div>
        </section>

        <section class="download-faq" aria-labelledby="download-faq-title">
            <div><span class="download-kicker">HELP & SUPPORT</span><h2 id="download-faq-title">A little help getting started.</h2><p>Answers about downloads and installation.</p></div>
            <div class="download-questions">
                <details><summary>How do I download for another device?<span aria-hidden="true">+</span></summary><p>Choose a device under “Other devices”. The download button and installation steps will update to match your selection.</p></details>
                <details><summary>Why isn’t a download available?<span aria-hidden="true">+</span></summary><p>A download may not be available for your device yet. You can choose another device, or select “Try again” if the link couldn’t be loaded.</p></details>
                <details><summary>What if I need help installing?<span aria-hidden="true">+</span></summary><p>Tell us which device you’re using and what went wrong. <a href="/contact-us">Contact support ↗</a></p></details>
            </div>
        </section>
    </main>
</template>

<style scoped src="../../../css/download.css"></style>
