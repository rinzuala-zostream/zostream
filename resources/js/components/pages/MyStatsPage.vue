<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import PublicLoginCard from '../PublicLoginCard.vue';
import { authenticatedFetch, clearPublicSession, getPublicSession } from '../../lib/publicAuth';
import { watchTime, dataUsage, dateLabel, money, statsQuery, watchLink } from '../../lib/customerStats';

const session = ref(getPublicSession());
const access = ref(null);
const tab = ref('overview');
const signingOut = ref(false);
const loginError = ref('');
const range = reactive({ period: 'month', from: '', to: '' });
const draft = reactive({ period: 'month', from: '', to: '' });
const rangeError = ref('');
const sections = reactive(Object.fromEntries(['overview', 'watching', 'account', 'payments'].map(key =>
    [key, { data: null, loading: false, error: '', stale: true, generation: 0 }])));
const controllers = {};
const search = ref('');
const type = ref('all');
const sort = ref('latest');
const watchingPage = ref(1);
const paymentPage = ref(1);
const paymentStatus = ref('all');
const paymentDevice = ref('all');
const downloading = ref('');
const downloadError = ref('');
const selectedDay = ref(null);
let searchTimer;
let disposed = false;

const profile = computed(() => sections.overview.data?.profile || {});
const stats = computed(() => sections.overview.data?.stats);
const account = computed(() => sections.account.data);
const activePlans = computed(() => (account.value?.subscriptions || []).filter(plan => plan.status === 'active'));
const otherPlans = computed(() => (account.value?.subscriptions || []).filter(plan => plan.status !== 'active'));
const activity = computed(() => stats.value?.activity || []);
const activityMax = computed(() => Math.max(60, ...activity.value.map(day => day.seconds_watched)));
const today = new Date().toLocaleDateString('en-CA');
const ranges = [{ value: 'today', label: 'Today' }, { value: 'week', label: 'Last 7 days' },
    { value: 'month', label: 'This month' }, { value: 'last_month', label: 'Last month' }, { value: 'custom', label: 'Choose dates' }];

function invalidateRequests() {
    for (const name of Object.keys(sections)) {
        controllers[name]?.abort();
        sections[name].generation++;
        sections[name].loading = false;
    }
}
function expired() {
    invalidateRequests();
    access.value = false;
    session.value = null;
    clearPublicSession();
    for (const state of Object.values(sections)) { state.data = null; state.stale = true; }
    loginError.value = 'Please sign in again to view your account.';
}
function request(path, options = {}) {
    const prefix = session.value?.access_token ? '/api/v4/account/stats/' : '/account/stats/';
    return session.value?.access_token
        ? authenticatedFetch(prefix + path, options)
        : fetch(prefix + path, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...options.headers } });
}
function extras(name) {
    if (name === 'watching') return { q: search.value.trim(), type: type.value, sort: sort.value, page: watchingPage.value };
    if (name === 'payments') return { status: paymentStatus.value, device: paymentDevice.value, page: paymentPage.value };
    return {};
}
async function responseError(response) {
    const payload = await response.json().catch(() => null);
    const details = payload?.error?.details || payload?.errors;
    return Object.values(details || {}).flat()[0] || payload?.message || 'Couldn’t load this section. Please try again.';
}
async function loadSection(name) {
    const state = sections[name];
    controllers[name]?.abort();
    const controller = new AbortController();
    controllers[name] = controller;
    const generation = ++state.generation;
    state.loading = true;
    state.error = '';
    try {
        const query = name === 'account' ? '' : statsQuery(range, extras(name));
        const response = await request(`${name}${query ? `?${query}` : ''}`, { signal: controller.signal });
        if (disposed || generation !== state.generation) return;
        if (response.status === 401) { expired(); return; }
        if (!response.ok) throw new Error(await responseError(response));
        const payload = await response.json();
        if (disposed || generation !== state.generation) return;
        state.data = payload.success !== undefined ? payload.data : payload;
        state.stale = false;
        access.value = true;
        if (name === 'watching') watchingPage.value = state.data.content_pagination.page;
        if (name === 'payments') paymentPage.value = state.data.pagination.page;
    } catch (error) {
        if (!disposed && generation === state.generation && error.name !== 'AbortError') state.error = error.message;
    } finally {
        if (generation === state.generation) state.loading = false;
    }
}
function showTab(value) {
    tab.value = value;
    const name = value === 'account' ? 'payments' : value;
    if (sections[name].stale && !sections[name].loading) loadSection(name);
    if (value === 'account' && sections.account.stale && !sections.account.loading) loadSection('account');
}
function applyRange() {
    rangeError.value = '';
    if (draft.period === 'custom' && (!draft.from || !draft.to || draft.from > draft.to || draft.to > today)) {
        rangeError.value = 'Choose a start and end date, ending today or earlier.';
        return;
    }
    Object.assign(range, draft);
    watchingPage.value = 1;
    paymentPage.value = 1;
    selectedDay.value = null;
    for (const name of ['overview', 'watching', 'payments']) sections[name].stale = true;
    loadSection(tab.value === 'account' ? 'payments' : tab.value);
}
function filterWatching(debounce = false) {
    clearTimeout(searchTimer);
    const run = () => { watchingPage.value = 1; sections.watching.stale = true; loadSection('watching'); };
    if (debounce) searchTimer = setTimeout(run, 350); else run();
}
function filterPayments() { paymentPage.value = 1; loadSection('payments'); }
function periodLabel(period) { return period ? period.from === period.to ? dateLabel(period.from) : `${dateLabel(period.from)} – ${dateLabel(period.to)}` : ''; }
function titleOf(item) { return item.movie?.title || item.title || 'Zo Stream title'; }
function posterOf(item) { return item.episode?.thumbnail || item.movie?.poster || item.movie?.cover_img || item.poster; }
function progress(item) { return item.duration > 0 ? Math.min(100, Math.round(item.position / item.duration * 100)) : null; }
function label(value) { return ({ tv: 'TV', upi: 'UPI', renew: 'Renewal', new: 'New subscription' })[value] || (value ? value.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase()) : 'Not recorded'); }

async function download(payment = null) {
    if (downloading.value) return;
    const key = payment ? String(payment.id) : 'csv';
    downloading.value = key; downloadError.value = '';
    try {
        const path = payment ? `payments/${payment.id}/receipt` : `payments/export?${statsQuery(range, { status: paymentStatus.value, device: paymentDevice.value })}`;
        const response = await request(path, { headers: { Accept: 'application/json' } });
        if (response.status === 401) { expired(); return; }
        if (!response.ok) throw new Error(await responseError(response));
        const blob = await response.blob();
        if (disposed || access.value === false) return;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = payment ? `${payment.receipt_number}.pdf` : `zo-stream-payments.csv`;
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (error) { downloadError.value = error.message || 'Download failed. Please try again.'; }
    finally { downloading.value = ''; }
}
async function authenticated(value) {
    session.value = value; access.value = null; loginError.value = '';
    await Promise.all([loadSection('overview'), loadSection('account')]);
}
async function logout() {
    signingOut.value = true;
    invalidateRequests();
    try {
        if (session.value?.access_token) await authenticatedFetch('/api/v4/auth/logout', { method: 'POST' });
        await fetch('/account/stats/logout', { method: 'POST', credentials: 'same-origin', headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json',
        } });
    } catch { /* Local account details are cleared even if the connection is unavailable. */ }
    finally { expired(); loginError.value = ''; signingOut.value = false; }
}
onMounted(() => {
    document.title = 'My Zo Stream — Zo Stream';
    const query = new URLSearchParams(window.location.search);
    if (query.get('bridge') === 'expired') loginError.value = 'Your app sign-in link expired. Open My Zo Stream again from the app.';
    if (query.get('app') === '1') { clearPublicSession(); session.value = null; window.history.replaceState({}, '', '/account/stats'); }
    window.addEventListener('zostream:public-session-expired', expired);
    Promise.all([loadSection('overview'), loadSection('account')]);
});
onUnmounted(() => { disposed = true; invalidateRequests(); clearTimeout(searchTimer); window.removeEventListener('zostream:public-session-expired', expired); });
</script>

<template>
    <main class="my-stats-page">
        <div v-if="access === false" class="login-wrap">
            <a class="back-link" href="/">← Zo Stream home</a>
            <p v-if="loginError" class="notice" role="alert">{{ loginError }}</p>
            <PublicLoginCard @authenticated="authenticated" />
        </div>
        <div v-else class="dashboard">
            <header class="page-header">
                <div><a class="back-link" href="/">← Zo Stream</a><p class="eyebrow">YOUR WATCHING, AT A GLANCE</p><h1>My <span>Zo Stream</span></h1>
                    <p class="greeting">{{ profile.name ? `Hello, ${profile.name}` : 'Your watching and account, in one place.' }} <small v-if="profile.username">@{{ profile.username }}</small></p>
                </div>
                <button class="quiet-button" :disabled="signingOut" @click="logout">{{ signingOut ? 'Signing out…' : 'Sign out' }}</button>
            </header>
            <nav class="tabs" aria-label="My Zo Stream sections">
                <button v-for="item in ['overview', 'watching', 'account']" :key="item" :aria-current="tab === item ? 'page' : undefined" :class="{ active: tab === item }" @click="showTab(item)">{{ label(item) }}</button>
            </nav>
            <form class="range-filter" @submit.prevent="applyRange">
                <label>{{ tab === 'account' ? 'Show payments for' : 'Show activity for' }}<select v-model="draft.period" :aria-label="tab === 'account' ? 'Show payments for' : 'Show activity for'"><option v-for="item in ranges" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                <template v-if="draft.period === 'custom'"><label>From<input v-model="draft.from" type="date" :max="today" required></label><label>To<input v-model="draft.to" type="date" :min="draft.from" :max="today" required></label></template>
                <button class="primary-button" type="submit">Apply</button>
                <button type="button" class="quiet-button" @click="loadSection(tab === 'account' ? 'payments' : tab)">↻ Refresh</button>
                <p v-if="rangeError" class="field-error" role="alert">{{ rangeError }}</p>
            </form>

            <div v-show="tab === 'overview'" class="tab-content" :aria-busy="sections.overview.loading">
                <div v-if="sections.overview.error" class="notice" role="alert">{{ sections.overview.error }} <button @click="loadSection('overview')">Retry overview</button></div>
                <p v-if="sections.overview.loading" class="loading" role="status">{{ stats ? 'Updating your overview…' : 'Loading your overview…' }}</p>
                <template v-if="stats">
                    <div class="section-heading"><div><p class="eyebrow">YOUR VIEWING</p><h2>{{ periodLabel(stats.period) }}</h2></div></div>
                    <section class="metrics" aria-label="Viewing summary">
                        <article><span class="metric-icon">◷</span><strong>{{ watchTime(stats.watch_seconds) }}</strong><span>Watch time</span></article>
                        <article><span class="metric-icon">▶</span><strong>{{ stats.viewed_titles ?? 'Not available' }}</strong><span>Titles watched</span></article>
                        <article><span class="metric-icon">↓</span><strong>{{ dataUsage(stats.bandwidth_mb) }}</strong><span>Data used</span></article>
                    </section>
                    <section class="panel plan-summary">
                        <div><p class="eyebrow">YOUR SUBSCRIPTION</p><h3 v-if="activePlans.length">{{ activePlans.map(plan => plan.plan).join(' · ') }}</h3><h3 v-else>{{ sections.account.loading ? 'Loading your plan…' : sections.account.error ? 'Plan details unavailable' : 'No active subscription' }}</h3>
                            <p v-if="activePlans[0]">Valid until {{ dateLabel(activePlans[0].valid_until) }} · {{ label(activePlans[0].device_type) }}</p></div>
                        <button class="quiet-button" @click="showTab('account')">View account →</button>
                    </section>
                    <section class="content-section">
                        <div class="section-heading"><div><p class="eyebrow">PICK UP WHERE YOU LEFT OFF</p><h2>Continue watching</h2></div></div>
                        <p v-if="sections.overview.data.continue_unavailable" class="empty">Continue watching is unavailable. <button @click="loadSection('overview')">Try again</button></p>
                        <div v-else-if="sections.overview.data.continue_watching?.length" class="poster-grid">
                            <article v-for="item in sections.overview.data.continue_watching" :key="item.id" class="poster-card">
                                <div class="poster"><img v-if="posterOf(item)" :src="posterOf(item)" alt="" loading="lazy"><span v-else>▶</span><div v-if="progress(item) !== null" class="progress"><i :style="{ width: `${progress(item)}%` }"></i></div></div>
                                <div class="poster-copy"><h3>{{ titleOf(item) }}</h3><p>{{ item.episode?.title || 'Movie' }}</p><small>{{ progress(item) === null ? watchTime(item.position) : `${progress(item)}% watched` }}</small><a v-if="watchLink(item)" :href="watchLink(item)" class="text-link">Open in app ↗</a></div>
                            </article>
                        </div>
                        <p v-else class="empty">Start watching in the Zo Stream app. Your unfinished titles will appear here.</p>
                        <p class="hint">Your latest unfinished titles, across all dates.</p>
                    </section>
                    <section class="panel content-section">
                        <div class="section-heading"><div><p class="eyebrow">YOUR ROUTINE</p><h2>Viewing activity</h2></div><span class="hint">Tap a day</span></div>
                        <p v-if="activity.length === 1 && stats.watch_seconds !== null" class="single-day">{{ dateLabel(activity[0].date) }}<strong>{{ watchTime(activity[0].seconds_watched) }}</strong></p>
                        <div v-else-if="stats.watch_seconds !== null" class="chart-scroll">
                            <div class="activity-chart" :style="{ minWidth: `${Math.max(250, activity.length * 9)}px` }">
                                <button v-for="day in activity" :key="day.date" :aria-label="`${dateLabel(day.date)}: ${watchTime(day.seconds_watched)}`" :title="`${dateLabel(day.date)}: ${watchTime(day.seconds_watched)}`" :class="{ selected: selectedDay?.date === day.date }" @click="selectedDay = day"><i :style="{ height: `${day.seconds_watched ? Math.max(3, day.seconds_watched / activityMax * 100) : 1}%` }"></i></button>
                            </div>
                        </div>
                        <p class="hint" aria-live="polite">{{ selectedDay ? `${dateLabel(selectedDay.date)} · ${watchTime(selectedDay.seconds_watched)}` : stats.watch_seconds === null ? 'Activity records are not available yet.' : 'Daily watch time for the selected dates. Swipe to explore longer ranges.' }}</p>
                    </section>
                    <section v-if="stats.most_watched?.length" class="content-section"><div class="section-heading"><h2>Your most watched</h2><button class="text-link" @click="showTab('watching')">View history →</button></div>
                        <div class="top-titles"><article v-for="(item, index) in stats.most_watched.slice(0, 3)" :key="`${item.type}-${item.content_id}`"><b>{{ index + 1 }}</b><div><strong>{{ item.title }}</strong><small>{{ item.subtitle || label(item.type) }} · {{ watchTime(item.seconds_watched) }}</small></div></article></div>
                    </section>
                </template>
            </div>

            <div v-show="tab === 'watching'" class="tab-content" :aria-busy="sections.watching.loading">
                <div class="section-heading"><div><p class="eyebrow">WHAT YOU’VE BEEN WATCHING</p><h2>Watch history &amp; data usage</h2><p class="hint">{{ periodLabel(sections.watching.data?.period) }}</p></div></div>
                <div class="list-filters"><label class="search-field">Search titles<input v-model="search" type="search" placeholder="Find a movie or episode" maxlength="100" @input="filterWatching(true)"></label><label>Type<select aria-label="Type" v-model="type" @change="filterWatching()"><option value="all">All titles</option><option value="movie">Movies</option><option value="episode">Episodes</option></select></label><label>Sort by<select aria-label="Sort by" v-model="sort" @change="filterWatching()"><option value="latest">Latest watched</option><option value="watch_time">Watch time</option><option value="data">Data used</option></select></label></div>
                <div v-if="sections.watching.error" class="notice" role="alert">{{ sections.watching.error }} <button @click="loadSection('watching')">Retry history</button></div>
                <p v-if="sections.watching.loading" class="loading" role="status">Updating your history…</p>
                <div v-if="sections.watching.data?.content_usage?.length" class="record-list">
                    <details v-for="item in sections.watching.data.content_usage" :key="`${item.type}-${item.content_id}`" class="watch-record"><summary><img v-if="item.poster" :src="item.poster" alt="" loading="lazy"><span v-else class="poster-placeholder">▶</span><span class="record-title"><strong>{{ item.title }}</strong><small>{{ item.subtitle || label(item.type) }} · {{ dateLabel(item.last_watched_at) }}</small></span><span class="record-value">{{ watchTime(item.seconds_watched) }}<small>{{ dataUsage(item.bandwidth_mb) }}</small></span><span class="chevron">⌄</span></summary><div class="record-details"><p>{{ item.type === 'episode' ? 'Episode' : 'Movie' }} watch time: {{ watchTime(item.seconds_watched) }}. Data used: {{ dataUsage(item.bandwidth_mb) }}.</p><a v-if="item.watch_url" :href="item.watch_url" class="text-link">Open title in app ↗</a></div></details>
                </div>
                <p v-else-if="!sections.watching.loading && !sections.watching.error" class="empty">No matching titles for these dates. Try another date range or clear your search.</p>
                <nav v-if="sections.watching.data?.content_pagination?.last_page > 1" class="pagination" aria-label="Watch history pages"><button :disabled="watchingPage <= 1 || sections.watching.loading" @click="watchingPage--; loadSection('watching')">← Previous</button><span>{{ sections.watching.data.content_pagination.total }} titles · Page {{ watchingPage }} of {{ sections.watching.data.content_pagination.last_page }}</span><button :disabled="watchingPage >= sections.watching.data.content_pagination.last_page || sections.watching.loading" @click="watchingPage++; loadSection('watching')">Next →</button></nav>
                <p class="hint">Watch time counts recorded viewing, including rewatches. Data usage covers recorded playback transfers; some older sessions have no usage details.</p>
            </div>

            <div v-show="tab === 'account'" class="tab-content">
                <div v-if="sections.account.error" class="notice" role="alert">{{ sections.account.error }} <button @click="loadSection('account')">Retry account</button></div>
                <p v-if="sections.account.loading" class="loading" role="status">Loading account details…</p>
                <section class="content-section"><div class="section-heading"><div><p class="eyebrow">WATCHING ON YOUR TERMS</p><h2>Your subscriptions</h2></div><button class="text-link" @click="loadSection('account')">↻ Refresh account</button></div>
                    <div class="plan-grid"><article v-for="plan in activePlans" :key="plan.id" class="panel plan-card"><span class="badge status-paid">Active</span><h3>{{ plan.plan }}</h3><p>{{ label(plan.device_type) }}</p><dl><dt>Started</dt><dd>{{ dateLabel(plan.valid_from) }}</dd><dt>Valid until</dt><dd>{{ dateLabel(plan.valid_until) }}</dd></dl></article></div>
                    <p v-if="account && !activePlans.length" class="empty">No active subscription. Choose or renew a plan in the Zo Stream app.</p>
                    <details v-if="otherPlans.length" class="panel past-plans"><summary>Other plans ({{ otherPlans.length }})</summary><div v-for="plan in otherPlans" :key="plan.id" class="simple-row"><span><strong>{{ plan.plan }}</strong><small>{{ label(plan.device_type) }} · {{ dateLabel(plan.valid_from) }} – {{ dateLabel(plan.valid_until) }}</small></span><span class="badge">{{ label(plan.status) }}</span></div></details>
                    <p class="hint">Subscription status shows your current access. Use the app to change or renew a plan.</p>
                </section>
                <section class="content-section" :aria-busy="sections.payments.loading"><div class="section-heading"><div><p class="eyebrow">YOUR PAYMENT RECORDS</p><h2>Subscription &amp; payment history</h2><p class="hint">{{ periodLabel(sections.payments.data?.period) }} · Change dates above to find older payments.</p></div><button class="quiet-button" :disabled="!!downloading || !sections.payments.data?.pagination?.total" @click="download()">{{ downloading === 'csv' ? 'Downloading…' : '↓ Download CSV' }}</button></div>
                    <div class="list-filters"><label>Payment status<select aria-label="Payment status" v-model="paymentStatus" @change="filterPayments"><option value="all">All payments</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="failed">Failed</option><option value="refunded">Refunded</option></select></label><label>Device type<select aria-label="Device type" v-model="paymentDevice" @change="filterPayments"><option value="all">All devices</option><option value="mobile">Mobile</option><option value="tv">TV</option><option value="browser">Browser</option><option value="desktop">Desktop (Mac / Windows)</option></select></label></div>
                    <div v-if="sections.payments.error" class="notice" role="alert">{{ sections.payments.error }} <button @click="loadSection('payments')">Retry payments</button></div><p v-if="downloadError" class="notice" role="alert">{{ downloadError }}</p><p v-if="sections.payments.loading" class="loading" role="status">Updating payment history…</p>
                    <div v-if="sections.payments.data?.items?.length" class="record-list"><details v-for="payment in sections.payments.data.items" :key="payment.id" class="payment-record"><summary><span class="record-title"><strong>{{ payment.plan }}</strong><small>{{ dateLabel(payment.payment_date) }} · {{ label(payment.device_type) }}</small></span><span class="record-value">{{ money(payment.amount, payment.currency) }}<span class="badge" :class="`status-${payment.status}`">{{ label(payment.status) }}</span></span><span class="chevron">⌄</span></summary><div class="record-details"><dl><dt>Payment date</dt><dd>{{ dateLabel(payment.payment_date, true) }}</dd><dt>Payment status</dt><dd>{{ label(payment.status) }}</dd><dt>Valid until</dt><dd>{{ dateLabel(payment.valid_until) }}</dd><dt>Purchase type</dt><dd>{{ label(payment.payment_type) }}</dd><dt>Payment method</dt><dd>{{ label(payment.payment_method) }}</dd><dt>Payment gateway</dt><dd>{{ label(payment.payment_gateway) }}</dd><dt>Transaction ID</dt><dd class="transaction">{{ payment.transaction_id || 'Not recorded' }}</dd></dl><button v-if="payment.receipt_available" class="primary-button" :disabled="!!downloading" @click="download(payment)">{{ downloading === String(payment.id) ? 'Preparing receipt…' : '↓ Download receipt (PDF)' }}</button><p v-else class="hint">Receipts are available for paid payments only.</p></div></details></div>
                    <p v-else-if="!sections.payments.loading && !sections.payments.error" class="empty">No subscription payments for these dates and filters.</p>
                    <nav v-if="sections.payments.data?.pagination?.last_page > 1" class="pagination" aria-label="Payment history pages"><button :disabled="paymentPage <= 1 || sections.payments.loading" @click="paymentPage--; loadSection('payments')">← Previous</button><span>{{ sections.payments.data.pagination.total }} payments · Page {{ paymentPage }} of {{ sections.payments.data.pagination.last_page }}</span><button :disabled="paymentPage >= sections.payments.data.pagination.last_page || sections.payments.loading" @click="paymentPage++; loadSection('payments')">Next →</button></nav>
                    <p class="hint">CSV includes all payments matching the selected dates and payment filters. A paid payment can belong to a subscription that has since expired.</p>
                </section>
                <section v-if="account" class="content-section"><div class="section-heading"><div><p class="eyebrow">WHERE YOU WATCH</p><h2>Linked devices <small>({{ account.devices.length }})</small></h2></div></div><div class="panel"><div v-for="device in account.devices" :key="device.id" class="simple-row"><span><strong>{{ device.name }}</strong><small>{{ label(device.type) }}{{ device.owner ? ' · Owner device' : '' }} · Last active {{ dateLabel(device.last_active, true) }}</small></span><span class="badge">{{ label(device.status) }}</span></div><p v-if="!account.devices.length" class="empty">No linked devices found.</p><p class="hint">Manage your devices in the Zo Stream app.</p></div></section>
            </div>
            <footer class="page-footer">Your account details are private. Statistics reflect the playback and payment records available to Zo Stream.</footer>
        </div>
    </main>
</template>

<style scoped>
.my-stats-page { min-height: 100vh; padding: 32px 20px 72px; background: radial-gradient(ellipse at 80% 0%, #137ea31a, transparent 45%); color: #eaf0f7; }
.dashboard { max-width: 1100px; margin: auto; }
.page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; padding: 12px 0 28px; }
.back-link { display: inline-block; margin-bottom: 24px; font-size: 12px; color: #a9b8c7; }
.eyebrow { margin: 0 0 8px; color: #61cee7; font-size: 10px; font-weight: 800; letter-spacing: 1.6px; }
h1 { margin: 0 0 12px; font: 800 clamp(32px, 5vw, 52px)/1.1 var(--display, sans-serif); letter-spacing: -1.8px; }
h1 span { color: #79dbef; }
.greeting { margin: 0; color: #b7c3cf; font-size: 14px; }
.greeting small { display: inline-block; margin-left: 8px; color: #8d9dab; }
button, a, input, select, summary { -webkit-tap-highlight-color: transparent; }
button { cursor: pointer; font: inherit; }
button:disabled { cursor: not-allowed; opacity: .45; }
button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible, summary:focus-visible { outline: 2px solid #76dff6; outline-offset: 4px; }
.quiet-button, .primary-button, .pagination button { min-height: 42px; padding: 10px 15px; border: 1px solid #ffffff24; border-radius: 11px; background: #ffffff08; color: #e6f3fa; font-size: 12px; font-weight: 700; white-space: nowrap; }
.primary-button { background: #73d6ea; border-color: #73d6ea; color: #07222c; }
.text-link, .notice button, .empty button { border: 0; background: none; padding: 4px 0; color: #7bddf1; font-size: 12px; font-weight: 700; }
.tabs { display: flex; gap: 5px; padding: 5px; border: 1px solid #ffffff12; border-radius: 15px; background: #0f1924; }
.tabs button { flex: 1; padding: 13px 10px; border: 0; border-radius: 10px; background: transparent; color: #9daebe; font-size: 14px; font-weight: 700; }
.tabs button.active { color: #f0fcff; background: #263c4d; box-shadow: 0 2px 8px #0002; }
.range-filter, .list-filters { display: flex; align-items: end; flex-wrap: wrap; gap: 12px; margin: 22px 0 30px; }
label { display: grid; gap: 7px; color: #a9b9c8; font-size: 11px; font-weight: 600; }
input, select { min-height: 44px; width: 100%; padding: 10px 12px; border: 1px solid #ffffff24; border-radius: 10px; background: #121e2a; color: #edf5fc; font: inherit; font-size: 13px; color-scheme: dark; }
.range-filter label:first-child { min-width: 175px; }
.field-error { flex-basis: 100%; margin: 0; color: #ffb9ae; font-size: 12px; }
.tab-content { min-height: 200px; }
.section-heading { display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap; margin: 0 0 18px; }
h2 { margin: 0; font-size: clamp(18px, 2.5vw, 23px); font-weight: 700; letter-spacing: -.4px; }
h2 small { color: #99aabd; font-size: 16px; }
h3 { margin: 0; font-size: 16px; }
.metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 22px; }
.metrics article, .panel { border: 1px solid #ffffff14; background: linear-gradient(140deg, #172330e8, #101923e8); border-radius: 17px; }
.metrics article { display: grid; gap: 11px; padding: 22px; }
.metric-icon { color: #6dd5eb; font-size: 24px; }
.metrics strong { font-size: clamp(21px, 3.2vw, 32px); letter-spacing: -.7px; }
.metrics article > span:last-child { color: #a5b6c7; font-size: 12px; }
.panel { padding: 22px; }
.plan-summary { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 36px; border-left: 3px solid #69d1c0; }
.plan-summary p:not(.eyebrow), .plan-card > p { color: #aabaca; font-size: 12px; margin: 9px 0 0; }
.content-section { margin-bottom: 34px; }
.poster-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.poster-card { overflow: hidden; border: 1px solid #ffffff12; border-radius: 15px; background: #131f2b; }
.poster { position: relative; display: grid; place-items: center; aspect-ratio: 16/10; background: #1c2c3d; color: #70d5ed; }
.poster img { height: 100%; width: 100%; object-fit: cover; }
.progress { position: absolute; bottom: 0; left: 0; right: 0; height: 4px; background: #ffffff40; }
.progress i { display: block; height: 100%; background: #73d6ea; }
.poster-copy { display: grid; gap: 7px; padding: 14px; }
.poster-copy h3 { font-size: 13px; line-height: 1.4; }
.poster-copy p, .poster-copy small { color: #a8bacb; font-size: 11px; margin: 0; }
.hint { color: #91a5b8; font-size: 11px; line-height: 1.7; margin: 12px 0 0; }
.chart-scroll { overflow-x: auto; }
.single-day { display: grid; gap: 12px; margin: 20px 0; color: #a9bdcc; font-size: 13px; }
.single-day strong { color: #88dded; font-size: 28px; }
.activity-chart { display: flex; justify-content: space-around; height: 140px; gap: 3px; padding-top: 12px; border-bottom: 1px solid #ffffff1a; }
.activity-chart button { display: flex; align-items: end; justify-content: center; height: 100%; min-width: 6px; max-width: 28px; flex: 1; padding: 0; background: none; border: 0; }
.activity-chart i { display: block; width: 100%; min-height: 2px; border-radius: 4px 4px 0 0; background: #3d9bb6; }
.activity-chart button.selected i, .activity-chart button:hover i { background: #9ae8f7; }
.top-titles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.top-titles article { display: flex; align-items: center; gap: 14px; padding: 17px; border: 1px solid #ffffff12; border-radius: 13px; background: #121e29; }
.top-titles b { font-size: 30px; color: #6acbdf; }
.top-titles strong { display: block; font-size: 13px; line-height: 1.5; }
.top-titles small { display: block; margin-top: 5px; font-size: 10px; color: #9fb2c3; }
.search-field { flex: 1; min-width: 200px; }
.record-list { overflow: hidden; border: 1px solid #ffffff18; border-radius: 16px; background: #121d29; }
.record-list details + details { border-top: 1px solid #ffffff14; }
.record-list summary { display: flex; align-items: center; gap: 15px; padding: 17px; cursor: pointer; list-style: none; }
.record-list summary::-webkit-details-marker { display: none; }
.record-list summary img, .poster-placeholder { width: 62px; height: 45px; object-fit: cover; border-radius: 7px; flex: none; }
.poster-placeholder { display: grid; place-items: center; background: #213447; color: #7bdff1; }
.record-title { display: grid; gap: 7px; min-width: 0; flex: 1; }
.record-title strong { overflow-wrap: anywhere; font-size: 13px; line-height: 1.5; }
.record-title small, .record-value small { font-size: 11px; color: #9fb3c6; line-height: 1.6; }
.record-value { display: grid; justify-items: end; gap: 7px; font-size: 13px; flex: none; }
.chevron { color: #8199ae; }
details[open] .chevron { transform: rotate(180deg); }
.record-details { border-top: 1px solid #ffffff10; padding: 18px; background: #0c1722; }
.record-details > p { color: #a3b7c9; font-size: 12px; line-height: 1.8; margin: 0 0 12px; }
.pagination { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 18px 0; }
.pagination span { color: #a0b4c7; font-size: 11px; text-align: center; }
.plan-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.plan-card h3 { margin-top: 14px; }
.badge { display: inline-flex; padding: 4px 9px; border-radius: 20px; background: #ffffff0d; color: #b1c3d3; font-size: 10px; font-weight: 700; }
.status-paid { color: #8ee4bb; background: #39bd8120; }
.status-pending { color: #f0cc87; background: #db9d2d20; }
.status-failed { color: #f3a7a7; background: #d9575720; }
.status-refunded { color: #bca7ee; background: #8661ca20; }
dl { display: grid; grid-template-columns: minmax(100px, .4fr) 1fr; gap: 12px; font-size: 12px; line-height: 1.7; margin: 20px 0; }
dt { color: #91a9be; }
dd { margin: 0; overflow-wrap: anywhere; }
.transaction { font-family: monospace; }
.past-plans { margin-top: 15px; }
.past-plans > summary { font-size: 13px; cursor: pointer; }
.simple-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 17px 0; border-bottom: 1px solid #ffffff10; }
.simple-row strong { font-size: 13px; }
.simple-row small { display: block; color: #9fb3c6; font-size: 11px; line-height: 1.7; margin-top: 5px; }
.simple-row .badge { flex: none; }
.empty { padding: 25px 18px; border: 1px dashed #ffffff24; border-radius: 14px; color: #a0b4c7; font-size: 13px; line-height: 1.8; }
.loading { display: flex; gap: 10px; color: #a8cddd; font-size: 12px; padding: 14px 0; }
.loading::before { content: ''; width: 14px; height: 14px; flex: none; border-radius: 50%; border: 2px solid #5bd8fa30; border-top-color: #83d9ec; animation: spin 1s linear infinite; }
.notice { padding: 15px 17px; margin-bottom: 18px; border: 1px solid #eeb15d45; border-radius: 12px; background: #eeb15d0d; color: #efc68e; font-size: 13px; line-height: 1.8; }
.notice button { margin-left: 10px; }
.page-footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ffffff0d; color: #8096ab; font-size: 10px; line-height: 1.8; }
.login-wrap { max-width: 700px; margin: auto; }
.login-wrap :deep(.public-login-card form) { grid-template-columns: 1fr; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (min-width: 1000px) { .my-stats-page { padding: 46px 50px 80px; } }
@media (max-width: 700px) { .poster-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .top-titles { grid-template-columns: 1fr; } .plan-grid { grid-template-columns: 1fr; } .metrics { gap: 8px; } .metrics article { padding: 16px 12px; } .metrics strong { font-size: 20px; } .plan-summary { align-items: flex-start; flex-direction: column; } }
@media (max-width: 420px) { .my-stats-page { padding-inline: 14px; } .metrics { grid-template-columns: 1fr; } .metrics article { grid-template-columns: 28px 1fr auto; align-items: center; gap: 10px; } .metrics article > span:last-child { text-align: right; } .record-list summary { padding: 13px 11px; gap: 9px; } .record-list summary img, .poster-placeholder { width: 43px; height: 38px; } .record-value { font-size: 12px; } .pagination button { padding: 8px; font-size: 10px; } .greeting small { margin: 5px 0 0; display: block; } }
@media (prefers-reduced-motion: reduce) { .loading::before { animation: none; } }
</style>
