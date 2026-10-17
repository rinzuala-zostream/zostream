<script setup>
import { formatDateRange } from './dates';
defineProps({ data: { type: Object, default: () => ({}) }, mode: String, cohort: { type: Object, default: () => ({}) } });
const format = (value) => value == null ? 'Unavailable' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 1 }).format(value);
const change = (value) => value == null ? 'No comparable baseline' : `${value > 0 ? '+' : ''}${value}%`;
const metrics = [{ key: 'registrations', label: 'Registrations' }, { key: 'subscriptions', label: 'First-time paying users' }, { key: 'streaming_users', label: 'Unique streaming users' }, { key: 'watch_hours', label: 'Watch hours' }];
</script>
<template>
    <section class="comparison">
        <header><span>{{ mode === 'selected' ? 'SELECTED PERIOD VS PREVIOUS PERIOD' : 'SINCE NEXTFRAME PARTNERSHIP' }}</span><h2>{{ data.available ? `${data.confidence} · ${data.days} days` : 'Comparison unavailable' }}</h2><p v-if="data.available">{{ formatDateRange(data.since_range?.from, data.since_range?.to) }}<template v-if="data.baseline_range"> compared with {{ formatDateRange(data.baseline_range.from, data.baseline_range.to) }}</template></p><p v-else>{{ data.message }}</p><p v-if="data.available && !data.baseline_range">{{ data.baseline_message }}</p><p v-if="data.includes_today" class="notice">Today is still in progress. Current-period totals and daily averages are provisional.</p><small>Period length describes how much history is available; it does not establish a marketing effect.</small></header>
        <div v-if="data.available" class="scroll"><table><thead><tr><th>Business metric</th><th>Baseline total</th><th>Current total</th><th>Change in total</th><th>Baseline / day</th><th>Current / day</th></tr></thead><tbody><tr v-for="metric in metrics" :key="metric.key"><th>{{ metric.label }}<small v-if="metric.key === 'streaming_users'">Daily columns show average daily unique viewers</small></th><td>{{ format(data.baseline?.[metric.key]) }}</td><td>{{ format(data.since?.[metric.key]) }}</td><td>{{ change(data.changes?.[metric.key]) }}</td><td>{{ format(data.daily_averages?.baseline?.[metric.key]) }}</td><td>{{ format(data.daily_averages?.since?.[metric.key]) }}</td></tr></tbody></table></div>
        <footer><b>Registration cohort → paid conversion: {{ cohort.rate == null ? 'Unavailable' : `${cohort.rate}%` }}</b><p>{{ format(cohort.converted_users) }} of {{ format(cohort.registrations) }} users registered in the navbar date range made a successful paid subscription payment within that same range.</p></footer>
    </section>
</template>
<style scoped>
.comparison{margin-bottom:16px;padding:20px;border:1px solid #205476;border-radius:10px;background:linear-gradient(120deg,#09253e,#07172c)}header>span{font-size:11px;font-weight:800;color:#43ceee;letter-spacing:1px}h2{margin:8px 0;font-size:20px}p{margin:7px 0;color:#99b0c7;font-size:12px;line-height:1.6}header>small{color:#88a2bb;font-size:11px}.notice{color:#edca83}.scroll{overflow:auto;margin-top:18px}table{width:100%;white-space:nowrap;border-collapse:collapse;font-size:12px}td,th{padding:12px;border-bottom:1px solid #1c3b53;text-align:right}th:first-child{text-align:left}th small{display:block;font-size:10px;font-weight:400;margin-top:5px;color:#88a2bb}thead{color:#a2c1d8}footer{margin-top:17px;padding-top:14px;border-top:1px solid #1c3b53}footer b{font-size:13px}
</style>
