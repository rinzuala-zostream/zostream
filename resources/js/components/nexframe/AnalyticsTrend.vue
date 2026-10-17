<script setup>
import { computed, ref } from 'vue';
import { formatDate } from './dates';
const props = defineProps({ rows: { type: Array, default: () => [] }, series: { type: Array, default: () => [] }, title: String, note: String });
const hover = ref(null);
const format = (value) => value == null ? 'Unavailable' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 1 }).format(value);
const maximum = computed(() => Math.max(1, ...props.rows.flatMap((row) => props.series.map((series) => Number(row[series.key] ?? 0)))));
const x = (index) => props.rows.length < 2 ? 380 : index / (props.rows.length - 1) * 760;
const y = (value) => 175 - Number(value) / maximum.value * 160;
const selected = computed(() => props.rows[Math.min(hover.value ?? props.rows.length - 1, props.rows.length - 1)]);
const paths = computed(() => props.series.map((series) => {
    const segments = []; let current = [];
    props.rows.forEach((row, index) => {
        if (row[series.key] == null) { if (current.length) segments.push(current); current = []; }
        else current.push(`${x(index)},${y(row[series.key])}`);
    });
    if (current.length) segments.push(current);
    return { ...series, segments: segments.map((segment) => segment.join(' ')) };
}));
const hasData = computed(() => props.rows.some((row) => props.series.some((series) => row[series.key] != null)));
function move(event) {
    const bounds = event.currentTarget.getBoundingClientRect();
    hover.value = Math.max(0, Math.min(props.rows.length - 1, Math.round((event.clientX - bounds.left) / bounds.width * (props.rows.length - 1))));
}
</script>
<template>
    <article class="trend">
        <header><h2>{{ title }}</h2><p>{{ note }}</p></header>
        <div class="legend"><span v-for="series in props.series" :key="series.key" :style="{ color: series.color }">● {{ series.label }}</span></div>
        <template v-if="hasData">
            <div class="plot"><div class="scale"><span>{{ format(maximum) }}</span><span>{{ format(maximum / 2) }}</span><span>0</span></div><svg viewBox="0 0 760 190" preserveAspectRatio="none" @mousemove="move" @mouseleave="hover = null"><path d="M0 15H760M0 95H760M0 175H760" class="grid"/><g v-for="series in paths" :key="series.key"><polyline v-for="(segment, i) in series.segments" :key="i" :points="segment" :stroke="series.color"/><circle v-if="selected?.[series.key] != null" :cx="x(Math.min(hover ?? rows.length - 1, rows.length - 1))" :cy="y(selected[series.key])" r="4" :fill="series.color"/></g></svg></div>
            <div class="dates"><span>{{ formatDate(rows[0]?.date) }}</span><span>{{ formatDate(rows[rows.length - 1]?.date) }}</span></div>
            <div v-if="selected" class="values"><b>{{ formatDate(selected.date) }}</b><span v-for="series in props.series" :key="series.key" :style="{color:series.color}">{{ series.label }}: {{ format(selected[series.key]) }}</span></div>
            <details><summary>View daily values</summary><div class="table"><table><thead><tr><th>Date</th><th v-for="series in props.series" :key="series.key">{{ series.label }}</th></tr></thead><tbody><tr v-for="row in rows" :key="row.date"><td>{{ formatDate(row.date) }}</td><td v-for="series in props.series" :key="series.key">{{ format(row[series.key]) }}</td></tr></tbody></table></div></details>
        </template>
        <p v-else class="empty">Daily data is unavailable for these dates. Connect a source with daily insights to display this trend.</p>
    </article>
</template>
<style scoped>
.trend{min-width:0;padding:20px;border:1px solid #173c5b;border-radius:10px;background:linear-gradient(145deg,#071931,#041123)}h2{margin:0;font-size:16px}header p{margin:7px 0 0;color:#91a8c2;font-size:12px;line-height:1.5}.legend,.values{display:flex;flex-wrap:wrap;gap:12px;font-size:12px}.legend{margin:17px 0}.plot{display:flex;gap:12px;height:200px}.scale{display:flex;flex-direction:column;justify-content:space-between;min-width:38px;padding:12px 0;color:#91a8c2;font-size:11px}svg{width:100%;height:100%;overflow:visible}polyline{fill:none;stroke-width:2;vector-effect:non-scaling-stroke}.grid{fill:none;stroke:#17344e;stroke-width:1}.dates{display:flex;justify-content:space-between;margin:10px 0 15px 50px;color:#91a8c2;font-size:11px}.values{padding:12px;border-radius:7px;background:#0b233b}.values b{color:#c8d8e8}.empty{padding:45px 15px;color:#91a8c2;font-size:13px;line-height:1.6}details{margin-top:15px;font-size:12px;color:#b8cedf}summary{cursor:pointer}.table{max-height:300px;overflow:auto;margin-top:12px}table{width:100%;border-collapse:collapse;white-space:nowrap}td,th{padding:10px;text-align:left;border-bottom:1px solid #17344e}
</style>
