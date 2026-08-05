<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { legalLinks, links, navigation } from '../data/landing';

const props = defineProps({ activeSection: String, scrolled: Boolean, currentPath: { type: String, default: '/' } });
const emit = defineEmits(['navigate']);
const menuOpen = ref(false);
const legalOpen = ref(false);

const navigate = (target) => {
    menuOpen.value = false;
    legalOpen.value = false;
    emit('navigate', target);
};

const isActive = (item) => {
    if (item.target === '/') return props.currentPath === '/' && props.activeSection === 'home';
    if (item.target.startsWith('/#')) return props.currentPath === '/' && props.activeSection === item.target.slice(2);
    return props.currentPath === item.target;
};
</script>

<template>
    <header class="site-header" :class="{ scrolled }">
        <a class="brand" href="/" aria-label="Zo Stream home" @click.prevent="navigate('/')">
            <img :src="'/images/zostream-logo.jpg'" alt="" />
            <span>ZO <b>STREAM</b></span>
        </a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <a v-for="item in navigation" :key="item.target" :class="{ active: isActive(item) }" :href="item.target" @click.prevent="navigate(item.target)">{{ item.label }}</a>
            <div class="legal-menu" :class="{ active: legalLinks.some((item) => item.href === currentPath) }">
                <button type="button">Legal <span>⌄</span></button>
                <div class="legal-dropdown"><a v-for="item in legalLinks" :key="item.href" :class="{ active: item.href === currentPath }" :href="item.href">{{ item.label }}</a></div>
            </div>
        </nav>
        <div class="header-actions">
            <a class="store-link" :href="links.downloadPage">
                <AppIcon name="play-store" />
                <span><small>GET THE APP</small>Download</span>
            </a>
            <button class="menu-button" type="button" :class="{ open: menuOpen }" :aria-expanded="menuOpen" aria-controls="mobile-menu" aria-label="Toggle menu" @click="menuOpen = !menuOpen"><span></span><span></span><span></span></button>
        </div>
        <nav v-if="menuOpen" id="mobile-menu" class="mobile-nav" aria-label="Mobile navigation">
            <a v-for="item in navigation" :key="item.target" :class="{ active: isActive(item) }" :href="item.target" @click.prevent="navigate(item.target)">{{ item.label }}</a>
            <button class="mobile-legal-toggle" type="button" :aria-expanded="legalOpen" @click="legalOpen = !legalOpen">Legal <span>{{ legalOpen ? '−' : '+' }}</span></button>
            <div v-if="legalOpen" class="mobile-legal-links"><a v-for="item in legalLinks" :key="item.href" :class="{ active: item.href === currentPath }" :href="item.href">{{ item.label }}</a></div>
        </nav>
    </header>
</template>
