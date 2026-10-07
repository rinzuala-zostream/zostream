<script setup>
import { computed, onMounted, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';

const today = new Date();
const dateValue = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const prior = new Date(today);
prior.setDate(prior.getDate() - 6);
const filters = ref({ from: dateValue(prior), to: dateValue(today), timezone: 'Asia/Kolkata', platform: '', app_version: '', content_type: '', user_id: '' });
const tabs = [
    { id: 'overview', label: 'Overview' }, { id: 'content', label: 'Content' },
    { id: 'insights', label: 'SDK insights' }, { id: 'sessions', label: 'Playback sessions' }, { id: 'events', label: 'Product events' },
    { id: 'errors', label: 'Playback errors' },
];
const productEventNames = [
    'app_opened', 'app_foregrounded', 'app_backgrounded', 'app_session_ended',
    'screen_viewed', 'content_impression', 'content_opened',
    'search_performed', 'search_result_selected', 'search_empty',
    'wishlist_added', 'wishlist_removed',
    'download_started', 'download_completed', 'download_failed',
    'notification_opened', 'paywall_viewed', 'plan_selected',
    'purchase_started', 'purchase_completed', 'purchase_failed',
    'purchase_cancelled', 'restore_purchase_completed',
];
const dimensions = [
    { label: 'Playback', items: [['platform', 'Platform'], ['app_version', 'App version'], ['content_type', 'Content type'], ['content_id', 'Content ID'], ['series_id', 'Series ID'], ['season_id', 'Season ID'], ['episode_id', 'Episode ID'], ['downloaded', 'Downloaded'], ['autoplay', 'Autoplay'], ['state', 'Session state'], ['end_reason', 'End reason'], ['sdk_version', 'SDK version']] },
    { label: 'Stream quality', items: [['initial_quality', 'Initial quality'], ['final_quality', 'Final quality'], ['video_codec', 'Video codec'], ['audio_codec', 'Audio codec'], ['stream_format', 'Stream format']] },
    { label: 'Audio and subtitles', items: [['audio_language', 'Audio language'], ['subtitle_enabled', 'Subtitles on'], ['subtitle_language', 'Subtitle language'], ['playback_speed', 'Playback speed']] },
    { label: 'Device and network', items: [['network_type', 'Network'], ['build_number', 'Build number'], ['os_version', 'OS version'], ['device_model', 'Device model'], ['device_category', 'Device category'], ['locale', 'Locale'], ['device_timezone', 'Device timezone']] },
];
const metricSections = [
    { label: 'Sessions and outcomes', items: [['sessions', 'Playback sessions'], ['unique_viewers', 'Unique viewers'], ['valid_views', 'Valid views'], ['completed_sessions', 'Completed'], ['pending_sessions', 'Pending checkpoints'], ['error_sessions', 'Sessions with errors'], ['playback_error_count', 'Playback errors'], ['average_completion_percent', 'Average completion', 'percent'], ['downloaded_sessions', 'Downloaded plays'], ['autoplay_sessions', 'Autoplay sessions'], ['subtitle_sessions', 'Subtitles enabled']] },
    { label: 'Time and coverage', items: [['watched_ms', 'Watch time', 'hours'], ['unique_watched_ms', 'Unique watch time', 'hours'], ['replayed_ms', 'Replay time', 'hours'], ['foreground_watch_ms', 'Foreground time', 'hours'], ['background_play_ms', 'Background time', 'hours'], ['average_duration_ms', 'Average content duration', 'ms'], ['average_watch_position_ms', 'Average final position', 'ms'], ['max_position_ms', 'Sum of farthest positions', 'hours'], ['average_startup_ms', 'Average startup', 'ms']] },
    { label: 'Playback controls', items: [['play_count', 'Play'], ['pause_count', 'Pause'], ['resume_count', 'Resume'], ['seek_count', 'Seeks'], ['seek_forward_ms', 'Time skipped forward', 'hours'], ['seek_backward_ms', 'Time skipped backward', 'hours'], ['fullscreen_count', 'Fullscreen'], ['pip_count', 'Picture in picture'], ['cast_count', 'Cast']] },
    { label: 'Buffering and quality', items: [['buffer_count', 'Buffer events'], ['buffer_ms', 'Total buffering', 'hours'], ['longest_buffer_ms', 'Longest buffer', 'ms'], ['quality_changes', 'Quality changes'], ['average_bitrate_kbps', 'Average bitrate', 'kbps'], ['dropped_frames', 'Dropped frames'], ['rendered_frames', 'Rendered frames'], ['average_playback_speed', 'Average playback speed']] },
    { label: 'Coverage milestones', items: [['milestone_25', 'Reached 25%'], ['milestone_50', 'Reached 50%'], ['milestone_75', 'Reached 75%'], ['milestone_90', 'Reached 90%']] },
];
const metrics = metricSections.flatMap((section) => section.items);
const selectedDimension = ref('platform');
const selectedMetric = ref('sessions');
const insights = ref({ summary: {}, groups: { data: [], current_page: 1, last_page: 1, total: 0 } });
const activeTab = ref('overview');
const config = ref({ enabled: false, updated_at: null });
const report = ref({ overview: {}, engagement: {}, watch_trend: [], streaming_health: [], platforms: [], top_content: [], product_events: [] });
const quality = ref([]);
const contentPage = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const sessionPage = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const eventPage = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const errorPage = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const errorGroups = ref([]);
const detailFilters = ref({ q: '', name: '', category: '', content_id: '', end_reason: '' });
const page = ref(1);
const loading = ref(false);
const configLoading = ref(true);
const saving = ref(false);
const error = ref('');
const configError = ref('');
const notice = ref('');
const overview = computed(() => report.value.overview || report.value);
const watchMax = computed(() => Math.max(0, ...report.value.watch_trend.map((row) => Number(row.watch_hours) || 0)));
const currentRows = computed(() => ({
    content: contentPage.value.data,
    sessions: sessionPage.value.data,
    events: eventPage.value.data,
    errors: errorPage.value.data,
}[activeTab.value] || []));
const currentPager = computed(() => ({
    insights: insights.value.groups,
    content: contentPage.value,
    sessions: sessionPage.value,
    events: eventPage.value,
    errors: errorPage.value,
}[activeTab.value] || { current_page: 1, last_page: 1, total: 0 }));
const number = (value, digits = 0) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: digits }).format(Number(value || 0));
const selectedMetricConfig = computed(() => metrics.find(([key]) => key === selectedMetric.value) || metrics[0]);
const largestInsight = computed(() => Math.max(0, ...(insights.value.groups?.data || []).map((row) => Number(row[selectedMetric.value]) || 0)));
const insightValue = (value, unit) => {
    if (value === null || value === undefined || value === '') return '—';
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return '—';
    const formatted = number(unit === 'hours' ? numeric / 3600000 : numeric, unit === 'hours' || unit === 'percent' ? 2 : unit === 'kbps' ? 1 : 0);
    return `${formatted}${unit === 'hours' ? ' hr' : unit === 'ms' ? ' ms' : unit === 'percent' ? '%' : unit === 'kbps' ? ' kbps' : ''}`;
};
const dimensionValue = (value) => {
    if (value === null || value === undefined || value === '' || value === 'null') return 'Unknown / not sent';
    if (value === true || value === 'true' || value === '1' || value === 1) return 'Yes';
    if (value === false || value === 'false' || value === '0' || value === 0) return 'No';
    return String(value);
};
const percent = (value) => `${number(value, 1)}%`;
const duration = (value) => `${number((Number(value) || 0) / 60000, 1)} min`;
const humanize = (value) => String(value || '—').replaceAll('_', ' ');
const contentTitle = (row) => row?.display_title
    || (row?.parent_title && row?.title ? `${row.parent_title} — ${row.title}` : row?.title)
    || row?.content_id
    || 'Unknown content';
const contentMeta = (row) => {
    const parts = [humanize(row?.content_type)];
    if (row?.content_id && contentTitle(row) !== String(row.content_id)) parts.push(row.content_id);
    return parts.join(' · ');
};
const detailQuery = computed(() => ({ ...filters.value, ...detailFilters.value, page: page.value, per_page: 25 }));

async function loadConfig() {
    configLoading.value = true;
    configError.value = '';
    try { config.value = await api('/admin/analytics/config', { cache: 'no-store' }); }
    catch (reason) { configError.value = reason.message; }
    finally { configLoading.value = false; }
}

async function loadOverview() {
    const query = queryString({ ...filters.value });
    const [summary, qualityRows] = await Promise.all([
        api(`/analytic/reports/overview${query}`, { cache: 'no-store' }),
        api(`/analytic/reports/quality${query}`, { cache: 'no-store' }),
    ]);
    report.value = summary;
    quality.value = qualityRows;
}

async function loadDetails() {
    const query = queryString(detailQuery.value);
    if (activeTab.value === 'insights') insights.value = await api(`/analytic/reports/insights${queryString({ ...filters.value, dimension: selectedDimension.value, page: page.value, per_page: 25 })}`, { cache: 'no-store' });
    if (activeTab.value === 'content') contentPage.value = await api(`/analytic/reports/content${query}`, { cache: 'no-store' });
    if (activeTab.value === 'sessions') sessionPage.value = await api(`/analytic/reports/sessions${query}`, { cache: 'no-store' });
    if (activeTab.value === 'events') eventPage.value = await api(`/analytic/reports/events${query}`, { cache: 'no-store' });
    if (activeTab.value === 'errors') {
        const [groups, rows] = await Promise.all([
            api(`/analytic/reports/errors${query}`, { cache: 'no-store' }),
            api(`/analytic/reports/errors/events${query}`, { cache: 'no-store' }),
        ]);
        errorGroups.value = groups;
        errorPage.value = rows;
    }
}

async function loadAll() {
    loading.value = true;
    error.value = '';
    page.value = 1;
    try {
        await loadOverview();
        if (activeTab.value !== 'overview') await loadDetails();
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}

async function selectTab(tab) {
    activeTab.value = tab;
    page.value = 1;
    if (tab === 'overview') return;
    loading.value = true;
    error.value = '';
    try { await loadDetails(); }
    catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}

async function changePage(nextPage) {
    page.value = nextPage;
    loading.value = true;
    error.value = '';
    try { await loadDetails(); }
    catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}

async function changeDimension() {
    page.value = 1;
    loading.value = true;
    error.value = '';
    try { await loadDetails(); }
    catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}

async function setEnabled(enabled) {
    saving.value = true;
    configError.value = '';
    notice.value = '';
    try {
        config.value = await api('/admin/analytics/config', { method: 'PUT', body: { enabled } });
        notice.value = enabled ? 'Analytics collection is on. Connected apps receive the change live.' : 'Analytics collection is off. New uploads are blocked.';
    } catch (reason) { configError.value = reason.message; }
    finally { saving.value = false; }
}

function exportCurrentPage() {
    const file = new Blob([JSON.stringify(currentRows.value, null, 2)], { type: 'application/json' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(file);
    link.download = `analytics-${activeTab.value}-${filters.value.from}-${filters.value.to}-page-${page.value}.json`;
    link.click();
    URL.revokeObjectURL(link.href);
}

function scale(value, max) { return `${max > 0 ? Math.max(2, Math.min(100, (Number(value || 0) / max) * 100)) : 2}%`; }

onMounted(() => { void loadConfig(); void loadAll(); });
</script>

<template>
    <div class="admin-page analytics-page">
        <PageHeader eyebrow="Audience and playback" title="Analytics" description="Explore every playback, quality, product-event and error signal collected by ZoAnalytics.">
            <button class="admin-secondary" type="button" :disabled="loading || configLoading" @click="loadConfig(); loadAll()">Refresh</button>
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
                <button class="analytics-toggle" type="button" role="switch" :aria-checked="config.enabled" :aria-label="config.enabled ? 'Turn analytics off' : 'Turn analytics on'" :disabled="configLoading || saving || !!configError" :class="{ 'is-on': config.enabled }" @click="setEnabled(!config.enabled)"><span /></button>
            </div>
        </section>

        <form class="analytics-filters" @submit.prevent="loadAll">
            <label>From<input v-model="filters.from" type="date" required></label>
            <label>To<input v-model="filters.to" type="date" required></label>
            <label>Timezone<select v-model="filters.timezone"><option value="Asia/Kolkata">India · Kolkata</option><option value="UTC">UTC</option><option value="America/Los_Angeles">Los Angeles</option><option value="America/New_York">New York</option><option value="Europe/London">London</option><option value="Asia/Tokyo">Tokyo</option></select></label>
            <label>Platform<select v-model="filters.platform"><option value="">All platforms</option><option value="ios">iOS</option><option value="tvos">Apple TV</option><option value="android">Android</option><option value="tv">Android TV</option></select></label>
            <label>App version<input v-model="filters.app_version" placeholder="All versions"></label>
            <label>Content type<select v-model="filters.content_type"><option value="">All content</option><option value="movie">Movie</option><option value="episode">Episode</option><option value="live">Live</option></select></label>
            <label>User ID<input v-model="filters.user_id" placeholder="All users"></label>
            <button class="admin-primary" type="submit" :disabled="loading">{{ loading ? 'Loading…' : 'Apply filters' }}</button>
        </form>

        <nav class="analytics-tabs" aria-label="Analytics report sections">
            <button v-for="tab in tabs" :key="tab.id" type="button" :class="{ active: activeTab === tab.id }" @click="selectTab(tab.id)">{{ tab.label }}</button>
        </nav>

        <div v-if="loading" class="admin-loading">Loading analytics…</div>
        <template v-else-if="!error && activeTab === 'overview'">
            <section class="analytics-metrics">
                <article><span>Playback starts</span><b>{{ number(overview.playback_starts) }}</b></article>
                <article><span>Qualified views</span><b>{{ number(overview.valid_views) }}</b></article>
                <article><span>Unique viewers</span><b>{{ number(overview.unique_viewers) }}</b></article>
                <article><span>Total watch time</span><b>{{ number(overview.watch_hours, 1) }}<small> hr</small></b></article>
                <article><span>Average watch</span><b>{{ number(overview.average_watch_minutes, 1) }}<small> min</small></b></article>
                <article><span>Completion rate</span><b>{{ percent(overview.completion_rate) }}</b></article>
                <article><span>Playback error rate</span><b>{{ percent(overview.playback_error_rate) }}</b></article>
                <article><span>Average startup</span><b>{{ number(overview.average_startup_ms) }}<small> ms</small></b></article>
                <article><span>Rebuffer ratio</span><b>{{ percent((overview.rebuffer_ratio || 0) * 100) }}</b></article>
                <article><span>App sessions</span><b>{{ number(overview.app_sessions) }}</b></article>
            </section>

            <section class="analytics-columns">
                <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">AUDIENCE</span><h2>Watch trend</h2><p>Daily starts, viewers and watched hours.</p></div></header>
                    <div v-if="!report.watch_trend?.length" class="admin-empty">No playback data in this range.</div>
                    <div v-else class="analytics-trend"><div v-for="point in report.watch_trend" :key="point.date"><span>{{ point.date }}</span><i><b :style="{ width: scale(point.watch_hours, watchMax) }" /></i><strong>{{ number(point.watch_hours, 1) }} hr</strong><small>{{ number(point.playback_starts) }} starts · {{ number(point.unique_viewers) }} viewers</small></div></div>
                </article>
                <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">STREAMING HEALTH</span><h2>Quality by day</h2><p>Startup, buffering and successful sessions.</p></div></header>
                    <div v-if="!report.streaming_health?.length" class="admin-empty">No quality data in this range.</div>
                    <div v-else class="analytics-trend"><div v-for="point in report.streaming_health" :key="point.date"><span>{{ point.date }}</span><strong>{{ number(point.average_startup_ms) }} ms</strong><small>Rebuffer {{ number(point.rebuffer_rate, 2) }}% · Success {{ percent(point.playback_success_rate) }}</small></div></div>
                </article>
            </section>

            <section class="analytics-metrics analytics-detail-metrics">
                <article><span>Unique watch time</span><b>{{ number(report.engagement?.unique_watch_hours, 1) }}<small> hr</small></b></article>
                <article><span>Replay time</span><b>{{ number(report.engagement?.replayed_hours, 1) }}<small> hr</small></b></article>
                <article><span>Foreground play</span><b>{{ number(report.engagement?.foreground_watch_hours, 1) }}<small> hr</small></b></article>
                <article><span>Background play</span><b>{{ number(report.engagement?.background_play_hours, 1) }}<small> hr</small></b></article>
                <article><span>Play / pause / resume</span><b>{{ number(report.engagement?.play_count) }} / {{ number(report.engagement?.pause_count) }} / {{ number(report.engagement?.resume_count) }}</b></article>
                <article><span>Seeks · forward/back</span><b>{{ number(report.engagement?.seek_count) }} · {{ number(report.engagement?.seek_forward_hours, 2) }} / {{ number(report.engagement?.seek_backward_hours, 2) }} hr</b></article>
                <article><span>Fullscreen · PiP · Cast</span><b>{{ number(report.engagement?.fullscreen_count) }} · {{ number(report.engagement?.pip_count) }} · {{ number(report.engagement?.cast_count) }}</b></article>
                <article><span>Bitrate · playback speed</span><b>{{ number(report.engagement?.average_bitrate_kbps) }} kbps · {{ number(report.engagement?.average_playback_speed, 2) }}×</b></article>
                <article><span>Dropped / rendered frames</span><b>{{ number(report.engagement?.dropped_frames) }} / {{ number(report.engagement?.rendered_frames) }}</b></article>
                <article><span>Subtitle sessions · longest buffer</span><b>{{ number(report.engagement?.subtitle_sessions) }} · {{ number(report.engagement?.longest_buffer_seconds, 1) }} sec</b></article>
            </section>

            <section class="analytics-columns">
                <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">TOP CONTENT</span><h2>Most watched</h2></div><button class="admin-secondary" type="button" @click="selectTab('content')">All content</button></header>
                    <div v-if="!report.top_content?.length" class="admin-empty">No content analytics yet.</div><div v-else class="analytics-list"><div v-for="item in report.top_content" :key="`${item.content_type}:${item.content_id}`"><span><b>{{ contentTitle(item) }}</b><small>{{ contentMeta(item) }}</small></span><strong>{{ number(item.views) }} views · {{ number(item.watch_hours, 1) }} hr</strong></div></div>
                </article>
                <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">PRODUCT BEHAVIOUR</span><h2>App events</h2></div><button class="admin-secondary" type="button" @click="selectTab('events')">All events</button></header>
                    <div v-if="!report.product_events?.length" class="admin-empty">No product events yet.</div><div v-else class="analytics-list"><div v-for="item in report.product_events" :key="item.name"><span><b>{{ humanize(item.name) }}</b><small>{{ number(item.unique_users) }} unique users</small></span><strong>{{ number(item.count) }}</strong></div></div>
                </article>
            </section>
            <section class="analytics-columns">
                <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">PLATFORM</span><h2>Sessions and viewers</h2></div></header><div v-if="!report.platforms?.length" class="admin-empty">No platform data yet.</div><div v-else class="analytics-list"><div v-for="item in report.platforms" :key="item.platform"><span><b>{{ item.platform.toUpperCase() }}</b><small>{{ number(item.viewers) }} unique viewers</small></span><strong>{{ number(item.sessions) }} · {{ percent(item.percentage) }}</strong></div></div></article>
                <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">QUALITY BY APP</span><h2>App versions and devices</h2></div></header><div v-if="!quality.length" class="admin-empty">No app quality data yet.</div><div v-else class="analytics-table-wrap"><table><thead><tr><th>Platform</th><th>Version</th><th>Sessions</th><th>Startup</th><th>Rebuffer</th><th>Failure</th></tr></thead><tbody><tr v-for="row in quality" :key="`${row.platform}:${row.app_version}`"><td>{{ row.platform }}</td><td>{{ row.app_version }}</td><td>{{ number(row.sessions) }}</td><td>{{ number(row.average_startup_ms) }} ms</td><td>{{ number(row.rebuffer_ratio * 100, 2) }}%</td><td>{{ percent(row.failure_rate) }}</td></tr></tbody></table></div></article>
            </section>
        </template>

        <section v-else-if="!error && activeTab === 'insights'" class="analytics-insights">
            <header class="admin-panel analytics-panel"><div><span class="analytics-eyebrow">SDK V1 MEASUREMENTS</span><h2>Explore every playback signal</h2><p>Compare a metric across one attribute. The cards below show totals for the selected filters; the Sessions tab shows each original SDK payload.</p></div></header>
            <div class="admin-panel analytics-panel">
                <div class="analytics-insight-controls">
                    <label>Group sessions by<select v-model="selectedDimension" @change="changeDimension"><optgroup v-for="group in dimensions" :key="group.label" :label="group.label"><option v-for="[key, label] in group.items" :key="key" :value="key">{{ label }}</option></optgroup></select></label>
                    <label>Compare metric<select v-model="selectedMetric"><optgroup v-for="section in metricSections" :key="section.label" :label="section.label"><option v-for="[key, label] in section.items" :key="key" :value="key">{{ label }}</option></optgroup></select></label>
                </div>
                <div v-if="!insights.groups?.data?.length" class="admin-empty">No playback sessions match these filters. Try a wider date range or another filter.</div>
                <div v-else class="analytics-insight-groups">
                    <div v-for="row in insights.groups.data" :key="String(row.dimension_value)" class="analytics-insight-row">
                        <div><strong>{{ dimensionValue(row.dimension_label ?? row.dimension_value) }}</strong><b>{{ insightValue(row[selectedMetric], selectedMetricConfig[2]) }}</b></div>
                        <span class="analytics-insight-track"><i :style="{ width: largestInsight > 0 ? `${Math.max(0, Math.min(100, (Number(row[selectedMetric]) || 0) / largestInsight * 100))}%` : '0%' }" /></span>
                        <small>{{ number(row.sessions) }} sessions</small>
                    </div>
                </div>
            </div>
            <div class="analytics-insight-summary">
                <details v-for="(section, index) in metricSections" :key="section.label" class="admin-panel analytics-panel" :open="index === 0 ? true : undefined">
                    <summary>{{ section.label }} <small>{{ section.items.length }} metrics</small></summary>
                    <div class="analytics-insight-cards"><article v-for="[key, label, unit] in section.items" :key="key"><span>{{ label }}</span><b>{{ insightValue(insights.summary?.[key], unit) }}</b></article></div>
                </details>
            </div>
        </section>

        <section v-else-if="!error && activeTab === 'content'" class="admin-panel analytics-panel">
            <header><div><span class="analytics-eyebrow">CONTENT PERFORMANCE</span><h2>Every title and episode</h2><p>{{ number(contentPage.total) }} content items in the selected range.</p></div></header>
            <div class="analytics-table-wrap"><table><thead><tr><th>Content</th><th>Type</th><th>Starts</th><th>Valid views</th><th>Viewers</th><th>Watch hours</th><th>Completion</th></tr></thead><tbody><tr v-for="row in contentPage.data" :key="`${row.content_type}:${row.content_id}`"><td><b>{{ contentTitle(row) }}</b><small>{{ row.content_id }}</small></td><td>{{ row.content_type }}</td><td>{{ number(row.playback_starts) }}</td><td>{{ number(row.valid_views) }}</td><td>{{ number(row.unique_viewers) }}</td><td>{{ number(row.watch_hours, 1) }}</td><td>{{ percent(row.completion_rate) }}</td></tr></tbody></table><div v-if="!contentPage.data?.length" class="admin-empty">No content rows for these filters.</div></div>
        </section>

        <section v-else-if="!error && activeTab === 'sessions'" class="admin-panel analytics-panel">
            <header><div><span class="analytics-eyebrow">PLAYBACK DATA</span><h2>Session explorer</h2><p>{{ number(sessionPage.total) }} sessions · open a row to inspect the complete SDK payload.</p></div><button v-if="sessionPage.data?.length" class="admin-secondary" type="button" @click="exportCurrentPage">Export page JSON</button></header>
            <form class="detail-filter-row" @submit.prevent="page = 1; loadDetails()"><label>Find session/content/user<input v-model="detailFilters.q" placeholder="ID or user"></label><label>Content ID<input v-model="detailFilters.content_id" placeholder="Any content"></label><label>End reason<input v-model="detailFilters.end_reason" placeholder="Any reason"></label><button class="admin-secondary" type="submit">Search</button></form>
            <div class="analytics-table-wrap"><table><thead><tr><th>Started</th><th>Content</th><th>User</th><th>Platform / app</th><th>Watch</th><th>Completion</th><th>Result</th><th>Full SDK data</th></tr></thead><tbody><tr v-for="row in sessionPage.data" :key="row.session_id"><td>{{ row.started_at }}</td><td><b>{{ contentTitle(row) }}</b><small>{{ contentMeta(row) }}</small></td><td>{{ row.user_id }}</td><td>{{ row.platform }}<small>{{ row.app_version }} · SDK {{ row.sdk_version || 'unknown' }}</small></td><td>{{ duration(row.watched_ms) }}<small>{{ duration(row.unique_watched_ms) }} unique</small></td><td>{{ percent(row.completion_percent) }}</td><td>{{ row.completed ? 'Completed' : humanize(row.end_reason) }}<small>{{ number(row.error_count) }} errors · {{ number(row.buffer_count) }} buffers</small></td><td><details><summary>Inspect</summary><pre>{{ JSON.stringify(row, null, 2) }}</pre></details></td></tr></tbody></table><div v-if="!sessionPage.data?.length" class="admin-empty">No playback sessions for these filters.</div></div>
        </section>

        <section v-else-if="!error && activeTab === 'events'" class="admin-panel analytics-panel">
            <header><div><span class="analytics-eyebrow">PRODUCT EVENTS</span><h2>App event stream</h2><p>{{ number(eventPage.total) }} events · sanitized event properties from the SDK.</p></div><button v-if="eventPage.data?.length" class="admin-secondary" type="button" @click="exportCurrentPage">Export page JSON</button></header>
            <form class="detail-filter-row" @submit.prevent="page = 1; loadDetails()"><label>Event name<select v-model="detailFilters.name"><option value="">All event types</option><option v-for="eventName in productEventNames" :key="eventName" :value="eventName">{{ humanize(eventName) }}</option></select></label><label>App version<input v-model="filters.app_version" placeholder="All versions"></label><button class="admin-secondary" type="submit">Filter events</button></form>
            <div class="analytics-table-wrap"><table><thead><tr><th>Time</th><th>Event</th><th>Platform</th><th>App</th><th>User</th><th>App session</th><th>Full event</th></tr></thead><tbody><tr v-for="row in eventPage.data" :key="row.event_id"><td>{{ row.occurred_at }}</td><td>{{ humanize(row.name) }}</td><td>{{ row.platform }}</td><td>{{ row.app_version }}</td><td>{{ row.user_id }}</td><td>{{ row.app_session_id }}</td><td><details><summary>Inspect</summary><pre>{{ JSON.stringify(row, null, 2) }}</pre></details></td></tr></tbody></table><div v-if="!eventPage.data?.length" class="admin-empty">No product events for these filters.</div></div>
        </section>

        <section v-else-if="!error && activeTab === 'errors'" class="analytics-columns analytics-error-layout">
            <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">ERROR GROUPS</span><h2>Most common failures</h2></div></header><div class="analytics-table-wrap"><table><thead><tr><th>Category / stage</th><th>Code</th><th>Occurrences</th><th>Affected sessions</th><th>Fatal</th><th>Last seen</th></tr></thead><tbody><tr v-for="row in errorGroups" :key="`${row.category}:${row.stage}:${row.code}`"><td>{{ row.category }}<small>{{ row.stage }}</small></td><td>{{ row.code }}</td><td>{{ number(row.occurrences) }}</td><td>{{ number(row.affected_sessions) }}</td><td>{{ number(row.fatal_count) }}</td><td>{{ row.last_seen_at }}</td></tr></tbody></table></div></article>
            <article class="admin-panel analytics-panel"><header><div><span class="analytics-eyebrow">ERROR EVENTS</span><h2>Individual playback errors</h2><p>{{ number(errorPage.total) }} error events.</p></div><button v-if="errorPage.data?.length" class="admin-secondary" type="button" @click="exportCurrentPage">Export page JSON</button></header>
                <form class="detail-filter-row" @submit.prevent="page = 1; loadDetails()"><label>Category<input v-model="detailFilters.category" placeholder="All categories"></label><label>App version<input v-model="filters.app_version" placeholder="All versions"></label><button class="admin-secondary" type="submit">Filter errors</button></form>
                <div class="analytics-table-wrap"><table><thead><tr><th>Time / session</th><th>Category</th><th>Code</th><th>Fatal / retry</th><th>Position</th><th>Message</th><th>Full error</th></tr></thead><tbody><tr v-for="row in errorPage.data" :key="row.event_id"><td>{{ row.occurred_at }}<small>{{ row.session_id }}</small></td><td>{{ row.category }}<small>{{ row.stage }}</small></td><td>{{ row.code }}<small>HTTP {{ row.http_status || '—' }}</small></td><td>{{ row.is_fatal ? 'Fatal' : 'Non-fatal' }}<small>{{ row.is_retryable ? 'Retryable' : 'No retry' }} · {{ row.retry_count }} retries</small></td><td>{{ duration(row.position_ms) }}</td><td>{{ row.sanitized_message || '—' }}<small>{{ row.network_type }}</small></td><td><details><summary>Inspect</summary><pre>{{ JSON.stringify(row, null, 2) }}</pre></details></td></tr></tbody></table><div v-if="!errorPage.data?.length" class="admin-empty">No playback errors for these filters.</div></div>
            </article>
        </section>

        <footer v-if="activeTab !== 'overview' && !loading && currentPager.last_page > 1" class="analytics-pagination"><span>Page {{ currentPager.current_page }} of {{ currentPager.last_page }} · {{ number(currentPager.total) }} results</span><div><button class="admin-secondary" type="button" :disabled="currentPager.current_page <= 1" @click="changePage(currentPager.current_page - 1)">Previous</button><button class="admin-secondary" type="button" :disabled="currentPager.current_page >= currentPager.last_page" @click="changePage(currentPager.current_page + 1)">Next</button></div></footer>
    </div>
</template>

<style scoped>
.analytics-control{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:14px 0;padding:18px 20px}.analytics-control h2,.admin-panel h2{margin:5px 0 3px;font:750 18px 'Manrope',sans-serif}.analytics-control p,.analytics-panel header p{margin:0;color:var(--a-muted);font-size:11px}.analytics-control code{padding:2px 5px;border-radius:5px;background:var(--a-soft);color:var(--a-text)}.analytics-control small{display:block;margin-top:7px;color:var(--a-muted)}.analytics-eyebrow{color:var(--a-cyan);font-size:9px;font-weight:800;letter-spacing:.15em}.analytics-toggle-wrap{display:flex;align-items:center;gap:12px}.analytics-state{min-width:40px;font-size:12px;font-weight:800}.analytics-state.is-on{color:#059669}.analytics-state.is-off{color:var(--a-muted)}.analytics-toggle{width:54px;height:30px;padding:3px;border:0;border-radius:99px;background:#64748b;cursor:pointer;transition:background .2s}.analytics-toggle.is-on{background:#10b981}.analytics-toggle:disabled{cursor:not-allowed;opacity:.55}.analytics-toggle span{display:block;width:24px;height:24px;border-radius:50%;background:#fff;transition:transform .2s}.analytics-toggle.is-on span{transform:translateX(24px)}
.analytics-filters,.detail-filter-row{display:flex;align-items:end;flex-wrap:wrap;gap:9px;margin:12px 0;padding:13px;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel)}.analytics-filters label,.detail-filter-row label{display:grid;gap:5px;color:var(--a-muted);font-size:10px;font-weight:700}.analytics-filters input,.analytics-filters select,.detail-filter-row input{height:36px;min-width:125px;padding:0 10px;border:1px solid var(--a-line);border-radius:9px;background:var(--a-bg);color:var(--a-text);font:inherit}.analytics-filters label:nth-child(3) select{min-width:155px}.analytics-filters .admin-primary,.detail-filter-row .admin-secondary{height:36px;padding-inline:14px}.analytics-tabs{display:flex;gap:5px;overflow-x:auto;margin:12px 0;padding:4px;border:1px solid var(--a-line);border-radius:12px;background:var(--a-panel)}.analytics-tabs button{flex:none;padding:9px 13px;border:0;border-radius:8px;background:transparent;color:var(--a-muted);font-size:11px;font-weight:750;cursor:pointer}.analytics-tabs button.active{background:var(--a-cyan);color:#06202a}.analytics-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px;margin:12px 0}.analytics-metrics article{min-width:0;padding:13px;border:1px solid var(--a-line);border-radius:13px;background:var(--a-panel)}.analytics-metrics span{display:block;color:var(--a-muted);font-size:9px}.analytics-metrics b{display:block;margin-top:7px;color:var(--a-cyan);font:750 19px 'Manrope',sans-serif;overflow-wrap:anywhere}.analytics-metrics small{font-size:10px}.analytics-detail-metrics{grid-template-columns:repeat(5,minmax(0,1fr))}.analytics-detail-metrics b{font-size:15px}.analytics-columns{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0}.analytics-panel{min-width:0;padding:15px}.analytics-panel header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.analytics-list>div{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--a-line)}.analytics-list span{min-width:0}.analytics-list b,.analytics-list small{display:block}.analytics-list b{overflow:hidden;text-overflow:ellipsis;text-transform:capitalize}.analytics-list small,.analytics-trend small,.analytics-table-wrap td small{display:block;margin-top:3px;color:var(--a-muted);font-size:9px}.analytics-list strong{flex:none;font-size:10px}.analytics-trend>div{display:grid;grid-template-columns:76px minmax(55px,1fr) auto;align-items:center;gap:9px;padding:8px 0;border-top:1px solid var(--a-line);font-size:10px}.analytics-trend>div>span{color:var(--a-muted)}.analytics-trend i{height:7px;border-radius:9px;background:var(--a-soft);overflow:hidden}.analytics-trend i b{display:block;height:100%;border-radius:9px;background:linear-gradient(90deg,#22d3ee,#6366f1)}.analytics-trend strong{font-size:10px}.analytics-trend small{grid-column:2/4;margin:0}.analytics-table-wrap{max-width:100%;overflow:auto}.analytics-table-wrap table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:10px}.analytics-table-wrap th,.analytics-table-wrap td{padding:9px 10px;border-bottom:1px solid var(--a-line);text-align:left;vertical-align:top}.analytics-table-wrap th{color:var(--a-muted);font-size:9px;text-transform:uppercase;letter-spacing:.08em}.analytics-table-wrap details{max-width:240px}.analytics-table-wrap summary{color:var(--a-cyan);cursor:pointer}.analytics-table-wrap pre{max-height:300px;max-width:420px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:9px}.analytics-error-layout{grid-template-columns:1fr}.analytics-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 2px;color:var(--a-muted);font-size:11px}.analytics-pagination>div{display:flex;gap:7px}.admin-empty{padding:16px;color:var(--a-muted);font-size:11px}@media(max-width:1000px){.analytics-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.analytics-detail-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.analytics-columns{grid-template-columns:1fr}.analytics-metrics,.analytics-detail-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.analytics-control{align-items:flex-start;flex-direction:column}.analytics-filters>*{flex:1 1 140px}.analytics-pagination{align-items:flex-start;flex-direction:column}}
.detail-filter-row select{height:36px;min-width:160px;padding:0 10px;border:1px solid var(--a-line);border-radius:9px;background:var(--a-bg);color:var(--a-text);font:inherit}
.analytics-insights{display:grid;gap:12px}.analytics-insight-controls{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:15px}.analytics-insight-controls label{display:grid;gap:6px;min-width:200px;color:var(--a-muted);font-size:11px;font-weight:700}.analytics-insight-controls select{height:38px;max-width:100%;padding:0 10px;border:1px solid var(--a-line);border-radius:9px;background:var(--a-bg);color:var(--a-text);font:inherit}.analytics-insight-groups{display:grid;gap:12px}.analytics-insight-row>div{display:flex;justify-content:space-between;gap:12px;font-size:11px}.analytics-insight-row strong{overflow-wrap:anywhere}.analytics-insight-row b{flex:none}.analytics-insight-row small{display:block;margin-top:4px;color:var(--a-muted)}.analytics-insight-track{display:block;height:8px;margin-top:6px;border-radius:9px;background:var(--a-soft);overflow:hidden}.analytics-insight-track i{display:block;height:100%;border-radius:9px;background:linear-gradient(90deg,#22d3ee,#6366f1)}.analytics-insight-summary{display:grid;gap:9px}.analytics-insight-summary summary{cursor:pointer;font-size:13px;font-weight:800}.analytics-insight-summary summary small{float:right;font-size:10px;font-weight:500}.analytics-insight-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-top:14px}.analytics-insight-cards article{min-width:0;padding:12px;border-radius:10px;background:var(--a-soft)}.analytics-insight-cards span{display:block;color:var(--a-muted);font-size:10px}.analytics-insight-cards b{display:block;margin-top:5px;overflow-wrap:anywhere;font-size:16px}@media(max-width:1000px){.analytics-insight-cards{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.analytics-insight-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.analytics-insight-controls label{flex:1 1 200px;min-width:0}}
</style>
