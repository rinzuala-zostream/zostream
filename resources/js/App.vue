<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppFooter from './components/AppFooter.vue';
import AppHeader from './components/AppHeader.vue';
import AdBannerSlot from './components/AdBannerSlot.vue';
import DownloadSection from './components/DownloadSection.vue';
import FeaturedSection from './components/FeaturedSection.vue';
import FeaturesSection from './components/FeaturesSection.vue';
import HeroSection from './components/HeroSection.vue';
import PlatformStats from './components/PlatformStats.vue';
import PricingSection from './components/PricingSection.vue';
import AboutPage from './components/pages/AboutPage.vue';
import AccountDeletePage from './components/pages/AccountDeletePage.vue';
import ContactPage from './components/pages/ContactPage.vue';
import DownloadPage from './components/pages/DownloadPage.vue';
import FaqPage from './components/pages/FaqPage.vue';
import AdvertisePage from './components/pages/AdvertisePage.vue';
import AdSubmissionStatusPage from './components/pages/AdSubmissionStatusPage.vue';
import PolicyPage from './components/pages/PolicyPage.vue';
import { policyPages } from './data/pages';

const activeSection = ref('home');
const scrolled = ref(false);
const currentPath = window.location.pathname.replace(/\/$/, '') || '/';
const isHome = currentPath === '/';
const policyPage = policyPages[currentPath];
const dynamicLegalSlug = currentPath === '/advertising-terms'
    ? 'advertising-terms'
    : currentPath.startsWith('/legal/') ? currentPath.slice(7) : '';
const legalSlug = policyPage ? currentPath.slice(1) : dynamicLegalSlug;
const isLegalPage = Boolean(policyPage || dynamicLegalSlug);
const livePolicyPage = ref(policyPage || { eyebrow: 'Legal', title: 'Legal page', date: '', intro: '', sections: [] });
const isAdStatusPage = currentPath.startsWith('/advertise/status/') || currentPath.startsWith('/advertise/payment/');
const pageTitles = { '/about-us': 'About us', '/account-delete': 'Delete account', '/contact-us': 'Contact us', '/download': 'Download', '/faq': 'FAQ', '/advertise': 'Advertise' };
let sectionObserver;
let revealObserver;

const navigate = (target) => {
    if (target.startsWith('/#') && isHome) return document.querySelector(target.slice(1))?.scrollIntoView({ behavior: 'smooth' });
    if (target.startsWith('#') && isHome) return document.querySelector(target)?.scrollIntoView({ behavior: 'smooth' });
    window.location.href = target;
};
const handleScroll = () => { scrolled.value = window.scrollY > 30; };

onMounted(() => {
    document.title = `${policyPage?.title || pageTitles[currentPath] || (isAdStatusPage ? 'Ad submission status' : 'All in Mizo')} — Zo Stream`;
    if (legalSlug) {
        fetch(`/api/v4/legal-pages/${encodeURIComponent(legalSlug)}`, {
            headers: { 'X-Client-Platform': 'web', 'X-Client-Version': '1.0' },
        })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('Legal page unavailable')))
            .then((response) => {
                const page = response.data;
                if (!page) return;
                livePolicyPage.value = {
                    eyebrow: page.eyebrow || 'Legal',
                    title: page.title,
                    date: page.effective_date || '',
                    intro: page.intro || '',
                    sections: (page.sections || []).map((section) => [section.heading, section.body]),
                };
                document.title = `${page.title} — Zo Stream`;
            })
            .catch(() => {});
    }
    handleScroll();
    window.addEventListener('scroll', handleScroll, { passive: true });

    if (!isHome) activeSection.value = '';
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
        <AppHeader :active-section="activeSection" :current-path="currentPath" :scrolled="scrolled" @navigate="navigate" />
        <main v-if="isHome"><HeroSection @navigate="navigate" /><AdBannerSlot placement="home_top" /><PlatformStats /><FeaturesSection /><AdBannerSlot placement="home_middle" /><FeaturedSection /><PricingSection /><DownloadSection /></main>
        <AboutPage v-else-if="currentPath === '/about-us'" />
        <AccountDeletePage v-else-if="currentPath === '/account-delete'" />
        <ContactPage v-else-if="currentPath === '/contact-us'" />
        <DownloadPage v-else-if="currentPath === '/download'" />
        <FaqPage v-else-if="currentPath === '/faq'" />
        <AdvertisePage v-else-if="currentPath === '/advertise'" />
        <AdSubmissionStatusPage v-else-if="isAdStatusPage" />
        <PolicyPage v-else-if="isLegalPage" :page="livePolicyPage" />
        <AppFooter @navigate="navigate" />
    </div>
</template>
