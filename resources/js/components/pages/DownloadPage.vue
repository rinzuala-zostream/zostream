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
    let install = ['File hawng rawh', 'Download zawh file hawng la, a hrilh angin install rawh.'];
    if (isStore.value) install = ['Install hmet rawh', 'Store-ah Zo Stream app install rawh.'];
    else if (active.value?.key === 'mac') install = ['App install rawh', macInstallStep(downloadUrl.value)];
    else if (format.value === 'ZIP') install = ['ZIP extract rawh', 'ZIP file extract la, chhunga installation instructions zawm rawh.'];
    else if (active.value?.key === 'tv') install = ['I TV-ah install rawh', 'I TV mil installation tanpui i mamawh chuan min rawn rawh.'];
    return [
        { title: isStore.value ? 'App store open rawh' : 'App download rawh', text: isStore.value ? 'A chunga button hmet la, app store a inhawng ang.' : 'Download button hmet la, file download zawh nghak rawh.' },
        { title: install[0], text: install[1] },
        { title: 'Sign in la, en tan rawh', text: 'Zo Stream app hawng la, i account hmangin sign in rawh.' },
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
            <h1>Download la.<br class="mobile-break" /> En tan rawh.</h1>
            <p>I device atan app download la, i Zo Stream account hmangin sign in rawh.</p>
        </header>

        <section v-if="active" class="download-workspace" aria-labelledby="selected-device-title" :aria-busy="status === 'loading' && Boolean(releaseKey)">
            <div class="download-main">
                <div class="download-selection"><span class="device-pill"><AppIcon :name="active.icon" />{{ active.name }}</span><span v-if="selected === detected" class="auto-detected"><span aria-hidden="true">✓</span> I device atan</span><span v-else class="selection-note">I device thlan</span></div>
                <img class="download-app-logo" :src="'/images/zostream-logo.jpg'" alt="Zo Stream" width="64" height="64" />
                <h2 id="selected-device-title" ref="downloadHeading" tabindex="-1">Zo Stream for {{ deviceName }}</h2>
                <p class="download-device-note">{{ active.note }}</p>

                <label v-if="active.key === 'tv'" class="tv-choice"><span>I TV platform thlang rawh</span><select v-model="tvRelease"><option :value="null" disabled>Choose your TV</option><option v-for="platform in tvPlatforms" :key="platform.key" :value="platform.key">{{ platform.name }}</option></select></label>

                <div class="download-action-area" aria-live="polite" aria-atomic="true">
                    <template v-if="downloadUrl">
                        <a class="download-primary" :href="downloadUrl" :target="isStore ? '_blank' : undefined" :rel="isStore ? 'noopener noreferrer' : undefined"><AppIcon :name="actionIcon" /><span>{{ actionLabel }}</span><span class="cta-arrow" aria-hidden="true">{{ isStore ? '↗' : '↓' }}</span></a>
                        <div class="download-file-info"><span v-if="versionLabel">{{ versionLabel }}</span><span v-if="format">{{ format }} file</span><span v-else>{{ isStore ? 'App store-ah install rawh' : 'Official download' }}</span></div>
                    </template>
                    <template v-else-if="!releaseKey"><p class="download-status">I TV platform thlang la, a mil download link i hmu ang.</p></template>
                    <template v-else-if="status === 'loading'"><button class="download-primary" disabled><span class="download-spinner" aria-hidden="true"></span>Download link kan zawng mek…</button><p class="download-status">Lo nghak lawk rawh.</p></template>
                    <template v-else-if="status === 'error'"><p class="download-status error" role="status">Download link load a ni lo. Check leh rawh.</p><button class="download-primary" @click="loadRelease(releaseKey, true)"><span aria-hidden="true">↻</span>Try again</button></template>
                    <template v-else><div class="unavailable-label">Tunah download a la awm lo</div><p class="download-status">{{ deviceName }} atan link a awm hunah hetah a lang ang. Device dang i thlang thei bawk.</p></template>
                </div>
                <a class="change-device-link" href="#other-devices">Device dang atan i duh em? <span aria-hidden="true">↓</span></a>
            </div>

            <aside class="download-instructions" aria-labelledby="install-title">
                <template v-if="downloadUrl">
                    <div class="instructions-label"><span aria-hidden="true">01 — 03</span> TAN DAN</div>
                    <h3 id="install-title">Install dan a awlsam.</h3>
                    <ol><li v-for="(step, index) in steps" :key="`${releaseKey}-${index}`"><span class="instruction-number">{{ index + 1 }}</span><div><h4>{{ step.title }}</h4><p>{{ step.text }}</p></div></li></ol>
                    <p v-if="active.key === 'mac'" class="installer-note"><strong>DMG leh PKG</strong> chu install dan chi hnih a ni. I download file mil instructions zawm rawh.</p>
                </template>
                <template v-else>
                    <div class="instructions-label">ZO STREAM DOWNLOAD</div>
                    <h3 id="install-title">I device mil app.</h3>
                    <p class="instructions-placeholder">{{ status === 'loading' && releaseKey ? 'Download link a ready hunah install dan hetah a lang ang.' : 'Device dang thlang la, a mil download link leh install dan i hmu ang.' }}</p>
                    <a class="instructions-help" href="#other-devices">Device options en rawh <span aria-hidden="true">↓</span></a>
                </template>
                <a class="installation-help" href="/contact-us"><AppIcon name="message" /><span><strong>Tanpui i mamawh em?</strong><small>Installation-ah kan pui thei che.</small></span><span aria-hidden="true">↗</span></a>
            </aside>
        </section>

        <section v-else class="device-picker-empty" aria-labelledby="choose-device-title"><AppIcon name="desktop" /><h2 id="choose-device-title">I device thlang rawh.</h2><p>I device OS kan hre thei lo. A hnuaiah thlang la, download link i hmu ang.</p></section>

        <section id="other-devices" class="other-devices" aria-labelledby="other-devices-title">
            <div class="other-devices-heading"><h2 id="other-devices-title">{{ active ? 'Device dang atan' : 'Choose your device' }}</h2><p>{{ active ? 'I download duh chu thlang rawh.' : 'App install-na tur device thlang rawh.' }}</p></div>
            <div class="other-device-list"><button v-for="device in otherPlatforms" :key="device.key" class="other-device" @click="selectDevice(device.key)"><AppIcon :name="device.icon" /><span>{{ device.name }}</span><span aria-hidden="true">↗</span></button></div>
        </section>

        <section class="download-faq" aria-labelledby="download-faq-title">
            <div><span class="download-kicker">HELP & SUPPORT</span><h2 id="download-faq-title">Hriat tur tlem.</h2><p>Download leh installation chungchang.</p></div>
            <div class="download-questions">
                <details><summary>Device dangah install ka duh chuan?<span aria-hidden="true">+</span></summary><p>“Device dang atan” atangin i install-na tur device thlang rawh. A mil download button leh install dan a lang ang.</p></details>
                <details><summary>Download button ka hmu lo a.<span aria-hidden="true">+</span></summary><p>I device atan download link a la awm loh chuan device dang thlang rawh. Link load a nih loh chuan “Try again” hmet la, check leh rawh.</p></details>
                <details><summary>Installation-ah ka buai a.<span aria-hidden="true">+</span></summary><p>I device hming leh i harsatna min hrilh rawh. <a href="/contact-us">Support rawn rawh ↗</a></p></details>
            </div>
        </section>
    </main>
</template>

<style scoped src="../../../css/download.css"></style>
