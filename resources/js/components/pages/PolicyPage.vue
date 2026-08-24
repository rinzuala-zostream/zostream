<script setup>
import { onMounted, ref } from 'vue';
import { legalLinks, links } from '../../data/landing';
defineProps({ page: { type: Object, required: true } });
const pageLinks = ref(legalLinks);
const knownPaths = new Map(legalLinks.map((item) => [item.href.slice(1), item.href]));
const linkPattern = /(https?:\/\/[^\s<]+|www\.[^\s<]+|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,})/gi;

const linkify = (text = '') => {
    const parts = [];
    let cursor = 0;

    for (const match of text.matchAll(linkPattern)) {
        if (match.index > cursor) parts.push({ text: text.slice(cursor, match.index) });

        const trailing = match[0].match(/[),.;:!?]+$/)?.[0] || '';
        const label = trailing ? match[0].slice(0, -trailing.length) : match[0];
        const href = label.includes('@') && !label.startsWith('http')
            ? `mailto:${label}`
            : label.startsWith('www.') ? `https://${label}` : label;

        parts.push({ text: label, href });
        if (trailing) parts.push({ text: trailing });
        cursor = match.index + match[0].length;
    }

    if (cursor < text.length) parts.push({ text: text.slice(cursor) });
    return parts.length ? parts : [{ text }];
};

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
    <main class="inner-page legal-page">
        <section class="page-hero section-pad"><div data-reveal><a href="/">Home</a><span>/</span><b>{{ page.eyebrow }}</b><h1>{{ page.title }}</h1><p><template v-for="(part, index) in linkify(page.intro)" :key="index"><a v-if="part.href" :href="part.href" :target="part.href.startsWith('mailto:') ? undefined : '_blank'" rel="noopener noreferrer">{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p><small>{{ page.date }}</small></div></section>
        <section class="document-layout section-pad">
            <aside class="document-nav" data-reveal><span>IN THIS WEBSITE</span><a v-for="item in pageLinks" :key="item.href" :href="item.href" :class="{ active: item.label === page.title || (page.title === 'Return & Refund' && item.href === '/return-policy') }">{{ item.label }}</a></aside>
            <article class="policy-document" data-reveal><section v-for="(section, index) in page.sections" :key="section[0]"><span>0{{ index + 1 }}</span><div><h2>{{ section[0] }}</h2><p><template v-for="(part, partIndex) in linkify(section[1])" :key="partIndex"><a v-if="part.href" :href="part.href" :target="part.href.startsWith('mailto:') ? undefined : '_blank'" rel="noopener noreferrer">{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p></div></section><div class="policy-help"><span>Still have questions?</span><h3>Kan support team-in an pui thei che.</h3><a :href="`mailto:${links.supportEmail}`">{{ links.supportEmail }} ↗</a></div></article>
        </section>
    </main>
</template>
