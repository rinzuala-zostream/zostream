<script setup>
import { onMounted, ref } from 'vue';
import { legalLinks, links } from '../../data/landing';
defineProps({ page: { type: Object, required: true } });
const pageLinks = ref(legalLinks);
const knownPaths = new Map(legalLinks.map((item) => [item.href.slice(1), item.href]));

onMounted(() => {
    fetch('/api/v4/legal-pages', { headers: { 'X-Client-Platform': 'web', 'X-Client-Version': '1.0' } })
        .then((response) => response.ok ? response.json() : Promise.reject())
        .then((response) => {
            if (!Array.isArray(response.data) || response.data.length === 0) return;
            pageLinks.value = response.data.map((item) => ({ label: item.title, href: knownPaths.get(item.slug) || `/legal/${item.slug}` }));
        })
        .catch(() => {});
});
</script>

<template>
    <main class="inner-page">
        <section class="page-hero section-pad"><div data-reveal><a href="/">Home</a><span>/</span><b>{{ page.eyebrow }}</b><h1>{{ page.title }}</h1><p>{{ page.intro }}</p><small>{{ page.date }}</small></div></section>
        <section class="document-layout section-pad">
            <aside class="document-nav" data-reveal><span>IN THIS WEBSITE</span><a v-for="item in pageLinks" :key="item.href" :href="item.href" :class="{ active: item.label === page.title || (page.title === 'Return & Refund' && item.href === '/return-policy') }">{{ item.label }}</a></aside>
            <article class="policy-document" data-reveal><section v-for="(section, index) in page.sections" :key="section[0]"><span>0{{ index + 1 }}</span><div><h2>{{ section[0] }}</h2><p>{{ section[1] }}</p></div></section><div class="policy-help"><span>Still have questions?</span><h3>Kan support team-in an pui thei che.</h3><a :href="`mailto:${links.supportEmail}`">{{ links.supportEmail }} ↗</a></div></article>
        </section>
    </main>
</template>
