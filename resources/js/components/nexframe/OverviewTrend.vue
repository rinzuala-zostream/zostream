<script setup>
import { computed, ref } from 'vue';
import { formatDate } from './dates';

const props = defineProps({ rows: { type: Array, default: () => [] }, platforms: { type: Array, default: () => [] } });
const hover = ref(null);
const showValues = ref(false);
const format = (value) => value == null ? '—' : new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
const labelDate = (date) => formatDate(date, { short: true });
const x = (index) => props.rows.length < 2 ? 380 : index / (props.rows.length - 1) * 760;
const series = computed(() => [
    { key: 'social_engagement', label: 'Social interactions', color: '#20d2ff', axis: 'left' },
    { key: 'subscriptions', label: 'First-time paying users', color: '#c77dff', axis: 'right' },
].map((item) => {
    const max = Math.max(1, ...props.rows.map((row) => Number(row[item.key] ?? 0)));
    const segments = []; let points = [];
    const allPoints = [];
    props.rows.forEach((row, index) => {
        if (row[item.key] == null) { if (points.length) segments.push(points); points = []; return; }
        const point = { x: x(index), y: 190 - Number(row[item.key]) / max * 175, index };
        points.push(point); allPoints.push(point);
    });
    if (points.length) segments.push(points);
    return { ...item, max, points: allPoints, segments: segments.map((segment) => ({
        line: segment.map((point) => `${point.x},${point.y}`).join(' '),
        area: `${segment[0].x},190 ${segment.map((point) => `${point.x},${point.y}`).join(' ')} ${segment[segment.length - 1].x},190`,
    })) };
}));
const ticks = computed(() => Array.from({ length: 5 }, (_, i) => 1 - i / 4));
const dateTicks = computed(() => props.rows.map((row, index) => ({ ...row, index })).filter((row) => row.index === 0 || row.index === props.rows.length - 1 || row.index % Math.max(1, Math.ceil(props.rows.length / 6)) === 0));
const selectedIndex = computed(() => Math.min(hover.value ?? props.rows.length - 1, props.rows.length - 1));
const selected = computed(() => props.rows[selectedIndex.value]);
const hasData = computed(() => series.value.some((item) => item.points.length));
function move(event) {
    const bounds = event.currentTarget.getBoundingClientRect();
    hover.value = Math.max(0, Math.min(props.rows.length - 1, Math.round((event.clientX - bounds.left) / bounds.width * (props.rows.length - 1))));
}
</script>

<template>
    <article class="overview-trend">
        <header><div><span class="chart-icon">▥</span><h2>Social Engagement & Zo Stream Growth</h2></div><button @click="showValues = !showValues" :aria-expanded="showValues">{{ showValues ? 'Hide data' : `${rows.length} days · View data` }} ⌄</button></header>
        <div class="legend"><span v-for="item in series" :key="item.key" :style="{ color: item.color }"><i/>{{ item.label }} <small>({{ item.axis }} axis)</small></span></div>
        <template v-if="hasData">
            <div class="plot">
                <div class="scale left"><span v-for="tick in ticks" :key="tick">{{ format(series[0].max * tick) }}</span></div>
                <svg viewBox="0 0 760 205" preserveAspectRatio="none" @mousemove="move" @mouseleave="hover = null" @touchmove.passive="hover = null">
                    <defs><linearGradient id="nf-overview-social-area" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#14caff" stop-opacity=".24"/><stop offset="100%" stop-color="#14caff" stop-opacity="0"/></linearGradient><linearGradient id="nf-overview-paid-area" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#ab61ff" stop-opacity=".18"/><stop offset="100%" stop-color="#ab61ff" stop-opacity="0"/></linearGradient></defs>
                    <path v-for="tick in ticks" :key="`h${tick}`" :d="`M0 ${190-tick*175}H760`" class="grid"/>
                    <path v-for="tick in dateTicks" :key="`v${tick.date}`" :d="`M${x(tick.index)} 15V190`" class="grid vertical"/>
                    <g v-for="item in series" :key="item.key"><g v-for="(segment,index) in item.segments" :key="index"><polygon :points="segment.area" :fill="item.key === 'subscriptions' ? 'url(#nf-overview-paid-area)' : 'url(#nf-overview-social-area)'"/><polyline :points="segment.line" :stroke="item.color" :style="{ filter: `drop-shadow(0 0 4px ${item.color}66)` }"/></g><circle v-for="point in item.points" :key="point.index" :cx="point.x" :cy="point.y" :r="point.index === selectedIndex ? 4 : 2.5" :fill="item.color"/></g>
                    <path v-if="selected" :d="`M${x(selectedIndex)} 15V190`" class="cursor"/>
                </svg>
                <div class="scale right"><span v-for="tick in ticks" :key="tick">{{ format(series[1].max * tick) }}</span></div>
            </div>
            <div class="dates"><span v-for="tick in dateTicks" :key="tick.date">{{ labelDate(tick.date) }}</span></div>
            <div v-if="selected" class="readout"><b>{{ formatDate(selected.date) }}</b><span v-for="item in series" :key="item.key" :style="{ color: item.color }">{{ item.label }} <strong>{{ format(selected[item.key]) }}</strong></span></div>
        </template>
        <p v-else class="empty">Daily social and payment data are unavailable for this period.</p>
        <footer><span>Daily social data: {{ platforms.length ? platforms.join(' + ') : 'unavailable' }}</span><small>Separate axes · directional comparison</small></footer>
        <div v-if="showValues" class="table"><table><thead><tr><th>Date</th><th>Social interactions</th><th>First-time paying users</th></tr></thead><tbody><tr v-for="row in rows" :key="row.date"><td>{{ formatDate(row.date) }}</td><td>{{ format(row.social_engagement) }}</td><td>{{ format(row.subscriptions) }}</td></tr></tbody></table></div>
    </article>
</template>

<style scoped>
.overview-trend{min-width:0;padding:18px;border:1px solid #19416b;border-radius:11px;background:linear-gradient(130deg,#06182e,#031124);box-shadow:0 8px 30px #0002;overflow:hidden}header{display:flex;align-items:center;justify-content:space-between;gap:12px}header>div{display:flex;align-items:center;gap:10px}.chart-icon{font-size:25px;color:#9d73ff}h2{margin:0;font-size:15px;font-weight:750;line-height:1.4}button{flex-shrink:0;padding:6px 10px;border:1px solid #1d3b58;border-radius:16px;background:#06172b;color:#c4d5e8;font-size:11px;cursor:pointer}.legend{display:flex;gap:20px;flex-wrap:wrap;margin:18px 0;font-size:12px}.legend span{display:flex;align-items:center;gap:7px}.legend i{width:16px;height:3px;background:currentColor;border-radius:3px}.legend small{color:#829bb5;font-size:10px}.plot{display:flex;height:225px;gap:9px}.scale{display:flex;flex-direction:column;justify-content:space-between;padding:15px 0 17px;min-width:32px;font-size:11px;color:#8aa3bf}.scale.right{text-align:right}svg{min-width:0;flex:1;overflow:visible}polyline{fill:none;stroke-width:2;vector-effect:non-scaling-stroke}.grid{fill:none;stroke:#163552;stroke-width:.8}.grid.vertical{opacity:.55}.cursor{stroke:#a6b8d5;stroke-width:1;stroke-dasharray:3 4;opacity:.35}.dates{display:flex;justify-content:space-between;gap:5px;margin:0 38px 13px;color:#829bb5;font-size:10px}.readout{display:flex;align-items:center;flex-wrap:wrap;gap:10px 18px;padding:10px 12px;border:1px solid #143552;border-radius:8px;background:#06182b;font-size:11px}.readout b{color:#d0e0f0}.readout span{display:flex;gap:8px}.readout strong{font-weight:800}footer{display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-top:12px;color:#7d97b2;font-size:10px}footer small{font-size:10px}.empty{min-height:220px;display:grid;place-items:center;color:#8aa3bf;font-size:13px;line-height:1.6}.table{max-height:260px;overflow:auto;margin-top:15px}table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:12px}th,td{text-align:left;padding:10px;border-bottom:1px solid #173953}@media(max-width:600px){header{align-items:flex-start}h2{font-size:13px}.legend{gap:10px;font-size:11px}.legend small{display:none}.plot{height:200px}.dates{font-size:9px}.dates span:nth-child(even){display:none}.readout{gap:8px}.readout span{font-size:10px}}
</style>
