<script setup>
import { computed, onMounted, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';

const today = new Date();
const dateValue = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const to = ref(dateValue(today));
const fromDate = new Date(today);
fromDate.setDate(fromDate.getDate() - 6);
const from = ref(dateValue(fromDate));
const platform = ref('');
const config = ref({ enabled: false, updated_at: null });
const report = ref({ overview: {}, watch_trend: [], streaming_health: [], platforms: [], top_content: [], product_events: [] });
const loading = ref(true);
const configLoading = ref(true);
const saving = ref(false);
const error = ref('');
const configError = ref('');
const notice = ref('');
const overview = computed(() => report.value.overview || report.value);
const number = (value, digits = 0) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: digits }).format(Number(value || 0));
const percent = (value) => `${number(value, 1)}%`;

async function loadConfig() {
    configLoading.value = true;
    configError.value = '';
    try {
        config.value = await api('/admin/analytics/config', { cache: 'no-store' });
    } catch (reason) {
        configError.value = reason.message;
    } finally {
        configLoading.value = false;
    }
}

async function loadReport() {
    loading.value = true;
    error.value = '';
    try {
        report.value = await api(`/analytic/reports/overview${queryString({ from: from.value, to: to.value, platform: platform.value, timezone: 'Asia/Kolkata' })}`, { cache: 'no-store' });
    } catch (reason) {
        error.value = reason.message;
    } finally {
        loading.value = false;
    }
}

async function setEnabled(enabled) {
    saving.value = true;
    configError.value = '';
    notice.value = '';
    try {
        config.value = await api('/admin/analytics/config', { method: 'PUT', body: { enabled } });
        notice.value = enabled ? 'Analytics collection is on. Connected apps will receive the change live.' : 'Analytics collection is off. New uploads are blocked.';
    } catch (reason) {
        configError.value = reason.message;
    } finally {
        saving.value = false;
    }
}

onMounted(() => { void loadConfig(); void loadReport(); });
</script>

<template>
    <div class="admin-page analytics-page">
        <PageHeader eyebrow="Audience and playback" title="Analytics" description="Playback activity, streaming quality and product events from ZoAnalytics.">
            <button class="admin-secondary" type="button" :disabled="loading || configLoading" @click="loadConfig(); loadReport()">Refresh</button>
        </PageHeader>

        <StatusPanel tone="success" :message="notice" />
        <StatusPanel tone="error" :message="configError || error" />

        <section class="analytics-control admin-panel">
            <div>
                <span class="analytics-eyebrow">LIVE COLLECTION CONTROL</span>
                <h2>Analytics collection</h2>
                <p>Controls <code>/config/analytics/enabled</code> in Firebase. Connected apps follow the change in real time.</p>
                <small v-if="config.updated_at">Last changed {{ new Date(config.updated_at).toLocaleString() }}</small>
            </div>
            <div class="analytics-toggle-wrap">
                <span class="analytics-state" :class="config.enabled ? 'is-on' : 'is-off'">{{ configLoading ? 'Checking…' : config.enabled ? 'On' : 'Off' }}</span>
                <button class="analytics-toggle" type="button" role="switch" :aria-checked="config.enabled" :aria-label="config.enabled ? 'Turn analytics off' : 'Turn analytics on'" :disabled="configLoading || saving || !!configError" :class="{ 'is-on': config.enabled }" @click="setEnabled(!config.enabled)">
                    <span />
                </button>
            </div>
        </section>

        <section class="admin-filter-bar">
            <label>From<input v-model="from" type="date"></label>
            <label>To<input v-model="to" type="date"></label>
            <label>Platform<select v-model="platform"><option value="">All platforms</option><option value="ios">iOS</option><option value="tvos">Apple TV</option><option value="android">Android</option><option value="tv">TV</option></select></label>
            <button class="admin-primary" type="button" :disabled="loading" @click="loadReport">Apply</button>
        </section>

        <div v-if="loading" class="admin-loading">Loading analytics…</div>
        <template v-else-if="!error">
            <section class="analytics-metrics">
                <article><span>Playback starts</span><b>{{ number(overview.playback_starts) }}</b></article>
                <article><span>Valid views</span><b>{{ number(overview.valid_views) }}</b></article>
                <article><span>Unique viewers</span><b>{{ number(overview.unique_viewers) }}</b></article>
                <article><span>Watch hours</span><b>{{ number(overview.watch_hours, 1) }}</b></article>
                <article><span>Avg. startup</span><b>{{ number(overview.average_startup_ms) }}<small> ms</small></b></article>
                <article><span>Completion</span><b>{{ percent(overview.completion_rate) }}</b></article>
                <article><span>Playback errors</span><b>{{ percent(overview.playback_error_rate) }}</b></article>
                <article><span>App sessions</span><b>{{ number(overview.app_sessions) }}</b></article>
            </section>
            <section class="analytics-columns">
                <article class="admin-panel"><header><div><span class="analytics-eyebrow">CONTENT</span><h2>Top watched</h2></div></header><div v-if="!report.top_content?.length" class="admin-empty">Content analytics will show here after sessions upload.</div><div v-else class="analytics-list"><div v-for="item in report.top_content" :key="`${item.content_type}:${item.content_id}`"><span><b>{{ item.title || item.content_id }}</b><small>{{ item.content_type }} · {{ item.content_id }}</small></span><strong>{{ number(item.views) }} views</strong></div></div></article>
                <article class="admin-panel"><header><div><span class="analytics-eyebrow">PRODUCT EVENTS</span><h2>Top app activity</h2></div></header><div v-if="!report.product_events?.length" class="admin-empty">Product events will appear after app activity uploads.</div><div v-else class="analytics-list"><div v-for="item in report.product_events" :key="item.name"><span><b>{{ item.name.replaceAll('_', ' ') }}</b><small>{{ number(item.unique_users) }} unique users</small></span><strong>{{ number(item.count) }}</strong></div></div></article>
            </section>
            <section class="admin-panel analytics-platforms"><header><div><span class="analytics-eyebrow">DEVICE REACH</span><h2>Sessions by platform</h2></div></header><div v-if="!report.platforms?.length" class="admin-empty">Platform distribution appears after playback uploads.</div><div v-else class="analytics-platform-list"><div v-for="item in report.platforms" :key="item.platform"><span>{{ item.platform.toUpperCase() }}</span><b>{{ number(item.sessions) }} sessions</b><small>{{ number(item.viewers) }} viewers · {{ percent(item.percentage) }}</small></div></div></section>
        </template>
    </div>
</template>

<style scoped>
.analytics-control{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:14px 0;padding:20px}.analytics-control h2,.admin-panel h2{margin:6px 0 4px;font:750 19px 'Manrope',sans-serif}.analytics-control p{margin:0;color:var(--a-muted);font-size:12px}.analytics-control code{padding:2px 5px;border-radius:5px;background:var(--a-soft);color:var(--a-text)}.analytics-control small{display:block;margin-top:8px;color:var(--a-muted)}.analytics-eyebrow{color:var(--a-cyan);font-size:9px;font-weight:800;letter-spacing:.15em}.analytics-toggle-wrap{display:flex;align-items:center;gap:12px}.analytics-state{min-width:40px;font-size:12px;font-weight:800}.analytics-state.is-on{color:#059669}.analytics-state.is-off{color:var(--a-muted)}.analytics-toggle{width:54px;height:30px;padding:3px;border:0;border-radius:99px;background:#64748b;cursor:pointer;transition:background .2s}.analytics-toggle.is-on{background:#10b981}.analytics-toggle:disabled{cursor:not-allowed;opacity:.55}.analytics-toggle span{display:block;width:24px;height:24px;border-radius:50%;background:#fff;transition:transform .2s}.analytics-toggle.is-on span{transform:translateX(24px)}.analytics-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0}.analytics-metrics article{padding:16px;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel)}.analytics-metrics span{display:block;color:var(--a-muted);font-size:10px}.analytics-metrics b{display:block;margin-top:8px;color:var(--a-cyan);font:750 22px 'Manrope',sans-serif}.analytics-metrics small{font-size:11px}.analytics-columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.analytics-columns>.admin-panel,.analytics-platforms{padding:18px}.analytics-columns header,.analytics-platforms header{margin-bottom:14px}.analytics-list>div,.analytics-platform-list>div{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-top:1px solid var(--a-line)}.analytics-list span{min-width:0}.analytics-list b,.analytics-list small{display:block}.analytics-list b{overflow:hidden;text-overflow:ellipsis;text-transform:capitalize}.analytics-list small,.analytics-platform-list small{margin-top:4px;color:var(--a-muted);font-size:10px}.analytics-list strong,.analytics-platform-list b{font-size:12px}.analytics-platforms{margin-top:14px}.analytics-platform-list{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.analytics-platform-list>div{align-items:flex-start;flex-direction:column;padding:12px;border:1px solid var(--a-line);border-radius:11px}.admin-filter-bar{align-items:end}.admin-filter-bar label input{display:block;margin-top:5px}.admin-filter-bar label select{margin-top:5px}@media(max-width:800px){.analytics-metrics{grid-template-columns:repeat(2,1fr)}.analytics-columns{grid-template-columns:1fr}.analytics-platform-list{grid-template-columns:repeat(2,1fr)}}
</style>
