<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import ConnectionCard from '../nexframe/ConnectionCard.vue';
import ContentPanel from '../nexframe/ContentPanel.vue';
import FunnelPanel from '../nexframe/FunnelPanel.vue';
import SocialPanel from '../nexframe/SocialPanel.vue';
import SocialSummary from '../nexframe/SocialSummary.vue';
import AnalyticsTrend from '../nexframe/AnalyticsTrend.vue';
import GrowthComparison from '../nexframe/GrowthComparison.vue';
import OverviewTrend from '../nexframe/OverviewTrend.vue';
import { formatDate as displayDate, formatDateTime, formatDateRange, normalizeReportRange } from '../nexframe/dates';

const loading = ref(true);
const error = ref('');
const dashboard = ref(null);
const activeSection = ref('overview');
const comparisonMode = ref('partnership');
const businessSeries = [{ key: 'registrations', label: 'Registrations', color: '#28caff' }, { key: 'subscriptions', label: 'First-time paying users', color: '#7eafff' }, { key: 'streaming_users', label: 'Streaming users', color: '#4adeb2' }];
const socialSeries = [{ key: 'social_engagement', label: 'Daily social interactions', color: '#78d6c7' }];
const search = ref('');
const mobileNav = ref(false);
const signedIn = ref(false);
const checkingSession = ref(true);
const authConfigured = ref(true);
const authError = ref('');
const loggingIn = ref(false);
const credentials = reactive({ email: '', password: '' });
const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const today = new Date();
const partnershipStart = ref(typeof document === 'undefined' ? '' : document.querySelector('meta[name="nexframe-partnership-start"]')?.content || '');
const filters = reactive({
    from: partnershipStart.value,
    to: localDate(today),
});
let filterTimer;
let autoSyncTimer;
let loadSequence = 0;

const metrics = computed(() => dashboard.value?.metrics || {});
const daily = computed(() => dashboard.value?.daily || []);
const social = computed(() => dashboard.value?.social || {});
const socialReach = computed(() => {
    const instagram = social.value.instagram?.insights?.reach;
    const facebook = social.value.facebook?.insights?.reach;
    return { instagram, facebook, value: instagram == null && facebook == null ? null : (instagram ?? 0) + (facebook ?? 0), partial: (instagram == null) !== (facebook == null) };
});
const dailyStreamMaximum = computed(() => Math.max(1, ...daily.value.map((row) => Number(row.streaming_users ?? 0))));
const impact = computed(() => dashboard.value?.impact || {});
const partnership = computed(() => impact.value?.partnership || {});
const socialPosts = computed(() => {
    const term = search.value.trim().toLowerCase();
    const rows = social.value.posts || [];
    if (!term) return rows;
    return rows.filter((row) => `${row.caption} ${row.platform} ${row.type}`.toLowerCase().includes(term));
});
const content = computed(() => {
    const term = search.value.trim().toLowerCase();
    const rows = dashboard.value?.content || [];
    if (!term) return rows;
    return rows.filter((row) => `${row.title} ${row.category}`.toLowerCase().includes(term));
});
const lastUpdated = computed(() => {
    const date = dashboard.value?.business_snapshot?.as_of || dashboard.value?.generated_at;
    return formatDateTime(date);
});
const pageHeading = computed(() => ({
    overview: ['Central Analytics', 'Zo Stream growth and social performance in one view.'],
    social: ['Social Media Insights', 'Reach, views, interactions and post performance from Facebook and Instagram.'],
    impact: ['Zo Stream Growth Impact', 'Track growth from the partnership start date; comparisons stay within the partnership period.'],
    content: ['Content Performance', 'Review releases, Zo Stream views and matching social promotion.'],
    settings: ['Data Connections', 'Monitor the standalone dashboard and Meta API connections.'],
}[activeSection.value]));

const nav = [
    ['overview', 'Overview', 'home'],
    ['social', 'Social Media', 'social'],
    ['impact', 'Growth Impact', 'trend'],
    ['content', 'Content Performance', 'content'],
    ['settings', 'Connections', 'settings'],
];

const icons = {
    home: 'M3 11.5 12 4l9 7.5v8a1.5 1.5 0 0 1-1.5 1.5h-5v-6h-5v6h-5A1.5 1.5 0 0 1 3 19.5z',
    social: 'M7 7.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm10-3a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM14 19.5c.8-3.2 2.7-5 5.5-5 1 0 1.8.2 2.5.6',
    trend: 'M3 19V5m0 14h18M6 15l4-4 3 2 6-7',
    funnel: 'M3 5h18l-7 8v5l-4 2v-7z',
    content: 'M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm5 5 6 3-6 3z',
    settings: 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm0-5 1.1 2.1 2.3.6 2-1.2 1.7 1.7-1.2 2 .6 2.3 2.1 1.1v2.4l-2.1 1.1-.6 2.3 1.2 2-1.7 1.7-2-1.2-2.3.6-1.1 2.1H10l-1.1-2.1-2.3-.6-2 1.2-1.7-1.7 1.2-2-.6-2.3-2.1-1.1V11l2.1-1.1.6-2.3-1.2-2L4.6 5l2 1.2 2.3-.6L10 3.5z',
};

function number(value, compact = false) {
    if (value == null) return '—';
    return new Intl.NumberFormat('en-IN', compact ? { notation: 'compact', maximumFractionDigits: 1 } : {}).format(Number(value));
}

function change(metric) {
    if (metric?.value == null) return 'Unavailable';
    if (metric?.change == null) return 'No comparable baseline';
    const value = Number(metric.change);
    return `${value >= 0 ? '↑ +' : '↓ '}${value}%`;
}

function signedPercent(value) {
    if (value == null) return 'New data';
    const numeric = Number(value);
    return `${numeric > 0 ? '+' : ''}${numeric}%`;
}

function rangeLabel() {
    return formatDateRange(filters.from, filters.to);
}

function chartPoints(key, width = 760, height = 190) {
    if (!daily.value.length) return '';
    if (daily.value.every((row) => row[key] == null)) return '';
    const values = daily.value.map((row) => Number(row[key] || 0));
    const max = Math.max(1, ...values);
    return values.map((value, index) => {
        const x = daily.value.length === 1 ? width / 2 : (index / (daily.value.length - 1)) * width;
        const y = height - (value / max) * (height - 24) - 8;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
}

function chartArea(key) {
    const points = chartPoints(key);
    return points ? `0,190 ${points} 760,190` : '';
}

function axisLabel(date) {
    return displayDate(date, { short: true });
}

function selectSection(section) {
    activeSection.value = section;
    mobileNav.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function load(forceRefresh = false) {
    window.clearTimeout(filterTimer);
    if (!signedIn.value) {
        loading.value = false;
        return;
    }
    const sequence = ++loadSequence;
    loading.value = true;
    error.value = '';
    try {
        // Normalize synchronously: a date watcher may not have run before login,
        // navigation or auto-refresh initiates this request.
        Object.assign(filters, normalizeReportRange(filters, partnershipStart.value, localDate(new Date())));
        const query = new URLSearchParams({ ...filters, comparison: comparisonMode.value });
        if (forceRefresh) query.set('refresh', '1');
        const report = await request(`/analytics/nexframe/data?${query}`, { cache: 'no-store' });
        if (sequence === loadSequence) dashboard.value = report;
    } catch (reason) {
        if (sequence === loadSequence) error.value = reason.message || 'Dashboard data could not be loaded.';
    } finally {
        if (sequence === loadSequence) loading.value = false;
    }
}

function formatDate(value, includeTime = false) {
    return displayDate(value, { includeTime });
}

function connectionText(platform) {
    if (!platform?.configured) return 'Not configured';
    if (platform.healthy) return 'Connected';
    return 'Needs attention';
}

function exportReport() {
    const headers = ['date', 'registrations', 'subscriptions', 'streaming_users', 'social_engagement'];
    const rows = daily.value.map((row) => headers.map((key) => row[key] ?? '').join(','));
    const blob = new Blob([[headers.join(','), ...rows].join('\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `nexframe-impact-${filters.from}-${filters.to}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

watch(comparisonMode, () => { if (signedIn.value) load(false); });

watch(
    () => [filters.from, filters.to],
    ([from, to], previous) => {
        window.clearTimeout(filterTimer);
        if (partnershipStart.value) {
            if (from && from < partnershipStart.value) {
                filters.from = partnershipStart.value;
                return;
            }
            if (to && to < partnershipStart.value) {
                filters.to = partnershipStart.value;
                return;
            }
        }
        if (!signedIn.value || !from || !to || from > to || !previous) return;
        filterTimer = window.setTimeout(() => load(false), 350);
    }
);

async function request(url, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrf) headers.set('X-CSRF-TOKEN', csrf);
    let body = options.body;
    if (body && typeof body !== 'string') {
        headers.set('Content-Type', 'application/json');
        body = JSON.stringify(body);
    }
    const response = await fetch(url, { ...options, headers, body, credentials: 'same-origin' });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || `Request failed (${response.status})`);
    return payload;
}

async function checkSession() {
    try {
        const status = await request('/analytics/nexframe/session', { cache: 'no-store' });
        if (Object.hasOwn(status, 'partnership_started_at')) partnershipStart.value = status.partnership_started_at || '';
        filters.from = partnershipStart.value || localDate(new Date());
        signedIn.value = status.authenticated === true;
        authConfigured.value = status.configured === true;
        if (signedIn.value) await load();
    } catch (reason) {
        authError.value = reason.message;
    } finally {
        checkingSession.value = false;
        if (!signedIn.value) loading.value = false;
    }
}

async function login() {
    loggingIn.value = true;
    authError.value = '';
    try {
        await request('/analytics/nexframe/login', { method: 'POST', body: credentials });
        signedIn.value = true;
        credentials.password = '';
        // Read the current server boundary again after login (including when
        // deployment/config changed while the login screen was open).
        await checkSession();
    } catch (reason) {
        authError.value = reason.message;
    } finally {
        loggingIn.value = false;
    }
}

async function logout() {
    ++loadSequence;
    await request('/analytics/nexframe/logout', { method: 'POST' }).catch(() => {});
    dashboard.value = null;
    signedIn.value = false;
}

onMounted(() => {
    checkSession();
    autoSyncTimer = window.setInterval(() => {
        if (signedIn.value && !loading.value) load(true);
    }, 300_000);
});
onBeforeUnmount(() => {
    window.clearTimeout(filterTimer);
    window.clearInterval(autoSyncTimer);
});
</script>

<template>
    <div class="nf-app">
        <div v-if="checkingSession" class="nf-auth-gate"><div class="nf-loading"><i /><span>Opening NextFrame Analytics…</span></div></div>
        <div v-else-if="!signedIn" class="nf-auth-gate">
            <div class="nf-auth-card">
                <div class="nf-lock">⌁</div>
                <span>SECURE PARTNER ANALYTICS</span>
                <h1>Zo Stream <b>×</b> NextFrame</h1>
                <p>This is a standalone partner dashboard with its own secure access, separate from Zo Stream Admin.</p>
                <form class="nf-login-form" @submit.prevent="login">
                    <label>Email<input v-model.trim="credentials.email" type="email" autocomplete="username" required placeholder="partner@example.com"></label>
                    <label>Password<input v-model="credentials.password" type="password" autocomplete="current-password" required placeholder="Enter dashboard password"></label>
                    <span v-if="authError" class="nf-auth-error">{{ authError }}</span>
                    <span v-else-if="!authConfigured" class="nf-auth-error">Dashboard credentials need to be configured on the server.</span>
                    <button :disabled="loggingIn || !authConfigured">{{ loggingIn ? 'Signing in…' : 'Sign in to dashboard' }} <i>→</i></button>
                </form>
            </div>
        </div>

        <template v-else>
            <header class="nf-topbar">
                <button class="nf-menu" aria-label="Open navigation" @click="mobileNav = !mobileNav">☰</button>
                <a class="nf-zostream" href="/"><img :src="'/images/zostream-logo.jpg'" alt=""><b><i>Zo</i> Stream</b></a>
                <span class="nf-partner-x">×</span>
                <div class="nf-nextframe"><span>◇</span><b>NextFrame Studio</b></div>
                <i class="nf-divider" />
                <strong class="nf-page-name">{{ pageHeading[0] }}</strong>
                <div class="nf-date" :title="rangeLabel()"><span>▣</span><input v-model="filters.from" type="date" aria-label="Date range start" :min="partnershipStart || undefined" :max="filters.to"><i>–</i><input v-model="filters.to" type="date" aria-label="Date range end" :min="filters.from || partnershipStart || undefined" :max="localDate(today)"></div>
                <label class="nf-search"><span>⌕</span><input v-model="search" placeholder="Search content…"></label>
                <div class="nf-live"><i /> <span><b>{{ loading ? 'Refreshing…' : 'Auto refresh' }}</b><small>Business snapshot {{ lastUpdated }}</small></span></div>
                <button class="nf-user" title="Sign out" @click="logout"><span>NF</span><p><b>NextFrame</b><small>Partner access · Sign out</small></p></button>
            </header>

            <button v-if="mobileNav" class="nf-nav-scrim" aria-label="Close navigation" @click="mobileNav = false" />
            <aside class="nf-sidebar" :class="{ open: mobileNav }">
                <nav>
                    <button v-for="item in nav" :key="item[0]" :class="{ active: activeSection === item[0] }" @click="selectSection(item[0])">
                        <svg viewBox="0 0 24 24"><path :d="icons[item[2]]" /></svg>
                        <span>{{ item[1] }}</span><em v-if="item[0] === 'social'">SYNC</em>
                    </button>
                </nav>
                <div class="nf-side-promo">
                    <img :src="'/images/zostream-logo.jpg'" alt="">
                    <b><i>Zo</i> Stream</b>
                    <p>Entertainment for everyone in Mizoram</p>
                    <div class="nf-promo-scene" :style="{ backgroundImage: 'linear-gradient(0deg,#00102144,#00102188), url(/images/featured-poster.jpg)' }"><span>▶</span></div>
                </div>
                <div class="nf-agency-card"><span>◇</span><p><b>NextFrame Studio</b><small>Social Media Agency Partner</small></p><em>Create · Engage · Grow</em></div>
            </aside>

            <main class="nf-main" :class="{ 'nf-main-overview': activeSection === 'overview' }">
                <header class="nf-view-header">
                    <div><span>ZO STREAM × NEXTFRAME</span><h1>{{ pageHeading[0] }}</h1><p>{{ pageHeading[1] }}</p></div>
                </header>
                <div v-if="error" class="nf-error">{{ error }} <button @click="load(false)">Retry</button></div>
                <div v-if="loading && !dashboard" class="nf-loading"><i /><span>Syncing analytics…</span></div>

                <template v-if="dashboard">
                    <section class="nf-report-dates" aria-label="Report dates" aria-live="polite">
                        <div><span>Showing data for</span><strong>{{ formatDateRange(dashboard.range.from, dashboard.range.to) }}</strong><small>{{ dashboard.range.days }} {{ dashboard.range.days === 1 ? 'day' : 'days' }} · Dates inclusive · IST (UTC+05:30)</small></div>
                        <div v-if="partnershipStart"><span>Partnership started</span><b>{{ displayDate(partnershipStart) }}</b><small>No reports before this date</small></div>
                        <div v-if="dashboard.range.previous_from"><span>Compared with</span><b>{{ formatDateRange(dashboard.range.previous_from, dashboard.range.previous_to) }}</b><small>Previous equal-length period</small></div>
                    </section>
                    <div v-if="dashboard.business_snapshot?.stale" class="nf-data-notice">Showing the last business snapshot from {{ lastUpdated }} while fresh analytics are being prepared. Social insights have their own sync time.</div>
                    <div v-if="dashboard.streaming_source?.id === 'watch_sessions'" class="nf-data-notice">Streaming source: Zo Stream watch-duration logs. Viewers and watch hours are available; playback-session totals are not recorded by this source.</div>
                    <div v-if="dashboard.range.includes_today" class="nf-data-notice">Today is in progress; selected-period totals are provisional.</div>
                    <div v-if="!dashboard.source_status?.streaming || !dashboard.source_status?.payments" class="nf-data-notice">Source unavailable: {{ [!dashboard.source_status?.streaming && 'streaming analytics', !dashboard.source_status?.payments && 'successful subscription payments'].filter(Boolean).join(', ') }}. Missing metrics display as —.</div>
                    <template v-if="activeSection === 'overview'">
                        <div class="nf-kpis">
                            <article class="nf-kpi-registration"><header><span class="nf-kpi-icon cyan"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 4a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/></svg></span><p><b>New Account Registrations</b><small>All Zo Stream platforms</small></p></header><div class="nf-kpi-value"><strong>{{ number(metrics.registrations?.value) }}</strong><span :class="{ down: metrics.registrations?.change < 0 }">{{ change(metrics.registrations) }}</span></div><svg class="nf-spark" viewBox="0 0 300 65" preserveAspectRatio="none"><polyline :points="chartPoints('registrations',300,65)" /></svg><footer class="nf-kpi-footer">{{ dashboard.range.previous_from ? `Compared with the previous ${dashboard.range.days} days` : 'Comparison starts within the partnership period only' }}</footer></article>
                            <article class="nf-kpi-paid"><header><span class="nf-kpi-icon blue"><svg viewBox="0 0 24 24"><path d="m3 6 4.5 5L12 3l4.5 8L21 6l-2 13H5zM5 22h14"/></svg></span><p><b>First-time Paying Users</b><small>First successful paid subscription</small></p></header><div class="nf-kpi-value"><strong>{{ number(metrics.subscriptions?.value) }}</strong><span :class="{ down: metrics.subscriptions?.change < 0 }">{{ change(metrics.subscriptions) }}</span></div><svg class="nf-spark purple" viewBox="0 0 300 65" preserveAspectRatio="none"><polyline :points="chartPoints('subscriptions',300,65)" /></svg><footer class="nf-kpi-footer">{{ number(metrics.active_subscriptions?.value) }} subscriptions active now</footer></article>
                            <article class="nf-kpi-streaming"><header><span class="nf-kpi-icon violet"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="14" rx="3"/><path d="m10 8 5 3-5 3zM8 22h8"/></svg></span><p><b>Streaming Audience</b><small>Unique viewers in selected dates</small></p></header><div class="nf-kpi-value"><strong>{{ number(metrics.streaming_users?.value) }}</strong><span :class="{ down: metrics.streaming_users?.change < 0 }">{{ change(metrics.streaming_users) }}</span></div><div class="nf-mini-bars"><i v-for="row in daily" :key="row.date" :class="{ 'nf-bar-unavailable': row.streaming_users == null }" :style="{ height: row.streaming_users == null ? '0%' : Math.max(2, row.streaming_users / dailyStreamMaximum * 100) + '%' }" /></div><footer class="nf-kpi-footer">{{ number(metrics.watch_hours?.value) }} watch hours · {{ number(metrics.streaming_sessions?.value) }} sessions</footer></article>
                            <article class="nf-kpi-social"><header><span class="nf-kpi-icon pink"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/></svg></span><p><b>Social Media Reach</b><small>Facebook + Instagram · audience overlap possible</small></p></header><div class="nf-kpi-value"><strong>{{ number(socialReach.value,true) }}</strong><span class="pending">{{ socialReach.value == null ? 'Awaiting insights' : socialReach.partial ? 'Partial total' : 'Combined reach' }}</span></div><div class="nf-social-status"><span class="ig">◎</span><p><b>Instagram</b><small>{{ number(socialReach.instagram,true) }} reach</small></p><span class="fb">f</span><p><b>Facebook</b><small>{{ number(socialReach.facebook,true) }} reach</small></p></div><footer class="nf-kpi-footer">{{ social.connected_platforms || 0 }} of 2 platforms connected</footer></article>
                        </div>
                        <div class="nf-grid-main">
                            <OverviewTrend :rows="daily" :platforms="social.daily_engagement_platforms || []" />
                            <FunnelPanel :rows="dashboard.funnel" />
                        </div>
                        <div class="nf-grid-bottom">
                            <SocialPanel :social="social" :posts="socialPosts" compact @open="selectSection('social')" />
                            <ContentPanel :rows="content.slice(0,6)" @open="selectSection('content')" />
                        </div>
                        <SocialSummary :social="social" compact @open="selectSection('social')" />
                    </template>

                    <template v-else-if="activeSection === 'social'">
                        <SocialPanel :social="social" :posts="socialPosts" expanded />
                    </template>

                    <template v-else-if="activeSection === 'impact'">
                        <div class="nf-comparison-controls"><label>Comparison window<select v-model="comparisonMode"><option value="partnership">Since partnership started</option><option value="selected">Selected dates vs previous period</option></select></label><button class="nf-panel-button" @click="exportReport">Export daily CSV</button></div>
                        <GrowthComparison :data="partnership" :mode="comparisonMode" :cohort="impact.registration_cohort" />

                        <div class="nf-growth-charts"><AnalyticsTrend :rows="daily" :series="businessSeries" title="Zo Stream daily activity" note="Counts from registrations and first successful subscription payments."/><AnalyticsTrend :rows="daily" :series="socialSeries" title="Social daily interactions" :note="'Meta reporting-day totals. Available platforms: ' + (social.daily_engagement_platforms?.join(', ') || 'none') + '. Source reporting timezones may differ.'"/></div>
                        <div class="nf-attribution"><b>Directional analysis</b><p>{{ impact.attribution_note }}</p><span>Correlation: {{ impact.social_subscription_correlation ?? 'Requires at least 7 paired days with daily social insights' }}</span></div>
                        <div class="nf-summary-grid nf-payment-summary"><article><span>First-time paying users</span><b>{{ number(metrics.subscriptions?.value) }}</b></article><article><span>Repeat subscription payments</span><b>{{ number(metrics.repeat_payments?.value) }}</b></article><article><span>Successful paid transactions</span><b>{{ number(metrics.successful_payments?.value) }}</b></article><article><span>Unique paying users</span><b>{{ number(metrics.paying_users?.value) }}</b></article></div>
                    </template>

                    <template v-else-if="activeSection === 'content'"><ContentPanel :rows="content" expanded /></template>

                    <template v-else-if="activeSection === 'settings'">
                        <div class="nf-settings-grid"><ConnectionCard platform="Instagram Business" icon="◎" :data="social.instagram" variables="NEXFRAME_INSTAGRAM_ACCESS_TOKEN"/><ConnectionCard platform="Facebook Page" icon="f" :data="social.facebook" variables="NEXFRAME_FACEBOOK_PAGE_ACCESS_TOKEN · NEXFRAME_FACEBOOK_PAGE_ID · NEXFRAME_META_API_VERSION"/></div>
                        <section class="nf-connection-note"><span>ZO STREAM DATABASE</span><p>Registrations, subscriptions, catalog and playback analytics are read-only in this dashboard. No database schema changes are required.</p></section>
                    </template>
                </template>
            </main>
        </template>
    </div>
</template>

<style scoped>
.nf-report-dates{display:flex;flex-wrap:wrap;gap:18px 32px;margin-bottom:18px;padding:18px 20px;border:1px solid #235176;border-radius:10px;background:linear-gradient(115deg,#09243d,#061629)}.nf-report-dates>div{display:grid;gap:5px;min-width:0}.nf-report-dates span{font-size:13px;color:#a6bfd7}.nf-report-dates strong{font-size:20px;line-height:1.4;color:#e7f7ff}.nf-report-dates b{font-size:16px;line-height:1.6;color:#d2e9fa}.nf-report-dates small{font-size:12px;color:#91aec9;line-height:1.5}@media(max-width:600px){.nf-report-dates{padding:15px;gap:15px}.nf-report-dates>div{width:100%}.nf-report-dates strong{font-size:18px}}

:global(body){background:#020d1d}
.nf-app{--cyan:#20caff;--muted:#8da6bf;min-height:100vh;background:#020d1d;color:#edf5ff;font-family:'DM Sans',sans-serif}
.nf-app button{font-family:inherit;cursor:pointer}
.nf-topbar{position:fixed;z-index:30;inset:0 0 auto;display:flex;align-items:center;border-bottom:1px solid #17344e;backdrop-filter:blur(18px)}
.nf-zostream,.nf-nextframe{display:flex;align-items:center;gap:9px;white-space:nowrap}
.nf-zostream{color:inherit;text-decoration:none}.nf-zostream img{border-radius:8px;object-fit:cover}.nf-zostream b{font-family:'Manrope',sans-serif;font-weight:850}.nf-zostream b i{font-style:normal;background:linear-gradient(100deg,#00e7ff,#2285ff);background-clip:text;color:transparent}
.nf-partner-x{color:#7991ad}.nf-nextframe>span{display:grid;place-items:center;width:28px;height:28px;border:1px solid #a9bdd4;font-size:21px}.nf-divider{height:30px;width:1px;background:#24405b}.nf-page-name{font-weight:500;color:#92abc6}
.nf-date{display:flex;align-items:center;margin-left:auto;border:1px solid;border-radius:22px;color:#c7d9ec}.nf-date input{width:107px;padding:0;border:0;background:transparent;color:#d8e8fa;color-scheme:dark;font:600 12px 'DM Sans',sans-serif;min-width:0}.nf-date i{font-style:normal;color:#6988a8}.nf-date input::-webkit-calendar-picker-indicator{opacity:.75;cursor:pointer}
.nf-search{display:flex;align-items:center;gap:8px;padding:0 11px;margin-left:16px;border:1px solid;border-radius:20px;color:#7e9ab6}.nf-search input{min-width:0;width:100%;border:0;outline:0;background:transparent;color:#e5f0ff}
.nf-live{display:flex;align-items:center;gap:8px;white-space:nowrap}.nf-live>i{width:8px;height:8px;border-radius:50%;background:#2be1a0;box-shadow:0 0 12px #2be1a055}.nf-live>span,.nf-user p{display:grid;gap:3px}.nf-live small,.nf-user small{color:#7f9bb8}.nf-user{display:flex;align-items:center;gap:9px;padding:0;border:0;background:transparent;color:#e5efff;text-align:left}.nf-user>span{display:grid;place-items:center;border:1px solid #42607f;border-radius:50%;font-weight:800}.nf-user p{margin:0}
.nf-menu{display:none;border:0;background:transparent;color:#dcecff;font-size:22px}
.nf-sidebar{position:fixed;z-index:20;left:0;bottom:0;display:flex;flex-direction:column;overflow-y:auto}
.nf-sidebar nav{display:grid}.nf-sidebar nav button{display:flex;align-items:center;gap:12px;width:100%;background:transparent;color:#b8cbe0;text-align:left}.nf-sidebar nav button:hover{background:#0c2c4c}.nf-sidebar nav svg{flex-shrink:0;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linejoin:round}.nf-sidebar nav span{font-weight:650;white-space:nowrap}.nf-sidebar nav em{margin-left:auto;padding:3px 5px;border-radius:5px;background:#16474e;color:#8fe1d6;font-size:9px;font-style:normal}
.nf-side-promo{margin-top:auto;border:1px solid;border-radius:9px;text-align:center}.nf-side-promo>img{width:28px;height:28px;border-radius:7px;vertical-align:middle}.nf-side-promo>b{margin-left:6px;font-family:'Manrope',sans-serif;font-weight:850}.nf-side-promo>b i{color:var(--cyan);font-style:normal}.nf-side-promo>p{margin:12px 0;color:#a4bcd7}.nf-promo-scene{display:grid;place-items:center;border-radius:7px}.nf-promo-scene span{display:grid;place-items:center;border:1px solid;border-radius:50%;color:#8de0ff}
.nf-agency-card{display:grid;grid-template-columns:auto 1fr;gap:8px;border:1px solid;border-radius:9px}.nf-agency-card>span{font-size:28px;color:#61a5dd}.nf-agency-card p{display:grid;gap:5px;margin:0}.nf-agency-card small,.nf-agency-card em{color:#86a1bd}.nf-agency-card em{grid-column:1/-1;font-style:normal;letter-spacing:.6px}
.nf-main{min-height:100vh}
.nf-view-header{margin-bottom:22px}.nf-view-header>div>span{font-size:10px;color:var(--cyan);letter-spacing:1.4px;font-weight:800}.nf-view-header h1{margin:7px 0;font:800 28px 'Manrope',sans-serif}.nf-view-header p{margin:0;font-size:13px;color:#94adc7;line-height:1.6}
.nf-error{margin-bottom:14px;padding:13px 15px;border:1px solid #69405b;border-radius:9px;background:#2b172a;color:#ffc0d6;font-size:13px;line-height:1.5}.nf-error button{float:right;padding:4px 9px;border:0;border-radius:5px;background:#81335d;color:white}
.nf-loading{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:15px;min-height:45vh;color:#93b0cb;font-size:14px}.nf-loading i{width:30px;height:30px;border:2px solid #204a6b;border-top-color:var(--cyan);border-radius:50%;animation:nf-spin .8s linear infinite}
.nf-kpis{display:grid}.nf-kpis>article{position:relative;min-width:0;overflow:hidden;border:1px solid}.nf-kpis article header{display:flex;align-items:center}.nf-kpis article header p{display:grid;gap:4px;margin:0}.nf-kpis article header small{color:#93abc5}.nf-kpi-icon{display:grid;place-items:center}.nf-kpi-value{position:relative;z-index:1;display:flex}.nf-kpi-value strong{font-family:'Manrope',sans-serif;font-weight:800}.nf-kpi-value>span{border-radius:20px;background:#083d37;color:#39e5ac;font-weight:700}.nf-kpi-value>span.down{background:#421d33;color:#ff91b6}
.nf-spark{position:absolute;left:17px;right:17px;width:calc(100% - 34px);overflow:visible}.nf-spark polyline{fill:none;stroke:#17caff;stroke-width:2.2;vector-effect:non-scaling-stroke;filter:drop-shadow(0 0 5px #12c5ff66)}.nf-spark.purple polyline{stroke:#a877ff;filter:drop-shadow(0 0 5px #a877ff66)}
.nf-mini-bars{position:absolute;left:17px;right:17px;display:flex;align-items:flex-end}.nf-mini-bars i{flex:1;min-width:0;border-radius:3px 3px 0 0;background:linear-gradient(#0ed5f5,#0967ba)}
.nf-social-status{position:absolute;display:grid;grid-template-columns:auto 1fr auto 1fr;align-items:center;border-top:1px solid #173a57}.nf-social-status>span{display:grid;place-items:center;border-radius:7px;font-weight:900}.nf-social-status .ig{background:linear-gradient(135deg,#ffb14b,#d73e9a,#6e4cda)}.nf-social-status .fb{background:#1877f2}.nf-social-status p{display:grid;margin:0}.nf-social-status small{color:#9cb4cb}
.nf-grid-main,.nf-grid-bottom,.nf-growth-charts{display:grid}
.nf-data-notice{border:1px solid;border-radius:8px;line-height:1.5}
.nf-comparison-controls{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:16px}.nf-comparison-controls label{display:grid;gap:8px;color:#9db5cc;font-size:12px}.nf-comparison-controls select{padding:10px 12px;border:1px solid #275675;border-radius:8px;background:#081d33;color:#e1f0fa;font-size:13px}
.nf-panel-button{padding:9px 12px;border:1px solid #245475;border-radius:8px;background:#0b2a47;color:#cde8fa;font-size:12px}
.nf-growth-charts{grid-template-columns:1fr 1fr;gap:16px}.nf-attribution{display:flex;align-items:center;flex-wrap:wrap;gap:14px;margin-top:16px;padding:15px;border:1px solid #20435e;border-radius:9px;background:#071c30;font-size:12px}.nf-attribution b{font-size:13px}.nf-attribution p{flex:1;min-width:220px;margin:0;color:#94adc6;line-height:1.6}.nf-attribution>span{color:#a9d7d5}
.nf-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}.nf-summary-grid article{padding:17px;border:1px solid #1b3c58;border-radius:9px;background:#07182c}.nf-summary-grid span{display:block;color:#99b2cb;font-size:12px}.nf-summary-grid b{display:block;margin-top:10px;font:800 26px 'Manrope',sans-serif}.nf-payment-summary{margin-top:16px}
.nf-settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.nf-connection-note{display:flex;align-items:center;gap:20px;margin-top:16px;padding:17px;border:1px solid #23435c;border-radius:9px;background:#06172b}.nf-connection-note span{flex-shrink:0;color:var(--cyan);font-size:11px;font-weight:800;letter-spacing:.8px}.nf-connection-note p{margin:0;color:#95aec7;font-size:13px;line-height:1.6}
.nf-auth-gate{display:grid;place-items:center;min-height:100vh;padding:24px;background:radial-gradient(circle at 50% 20%,#0b3560,transparent 45%),#020d1d}.nf-auth-card{width:min(460px,100%);padding:38px;border:1px solid #245077;border-radius:17px;background:#06182deb;box-shadow:0 30px 90px #0007;text-align:center}.nf-lock{display:grid;place-items:center;width:56px;height:56px;margin:0 auto 20px;border:1px solid #337dae;border-radius:15px;background:#0d3257;color:#78dcff;font-size:30px}.nf-auth-card>span{font-size:10px;letter-spacing:1.7px;color:#42c9f0;font-weight:800}.nf-auth-card h1{margin:15px 0;font:800 28px 'Manrope',sans-serif}.nf-auth-card h1 b{color:var(--cyan)}.nf-auth-card p{margin:0;color:#95acc5;font-size:13px;line-height:1.7}
.nf-login-form{display:grid;gap:14px;margin-top:26px;text-align:left}.nf-login-form label{display:grid;gap:7px;color:#a0bad2;font-size:12px;font-weight:650}.nf-login-form input{height:44px;padding:0 12px;border:1px solid #285171;border-radius:8px;background:#041226;color:#edf5ff;font:14px 'DM Sans',sans-serif}.nf-login-form button{min-height:44px;border:1px solid #228af5;border-radius:8px;background:#0967d7;color:#fff;font-size:13px;font-weight:750}.nf-login-form button:disabled{opacity:.5;cursor:not-allowed}.nf-login-form button i{margin-left:12px;font-style:normal}.nf-auth-error{padding:11px;border:1px solid #76405e;border-radius:8px;background:#31152b;color:#ffbdd2;font-size:12px;text-align:center;line-height:1.5}
@keyframes nf-spin{to{transform:rotate(360deg)}}
@media(max-width:1050px){.nf-growth-charts{grid-template-columns:1fr}.nf-settings-grid{grid-template-columns:1fr}.nf-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:820px){.nf-sidebar{transform:translateX(-100%);transition:transform .2s}.nf-sidebar.open{transform:translateX(0);box-shadow:20px 0 60px #0008}}
@media(max-width:540px){.nf-auth-card{padding:28px 20px}.nf-auth-card h1{font-size:24px}.nf-connection-note{align-items:flex-start;flex-direction:column;gap:9px}.nf-comparison-controls{align-items:flex-start;flex-direction:column}.nf-summary-grid{grid-template-columns:1fr 1fr}.nf-summary-grid b{font-size:22px}}
/* Reference dashboard layout: four KPIs, wide trend, growth stages, then feed/catalog. */
.nf-app{background:radial-gradient(ellipse at 55% -15%,#09284b66,transparent 55%),#020d1d}.nf-topbar{height:68px;padding:0 24px;background:#031225f5;border-bottom-color:#15324d}.nf-zostream b{font-size:24px;letter-spacing:-.8px}.nf-zostream img{width:33px;height:33px}.nf-partner-x{margin:0 18px;font-size:23px}.nf-nextframe b{font-size:15px}.nf-nextframe>span{border-radius:4px;color:#ecf3ff}.nf-page-name{font-size:14px;white-space:nowrap}.nf-divider{margin:0 18px}.nf-date{padding:10px 13px;background:#071b39;border-color:#1b3e6e;gap:6px}.nf-search{height:38px;width:180px;background:#0a1c32;border-color:#223b55}.nf-search input{font-size:12px}.nf-live{margin-left:22px}.nf-live b{font-size:12px}.nf-live small{font-size:10px}.nf-user{margin-left:22px}.nf-user>span{height:36px;width:36px;font-size:12px;background:linear-gradient(135deg,#1c5d87,#202a58)}.nf-user b{font-size:12px}.nf-user small{font-size:10px}.nf-sidebar{top:68px;width:232px;padding:19px 17px;background:linear-gradient(#041429,#031124);border-right-color:#12324f}.nf-sidebar nav{gap:9px}.nf-sidebar nav button{height:45px;border:1px solid transparent;border-radius:8px;padding:0 12px}.nf-sidebar nav button.active{border-color:#2163a2;background:linear-gradient(105deg,#0c63d2,#084da7);box-shadow:inset 0 0 0 1px #3182ff16,0 5px 18px #0056bf26}.nf-sidebar nav span{font-size:14px}.nf-sidebar nav svg{width:21px;height:21px}.nf-sidebar nav button:last-child{margin-top:16px;border-top:1px solid #244059;border-radius:0;padding-top:15px;height:60px}.nf-sidebar nav button:last-child.active{border-radius:8px}.nf-side-promo{padding:16px 13px;background:linear-gradient(150deg,#091a35,#040d21);border-color:#193953}.nf-side-promo>b{font-size:19px}.nf-side-promo>p{font-size:11px;line-height:1.5}.nf-promo-scene{height:107px;background:linear-gradient(160deg,#072c4d,#031427);background-size:cover;background-position:center 35%}.nf-promo-scene span{height:40px;width:40px;background:#075184b0;border-color:#3d8bac}.nf-agency-card{margin-top:18px;padding:15px 12px;border-color:#193953}.nf-agency-card b{font-size:12px}.nf-agency-card small{font-size:10px;line-height:1.5}.nf-agency-card em{font-size:10px;padding-top:10px;text-align:center}.nf-main{margin-left:232px;padding:88px 20px 32px}.nf-main-overview>.nf-view-header{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;margin:0;padding:0}.nf-kpis{grid-template-columns:repeat(4,minmax(0,1fr));gap:15px}.nf-kpis>article{height:226px;padding:17px;border-color:#1a426c;border-radius:11px;background:linear-gradient(135deg,#061b33,#031124);box-shadow:0 8px 30px #0002}.nf-kpis header{gap:11px}.nf-kpis header p{min-width:0}.nf-kpis header p b{font-size:14px;line-height:1.35}.nf-kpis header p small{font-size:11px;line-height:1.5}.nf-kpi-icon{flex-shrink:0;width:45px;height:45px;border-radius:16px;box-shadow:inset 0 1px 2px #ffffff24,0 5px 15px #0003}.nf-kpi-icon svg{height:23px;width:23px;stroke:currentColor;stroke-width:1.7;fill:none;stroke-linecap:round;stroke-linejoin:round}.nf-kpi-icon.cyan{background:linear-gradient(135deg,#1484dc,#08458e);color:#e0f5ff}.nf-kpi-icon.blue{background:linear-gradient(135deg,#6248ea,#3024a4);color:#ece8ff}.nf-kpi-icon.violet{background:linear-gradient(135deg,#158fc6,#125490);color:#e0f5ff}.nf-kpi-icon.pink{background:linear-gradient(135deg,#6657e8,#353487);color:#e8e9ff}.nf-kpi-value{align-items:center;flex-wrap:wrap;gap:8px;margin-top:18px}.nf-kpi-value strong{font-size:33px;letter-spacing:-.6px;line-height:1.2}.nf-kpi-value>span{max-width:100%;font-size:10px;padding:6px 8px;line-height:1.3;white-space:normal}.nf-kpi-value>span.pending{background:#133234;color:#8fe7d6}.nf-spark{bottom:38px;height:53px}.nf-mini-bars{bottom:39px;height:52px;gap:4px}.nf-mini-bars i{min-height:0;opacity:.9}.nf-mini-bars i.nf-bar-unavailable{opacity:0}.nf-kpi-footer{position:absolute;bottom:13px;left:17px;right:17px;color:#8ca8c4;font-size:10px;line-height:1.4}.nf-social-status{bottom:42px;left:17px;right:17px;gap:6px;padding-top:12px}.nf-social-status>span{height:27px;width:27px;font-size:17px}.nf-social-status b{font-size:11px}.nf-social-status small{font-size:11px;margin-top:3px}.nf-grid-main{grid-template-columns:minmax(0,1.7fr) minmax(300px,.95fr);gap:15px;margin-top:17px;align-items:stretch}.nf-grid-bottom{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:15px;margin-top:17px;align-items:stretch}.nf-main-overview :deep(.panel header b){font-size:15px}.nf-main-overview :deep(.panel .panel-head>div>span){color:#a781ff}.nf-data-notice{padding:8px 12px;margin-bottom:12px;font-size:11px;background:#121f2b;border-color:#344758;color:#b5c5d4}.nf-nav-scrim{position:fixed;inset:68px 0 0;z-index:19;border:0;background:#0009}.nf-app button:focus-visible,.nf-app input:focus-visible,.nf-app select:focus-visible{outline:2px solid #5ad6ff;outline-offset:3px}
@media(max-width:1450px){.nf-nextframe b{font-size:13px}.nf-partner-x{margin:0 12px}.nf-page-name{display:none}.nf-divider{display:none}.nf-user p{display:none}.nf-kpis header p b{font-size:13px}.nf-kpi-value strong{font-size:30px}}
@media(max-width:1200px){.nf-topbar{padding:0 18px}.nf-live{display:none}.nf-sidebar{width:220px}.nf-main{margin-left:220px;padding-right:16px;padding-left:16px}.nf-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.nf-grid-main{grid-template-columns:minmax(0,1.6fr) minmax(270px,1fr)}.nf-grid-bottom{grid-template-columns:1fr}.nf-kpis header p b{font-size:14px}.nf-kpi-value strong{font-size:34px}}
@media(max-width:900px){.nf-nextframe,.nf-partner-x{display:none}.nf-search{width:160px}.nf-grid-main{grid-template-columns:1fr}.nf-main-overview :deep(.growth-flow .stages){grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.nf-main-overview :deep(.growth-flow .stage){display:flex;flex-direction:column;gap:10px}.nf-main-overview :deep(.growth-flow .stage-track){width:100%}.nf-main-overview :deep(.growth-flow .stage-shape){width:100%;min-height:62px;padding:8px}.nf-main-overview :deep(.growth-flow .stage-value){text-align:center}}
@media(max-width:820px){.nf-topbar{height:62px;padding:0 13px}.nf-sidebar{top:62px}.nf-nav-scrim{top:62px}.nf-main{margin-left:0;padding:79px 13px 25px}.nf-menu{display:block}.nf-zostream b{font-size:21px}.nf-zostream img{height:29px;width:29px}.nf-user{margin-left:12px}.nf-date{padding:8px 10px;margin-left:auto}.nf-main-overview>.nf-view-header{position:absolute}}
@media(max-width:540px){.nf-search{display:none}.nf-zostream{gap:5px}.nf-zostream b{font-size:17px}.nf-zostream img{width:24px;height:24px}.nf-date{gap:3px;padding:7px}.nf-topbar .nf-date input{width:79px;font-size:10px}.nf-date>span{display:none}.nf-user{display:flex;margin-left:8px}.nf-user>span{width:29px;height:29px;font-size:10px}.nf-menu{padding:0;margin-right:8px;font-size:19px}.nf-kpis{grid-template-columns:1fr;gap:12px}.nf-kpis>article{height:215px}.nf-main-overview :deep(.growth-flow .stages){grid-template-columns:1fr}.nf-main-overview :deep(.growth-flow .stage){display:grid;grid-template-columns:minmax(0,1fr) 90px}.nf-main-overview :deep(.growth-flow .stage-shape){width:var(--stage-width);min-height:43px}.nf-main-overview :deep(.growth-flow .stage-value){text-align:right}}

@media(max-width:400px){.nf-topbar .nf-zostream img{display:none}}
@media(max-width:350px){.nf-topbar{padding:0 10px}.nf-topbar .nf-date input{width:70px;font-size:9px}.nf-zostream b{font-size:15px}.nf-user{margin-left:5px}}
</style>
