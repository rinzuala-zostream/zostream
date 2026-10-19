<script setup>
import { computed } from 'vue';
import { formatDate, formatDateTime } from './dates';

const props = defineProps({ data: { type: Object, default: () => ({}) }, compact: Boolean });
const metrics = computed(() => props.data.metrics || {});
const format = (value) => value == null ? '—' : new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value));
const percent = (value) => value == null ? '—' : `${Number(value).toFixed(2)}%`;
const status = computed(() => !props.data.configured ? 'Not configured' : props.data.healthy ? props.data.status === 'partial' ? 'Partial reports' : 'Connected' : 'Needs attention');
const cards = computed(() => [
    ['Android installs', metrics.value.installs, 'Daily user installs in selected dates'],
    ['Uninstalls', metrics.value.uninstalls, 'Daily user uninstalls'],
    ['Active devices', metrics.value.active_device_installs, 'Latest reported snapshot'],
    ['Store visitors', metrics.value.store_visitors, 'Store listing visitors'],
    ['Store acquisitions', metrics.value.store_acquisitions, 'Listing visitors who installed'],
    ['Store conversion', metrics.value.store_conversion_rate, 'Acquisitions ÷ visitors', true],
]);
</script>

<template>
    <article class="play-store" :class="{ compact }">
        <header><div class="mark">▶</div><div><span>GOOGLE PLAY</span><h2>Android acquisition</h2><p>{{ data.package_name || 'Package not configured' }}</p></div><b :class="{ ok: data.healthy, partial: data.status === 'partial' }">{{ status }}</b></header>
        <p v-if="data.message" class="message">{{ data.message }}</p>
        <div v-if="data.configured" class="metrics"><section v-for="card in cards" :key="card[0]"><span>{{ card[0] }}</span><b>{{ card[3] ? percent(card[1]) : format(card[1]) }}</b><small>{{ card[2] }}</small></section></div>
        <footer><span>As of {{ data.as_of ? formatDate(data.as_of) : '—' }} · Google Play reports may lag up to {{ data.reporting_delay_days || 7 }} days.</span><small>Last sync {{ data.last_successful_sync ? formatDateTime(data.last_successful_sync) : '—' }}</small></footer>
    </article>
</template>

<style scoped>
.play-store{min-width:0;padding:19px;border:1px solid #28533c;border-radius:11px;background:linear-gradient(135deg,#092519,#06152a);color:#edf5ff}.play-store>header{display:flex;align-items:center;gap:12px}.mark{display:grid;place-items:center;width:43px;height:43px;flex-shrink:0;border-radius:12px;background:linear-gradient(135deg,#34d058,#16883a);color:#fff;font-size:18px}.play-store header>div:nth-child(2){display:grid;gap:3px;min-width:0}.play-store header span{color:#69dfa0;font-size:9px;font-weight:850;letter-spacing:1.2px}.play-store h2{margin:0;font-size:16px}.play-store header p{margin:0;color:#8caea0;font-size:10px;overflow-wrap:anywhere}.play-store header>b{margin-left:auto;padding:6px 8px;border-radius:7px;background:#392038;color:#e8a9dc;font-size:10px;white-space:nowrap}.play-store header>b.ok{background:#0b4630;color:#62eaa3}.play-store header>b.partial{background:#443915;color:#f3d47c}.metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px;margin-top:17px}.metrics section{min-width:0;padding:12px;border:1px solid #1c4636;border-radius:8px;background:#082019}.metrics span,.metrics small{display:block;color:#8eb4a3;font-size:10px;line-height:1.45}.metrics b{display:block;margin:7px 0 5px;font-size:21px;overflow-wrap:anywhere}.message{padding:10px;border:1px solid #5b4930;border-radius:7px;background:#2c2418;color:#e9c98e;font-size:11px;line-height:1.5}.play-store>footer{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #1b4032;color:#82a997;font-size:10px}.play-store>footer small{font-size:10px}.compact .metrics{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:900px){.metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:540px){.metrics,.compact .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.play-store>header{align-items:flex-start}.play-store header>b{white-space:normal;text-align:center}}
</style>
