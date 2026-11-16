<script setup>
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../AppIcon.vue';
import { links } from '../../data/landing';
import { api } from '../../admin/lib/api';

const desktopPlatforms = [
    { key: 'macos_update', name: 'Mac', label: 'macOS', icon: '', description: 'Mac computer-ah Zo Stream app install la, i account hmangin en rawh.' },
    { key: 'windows_update', name: 'Windows', label: 'Windows', icon: '▦', description: 'Windows PC-ah Zo Stream app install la, i account hmangin en rawh.' },
];
const desktopReleases = ref({});

const deviceOptions = computed(() => [
    { key: 'android', name: 'Android', available: true, icon: '✓', badge: 'AVAILABLE NOW', description: 'Phone leh tablet atan official Zo Stream app.', url: links.playStore, linkLabel: 'Open Google Play ↗', external: true },
    { key: 'ios', name: 'iPhone & iPad', available: false, icon: '◌', badge: 'APP STORE', description: 'iOS download link update turin kan buatsaih mek.' },
    { key: 'web', name: 'Web browser', available: true, icon: '↗', badge: 'NO INSTALL NEEDED', description: 'Modern browser atangin Zo Stream service access rawh.', url: '/', linkLabel: 'Explore Zo Stream ↗' },
    { key: 'tv', name: 'Smart TV', available: true, icon: '▣', badge: 'BIG SCREEN', description: 'Supported TV devices-ah account hmangin sign in rawh.', url: '/contact-us', linkLabel: 'Get setup help ↗' },
    ...desktopPlatforms.map(platform => {
        const release = desktopReleases.value[platform.key];
        const available = Boolean(release?.enabled && release?.url);
        return {
            ...platform, available,
            icon: available ? '✓' : platform.icon,
            badge: available ? 'AVAILABLE NOW' : `${platform.label.toUpperCase()} APP`,
            url: available ? release.url : null,
            linkLabel: `Download ${platform.label} app${release?.version ? ` · v${release.version}` : ''} ↗`,
        };
    }),
].sort((left, right) => Number(right.available) - Number(left.available)));

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
            <div class="device-option-grid" data-reveal>
                <article v-for="device in deviceOptions" :key="device.key" :class="{ available: device.available }">
                    <i>{{ device.icon }}</i><span>{{ device.badge }}</span>
                    <h3>{{ device.name }}</h3><p>{{ device.description }}</p>
                    <a v-if="device.url" :href="device.url" :target="device.external ? '_blank' : undefined" :rel="device.external ? 'noreferrer' : undefined">{{ device.linkLabel }}</a>
                    <small v-else>Coming soon</small>
                </article>
            </div>
        </section>

        <section class="install-guide section-pad" data-reveal><header><span>QUICK START</span><h2>Minute khat lek.</h2></header><ol><li><span>01</span><div><h3>Store open rawh</h3><p>Google Play-ah Zo Stream page open rawh.</p></div></li><li><span>02</span><div><h3>Install rawh</h3><p>Install button hmet la, download zawh nghak rawh.</p></div></li><li><span>03</span><div><h3>Sign in & enjoy</h3><p>I phone number hmangin sign in la, streaming tan rawh.</p></div></li></ol></section>
    </main>
</template>
