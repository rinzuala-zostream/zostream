<script setup>
import { computed } from 'vue';

const props = defineProps({ social: { type: Object, default: () => ({}) }, compact: Boolean });
defineEmits(['open']);
const format = (value) => value == null ? '—' : new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
const cards = computed(() => [
    { label: 'Social reach', key: 'reach', note: 'Platform totals; audiences may overlap' },
    { label: 'Content interactions', key: 'engagement', note: 'Interactions on published content' },
    { label: 'Content views', key: 'views', note: 'Plays and displays reported by each platform' },
    { label: 'Published posts', key: 'posts', note: 'Fetched posts published in selected dates' },
].map((card) => {
    const value = (platform) => {
        const account = props.social[platform];
        if (!account?.healthy) return null;
        if (card.key === 'posts') return account.posts?.length ?? null;
        if (card.key === 'engagement') return account.engagement;
        return account.insights?.[card.key] ?? null;
    };
    const instagram = value('instagram');
    const facebook = value('facebook');
    return { ...card, instagram, facebook, total: instagram == null && facebook == null ? null : (instagram ?? 0) + (facebook ?? 0), partial: (instagram == null) !== (facebook == null) };
}));
</script>

<template>
    <section class="social-summary" :class="{ compact }" aria-label="Social performance summary">
        <header><div><span>SOCIAL PERFORMANCE</span><h2>Facebook & Instagram at a glance</h2><p>Platform breakdowns accompany every total.</p></div><button @click="$emit('open')">Explore insights →</button></header>
        <div class="cards"><article v-for="card in cards" :key="card.key"><span>{{ card.label }}</span><div class="total"><b>{{ format(card.total) }}</b><small>{{ card.partial ? 'Partial total' : 'Combined' }}</small></div><dl><div class="instagram"><dt>◎ Instagram</dt><dd>{{ format(card.instagram) }}</dd></div><div class="facebook"><dt>f Facebook</dt><dd>{{ format(card.facebook) }}</dd></div></dl><p>{{ card.note }}</p></article></div>
    </section>
</template>

<style scoped>
.social-summary{margin:22px 0}.social-summary>header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:13px}header span{color:#73d8d0;font-size:10px;letter-spacing:1.4px;font-weight:800}h2{margin:6px 0;font-size:18px}header p,article>p{margin:0;color:#94abc2;font-size:12px;line-height:1.5}button{padding:10px 13px;border:1px solid #326561;border-radius:8px;background:#102d30;color:#b3eee5;font-size:12px;cursor:pointer}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}article{padding:17px;border:1px solid #245052;border-radius:10px;background:linear-gradient(145deg,#0a222d,#06162b)}article>span{font-size:13px;color:#d4e6ea}.total{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:12px 0}.total b{font-size:28px}.total small{font-size:10px;color:#9fd7ce}dl{display:grid;gap:9px;margin:0 0 13px;padding-top:12px;border-top:1px solid #20404e}dl>div{display:flex;justify-content:space-between;gap:8px;font-size:12px}.instagram{color:#f193ce}.facebook{color:#6bb5ff}dd{margin:0;font-weight:800}article>p{font-size:11px}@media(max-width:1100px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:540px){.social-summary>header{align-items:flex-start;flex-direction:column}.cards{grid-template-columns:1fr}}
</style>
<style scoped>
.compact{margin:18px 0 0}.compact>header{margin-bottom:11px}.compact header span,.compact header p{display:none}.compact h2{font-size:14px;margin:0}.compact button{font-size:11px;padding:6px 9px;background:transparent;border-color:#1d435c}.compact .cards{gap:13px}.compact article{padding:13px 15px;background:#05172b;border-color:#173955}.compact article>span{font-size:12px}.compact .total{margin:8px 0}.compact .total b{font-size:23px}.compact dl{gap:6px;padding-top:9px;margin-bottom:7px}.compact article>p{font-size:10px}.compact dl>div{font-size:11px}
</style>
