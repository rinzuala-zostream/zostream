<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppFooter from './components/AppFooter.vue';
import AppHeader from './components/AppHeader.vue';
import DownloadSection from './components/DownloadSection.vue';
import FeaturedSection from './components/FeaturedSection.vue';
import FeaturesSection from './components/FeaturesSection.vue';
import HeroSection from './components/HeroSection.vue';
import PlatformStats from './components/PlatformStats.vue';
import PricingSection from './components/PricingSection.vue';

const activeSection = ref('home');
const scrolled = ref(false);
let sectionObserver;
let revealObserver;

const navigate = (target) => document.querySelector(target)?.scrollIntoView({ behavior: 'smooth' });
const handleScroll = () => { scrolled.value = window.scrollY > 30; };

onMounted(() => {
    handleScroll();
    window.addEventListener('scroll', handleScroll, { passive: true });

    sectionObserver = new IntersectionObserver((entries) => {
        const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
        if (visible?.target.id) activeSection.value = visible.target.id;
    }, { rootMargin: '-30% 0px -55%', threshold: [0, .25, .5] });
    document.querySelectorAll('main section[id], footer[id]').forEach((section) => sectionObserver.observe(section));

    revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => { if (entry.isIntersecting) { entry.target.classList.add('is-visible'); revealObserver.unobserve(entry.target); } });
    }, { threshold: .12 });
    document.querySelectorAll('[data-reveal]').forEach((element) => revealObserver.observe(element));
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', handleScroll);
    sectionObserver?.disconnect();
    revealObserver?.disconnect();
});
</script>

<template>
    <div class="site-shell">
        <AppHeader :active-section="activeSection" :scrolled="scrolled" @navigate="navigate" />
        <main><HeroSection @navigate="navigate" /><PlatformStats /><FeaturesSection /><FeaturedSection /><PricingSection /><DownloadSection /></main>
        <AppFooter @navigate="navigate" />
    </div>
</template>
