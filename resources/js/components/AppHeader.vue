<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { links, navigation } from '../data/landing';

defineProps({ activeSection: String, scrolled: Boolean });
const emit = defineEmits(['navigate']);
const menuOpen = ref(false);

const navigate = (target) => {
    menuOpen.value = false;
    emit('navigate', target);
};
</script>

<template>
    <header class="site-header" :class="{ scrolled }">
        <a class="brand" href="#home" aria-label="Zo Stream home" @click.prevent="navigate('#home')">
            <img :src="'/images/zostream-logo.jpg'" alt="" />
            <span>ZO <b>STREAM</b></span>
        </a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <a v-for="item in navigation" :key="item.target" :class="{ active: activeSection === item.target.slice(1) }" :href="item.target" @click.prevent="navigate(item.target)">{{ item.label }}</a>
        </nav>
        <div class="header-actions">
            <a class="store-link" :href="links.playStore" target="_blank" rel="noreferrer">
                <AppIcon name="play-store" />
                <span><small>GET IT ON</small>Google Play</span>
            </a>
            <button class="menu-button" type="button" :class="{ open: menuOpen }" :aria-expanded="menuOpen" aria-controls="mobile-menu" aria-label="Toggle menu" @click="menuOpen = !menuOpen"><span></span><span></span><span></span></button>
        </div>
        <nav v-if="menuOpen" id="mobile-menu" class="mobile-nav" aria-label="Mobile navigation">
            <a v-for="item in navigation" :key="item.target" :class="{ active: activeSection === item.target.slice(1) }" :href="item.target" @click.prevent="navigate(item.target)">{{ item.label }}</a>
        </nav>
    </header>
</template>
