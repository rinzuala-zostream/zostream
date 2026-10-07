<script setup>
import { computed, onMounted, ref } from 'vue';
import PublicLoginCard from '../PublicLoginCard.vue';
import { authenticatedFetch, clearPublicSession, getPublicSession } from '../../lib/publicAuth';

const session = ref(getPublicSession());
const loading = ref(true);
const error = ref('');
const stats = ref(null);
const history = ref([]);
const subscriptions = ref([]);
const devices = ref([]);
const contentPage = ref(1);

const continueWatching = computed(() => history.value.filter((item) => {
    const position = Number(item.position || 0);
    const duration = Number(item.duration || 0);
    return position > 0 && (!duration || position / duration < 0.9);
}).slice(0, 4));
const activeSubscription = computed(() => subscriptions.value.find((item) => {
    const status = String(item.status || item.subscription_status || '').toLowerCase();
    return ['active', 'success', '1'].includes(status) || item.is_active === true;
}));

function payloadData(payload) { return payload?.data ?? payload; }
function listFrom(payload) {
    const data = payloadData(payload);
    if (Array.isArray(data)) return data;
    for (const key of ['data', 'items', 'subscriptions', 'devices']) if (Array.isArray(data?.[key])) return data[key];
    return [];
}
function titleOf(item) {
    return item.movie?.title || item.title || 'Zo Stream title';
}
function episodeOf(item) { return item.episode?.title || item.subtitle || ''; }
function posterOf(item) {
    return item.episode?.thumbnail || item.movie?.poster || item.movie?.cover_img || item.poster || '';
}
function percentOf(item) {
    const duration = Number(item.duration || 0);
    if (!duration) return 0;
    return Math.min(100, Math.round((Number(item.position || 0) / duration) * 100));
}
function prettyPosition(seconds) {
    const value = Math.max(0, Number(seconds || 0));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    return hours ? `${hours}h ${minutes}m` : `${minutes} min`;
}
function prettyBytes(mb) {
    if (mb === null || mb === undefined) return 'Not available';
    return Number(mb) >= 1024 ? `${(Number(mb) / 1024).toFixed(1)} GB` : `${Number(mb).toFixed(0)} MB`;
}

async function loadStats(page = 1) {
    loading.value = true;
    error.value = '';
    contentPage.value = page;
    try {
        if (session.value?.access_token) {
            const requests = await Promise.all([
                authenticatedFetch(`/api/v4/account/stats?page=${page}&per_page=10`),
                authenticatedFetch('/api/v4/library/history?per_page=30'),
                authenticatedFetch('/api/v4/account/subscriptions?per_page=30'),
                authenticatedFetch('/api/v4/account/devices'),
            ]);
            const responses = await Promise.all(requests.map(async (response) => {
                if (!response.ok) throw new Error(response.status === 401 ? 'Your sign-in expired. Please log in again.' : 'Stats are temporarily unavailable. Please try again.');
                return response.json();
            }));
            stats.value = payloadData(responses[0]);
            history.value = Array.isArray(responses[1]?.watch_history) ? responses[1].watch_history : listFrom(responses[1]);
            subscriptions.value = listFrom(responses[2]);
            devices.value = listFrom(responses[3]);
        } else {
            const response = await fetch(`/account/stats/data?page=${page}&per_page=10`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.status === 401) {
                stats.value = null;
                history.value = [];
                subscriptions.value = [];
                devices.value = [];
                loading.value = false;
                return;
            }
            if (!response.ok) throw new Error('Stats are temporarily unavailable. Please try again.');
            const payload = await response.json();
            stats.value = payload.stats;
            history.value = payload.watch_history || [];
            subscriptions.value = listFrom(payload.subscriptions);
            devices.value = listFrom(payload.devices);
        }
    } catch (reason) {
        error.value = reason.message || 'Stats are temporarily unavailable.';
    } finally {
        loading.value = false;
    }
}

async function handleAuthenticated(value) {
    session.value = value;
    await loadStats();
}

async function logout() {
    try {
        if (session.value?.access_token) {
            await authenticatedFetch('/api/v4/auth/logout', { method: 'POST' });
        } else {
            await fetch('/account/stats/logout', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json' },
            });
        }
    } catch { /* Local sign-out still clears this browser session. */ }
    clearPublicSession();
    session.value = null;
    stats.value = null;
    history.value = [];
    subscriptions.value = [];
    devices.value = [];
}

onMounted(() => {
    document.title = 'My Zo Stream — Zo Stream';
    if (window.location.search.includes('bridge=expired')) error.value = 'The app sign-in link expired. Please open My Zo Stream again from the app.';
    if (new URLSearchParams(window.location.search).get('app') === '1') {
        clearPublicSession();
        session.value = null;
        window.history.replaceState({}, '', '/account/stats');
    }
    loadStats();
});
</script>

<template>
    <main class="my-stats-page">
        <div v-if="loading && !stats" class="stats-state"><span class="stats-spinner"></span><p>Loading your stats…</p></div>
        <div v-else-if="!session?.access_token && !stats" class="stats-login-wrap">
            <a class="stats-back" href="/">← Zo Stream home</a>
            <p v-if="error" class="stats-alert">{{ error }}</p>
            <PublicLoginCard @authenticated="handleAuthenticated" />
        </div>
        <div v-else class="stats-content">
            <header class="stats-header">
                <div><a class="stats-back" href="/">← Zo Stream</a><span class="stats-kicker">YOUR ACCOUNT, YOUR WATCHING</span><h1>My <b>Zo Stream</b></h1><p>Your viewing, data usage and account status in one place.</p></div>
                <button class="stats-logout" @click="logout">Sign out</button>
            </header>
            <p v-if="error" class="stats-alert">{{ error }} <button @click="loadStats">Try again</button></p>
            <div v-if="loading" class="stats-state"><span class="stats-spinner"></span><p>Loading your stats…</p></div>
            <template v-else-if="stats">
                <section class="stats-metrics" aria-label="This month's viewing">
                    <article class="stats-metric"><span>THIS MONTH</span><strong>{{ stats.watch_minutes === null ? '—' : prettyPosition(Number(stats.watch_minutes || 0) * 60) }}</strong><small>Watch time</small></article>
                    <article class="stats-metric"><span>THIS MONTH</span><strong>{{ stats.viewed_titles ?? '—' }}</strong><small>Titles watched</small></article>
                    <article class="stats-metric"><span>THIS MONTH</span><strong>{{ stats.completed_sessions ?? '—' }}</strong><small>Completed sessions</small></article>
                    <article class="stats-metric"><span>THIS MONTH</span><strong>{{ prettyBytes(stats.bandwidth_mb) }}</strong><small>Data used</small></article>
                </section>

                <section class="stats-section">
                    <div class="stats-section-heading"><div><span>Playback and transfer records</span><h2>Data usage by title</h2></div><small class="usage-count">{{ stats.content_pagination?.total || 0 }} titles</small></div>
                    <div v-if="stats.content_usage?.length" class="usage-list">
                        <article v-for="(item, index) in stats.content_usage" :key="`${item.type}-${item.title}-${item.subtitle || ''}-${index}`" class="usage-row">
                            <div class="usage-poster"><img v-if="item.poster" :src="item.poster" :alt="item.title" loading="lazy"><span v-else>▶</span></div>
                            <div class="usage-title"><strong>{{ item.title }}</strong><small v-if="item.subtitle">{{ item.subtitle }}</small><small v-else>{{ item.type === 'episode' ? 'Episode' : 'Movie' }}</small></div>
                            <div class="usage-stat"><span>Watched</span><strong>{{ prettyPosition(item.seconds_watched) }}</strong></div>
                            <div class="usage-stat"><span>Data used</span><strong>{{ prettyBytes(item.bandwidth_mb) }}</strong></div>
                        </article>
                        <nav v-if="stats.content_pagination?.last_page > 1" class="usage-pagination" aria-label="Title usage pages">
                            <button :disabled="contentPage <= 1 || loading" @click="loadStats(contentPage - 1)">← Previous</button>
                            <span>Page {{ contentPage }} of {{ stats.content_pagination.last_page }}</span>
                            <button :disabled="contentPage >= stats.content_pagination.last_page || loading" @click="loadStats(contentPage + 1)">Next →</button>
                        </nav>
                    </div>
                    <p v-else class="stats-empty">No viewing or data usage records are available for this month.</p>
                </section>

                <section v-if="stats.most_watched?.length" class="stats-section">
                    <div class="stats-section-heading"><div><span>Based on your watch time</span><h2>Most watched</h2></div></div>
                    <div class="stats-title-grid">
                        <article v-for="(item, index) in stats.most_watched" :key="`${item.title}-${index}`" class="stats-title-card">
                            <div class="stats-poster"><img v-if="item.poster" :src="item.poster" :alt="item.title" loading="lazy"><span v-else>▶</span></div>
                            <div class="stats-title-copy"><strong>{{ item.title }}</strong><small>{{ item.subtitle ? `${item.subtitle} · ` : '' }}{{ prettyPosition(item.seconds_watched) }} this month</small></div>
                        </article>
                    </div>
                </section>

                <section v-if="continueWatching.length" class="stats-section">
                    <div class="stats-section-heading"><div><span>Pick up where you left off</span><h2>Continue watching</h2></div><a href="/#download">Open Zo Stream</a></div>
                    <div class="stats-title-grid">
                        <article v-for="item in continueWatching" :key="item.id || item.movie_id" class="stats-title-card">
                            <div class="stats-poster"><img v-if="posterOf(item)" :src="posterOf(item)" :alt="titleOf(item)" loading="lazy"><span v-else>▶</span><div class="stats-progress"><i :style="{ width: `${percentOf(item)}%` }"></i></div></div>
                            <div class="stats-title-copy"><strong>{{ titleOf(item) }}</strong><small>{{ episodeOf(item) ? `${episodeOf(item)} · ` : '' }}{{ percentOf(item) }}% watched</small></div>
                        </article>
                    </div>
                </section>

                <section class="stats-lower-grid">
                    <article class="stats-panel">
                        <div class="stats-section-heading"><div><span>Your activity</span><h2>Recently watched</h2></div></div>
                        <div v-if="history.length" class="stats-history-list"><div v-for="item in history.slice(0, 7)" :key="item.id || item.movie_id" class="stats-history-row"><span class="stats-history-icon">▶</span><div><strong>{{ titleOf(item) }}</strong><small>{{ episodeOf(item) ? `Episode · ${episodeOf(item)}` : 'Movie' }}</small></div><span class="stats-history-progress">{{ percentOf(item) }}%</span></div></div>
                        <p v-else class="stats-empty">Your watch history will show up here.</p>
                    </article>
                    <article class="stats-panel account-panel">
                        <div class="stats-section-heading"><div><span>Membership</span><h2>Account status</h2></div></div>
                        <div class="account-status-line"><span :class="['status-dot', activeSubscription ? 'is-active' : '']"></span><div><strong>{{ activeSubscription ? 'Subscription active' : 'No active subscription found' }}</strong><small v-if="activeSubscription">{{ activeSubscription.plan_name || activeSubscription.plan?.name || activeSubscription.plan || 'Zo Stream plan' }}<template v-if="activeSubscription.expiry_date || activeSubscription.end_date"> · Until {{ activeSubscription.expiry_date || activeSubscription.end_date }}</template></small><small v-else>Manage your plan in the Zo Stream app.</small></div></div>
                        <div class="account-device-count"><span>Registered devices</span><strong>{{ devices.length || '—' }}</strong></div>
                        <a class="account-link" href="/download">Manage Zo Stream account <span>↗</span></a>
                    </article>
                </section>
                <p class="stats-footnote">Data usage is shown from recorded playback logs. Some older sessions may not include usage details.</p>
            </template>
        </div>
    </main>
</template>

<style scoped>
.my-stats-page{min-height:100vh;padding:clamp(28px,6vw,76px) clamp(18px,6vw,96px) 80px;background:radial-gradient(ellipse at 82% 4%,rgba(23,191,243,.13),transparent 34%),radial-gradient(ellipse at 4% 24%,rgba(87,68,222,.14),transparent 31%)}.stats-content{width:min(100%,1240px);margin:auto}.stats-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:0 auto 34px;padding:20px 0 32px;border-bottom:1px solid rgba(255,255,255,.1)}.stats-back{display:inline-flex;margin-bottom:27px;color:#a8b4bf;font-size:12px;font-weight:700}.stats-kicker,.stats-section-heading>div>span{display:block;color:#20c7f8;font-size:10px;font-weight:900;letter-spacing:2px}.stats-header h1{margin:12px 0 9px;font:800 clamp(38px,6vw,66px)/1 var(--display);letter-spacing:-3px}.stats-header h1 b{color:#20c7f8}.stats-header p{margin:0;color:#a3abb4;font-size:14px}.stats-logout{padding:11px 16px;border:1px solid rgba(255,255,255,.14);border-radius:11px;background:rgba(255,255,255,.04);color:#e8edf1;cursor:pointer;font-size:12px;font-weight:800}.stats-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:24px 0 52px}.stats-metric,.stats-panel{border:1px solid rgba(255,255,255,.1);border-radius:20px;background:linear-gradient(140deg,rgba(21,29,40,.87),rgba(12,16,23,.82));box-shadow:0 24px 60px rgba(0,0,0,.13)}.stats-metric{display:grid;gap:10px;min-height:150px;padding:22px}.stats-metric span{color:#8492a0;font-size:9px;font-weight:900;letter-spacing:1.8px}.stats-metric strong{font:800 clamp(25px,3vw,34px)/1 var(--display)}.stats-metric small{color:#98a3ae;font-size:12px}.stats-section{margin:0 0 50px}.stats-section-heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:17px}.stats-section-heading h2{margin:6px 0 0;font:750 23px var(--display);letter-spacing:-.6px}.stats-section-heading>a{color:#32cafa;font-size:11px;font-weight:800}.stats-title-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px}.stats-title-card{overflow:hidden;border:1px solid rgba(255,255,255,.1);border-radius:16px;background:rgba(17,22,30,.8)}.stats-poster{position:relative;display:grid;place-items:center;aspect-ratio:16/9;background:linear-gradient(135deg,#171f2c,#0b0e14);color:#27c7f4}.stats-poster img{width:100%;height:100%;object-fit:cover}.stats-progress{position:absolute;right:0;bottom:0;left:0;height:4px;background:rgba(255,255,255,.25)}.stats-progress i{display:block;height:100%;background:#1ec8f4}.stats-title-copy{display:grid;gap:5px;padding:12px 13px}.stats-title-copy strong{overflow:hidden;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.stats-title-copy small,.stats-history-row small{color:#8e9aa5;font-size:10px}.stats-lower-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:16px}.stats-panel{min-width:0;padding:23px}.stats-panel .stats-section-heading{margin-bottom:18px}.stats-history-list{display:grid}.stats-history-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid rgba(255,255,255,.07)}.stats-history-icon{display:grid;place-items:center;width:34px;height:34px;flex:none;border-radius:11px;background:rgba(25,194,240,.1);color:#35cefa;font-size:10px}.stats-history-row>div{display:grid;gap:4px;min-width:0;flex:1}.stats-history-row strong{overflow:hidden;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.stats-history-progress{color:#a6b5c0;font-size:10px;font-weight:800}.account-status-line{display:flex;gap:12px;align-items:flex-start;padding:12px 0 19px}.status-dot{width:9px;height:9px;margin-top:5px;border-radius:50%;background:#83909b;box-shadow:0 0 0 5px rgba(131,144,155,.12)}.status-dot.is-active{background:#5be2a2;box-shadow:0 0 0 5px rgba(91,226,162,.12)}.account-status-line div{display:grid;gap:6px}.account-status-line strong{font-size:12px}.account-status-line small{color:#8e9aa5;font-size:10px}.account-device-count{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-top:1px solid rgba(255,255,255,.08);color:#a1acb5;font-size:11px}.account-device-count strong{color:white;font-size:15px}.account-link{display:flex;justify-content:space-between;margin-top:8px;padding:14px 15px;border:1px solid rgba(28,195,241,.24);border-radius:12px;background:rgba(20,184,231,.07);color:#6ddafa;font-size:11px;font-weight:800}.stats-footnote{margin:22px 0 0;color:#788591;font-size:10px;line-height:1.6}.stats-empty{padding:20px 0;color:#84909b;font-size:12px}.stats-state{display:flex;min-height:45vh;align-items:center;justify-content:center;gap:12px;color:#aab5be;font-size:13px}.stats-spinner{width:20px;height:20px;border:2px solid rgba(38,200,245,.25);border-top-color:#26c8f5;border-radius:50%;animation:spin .8s linear infinite}.stats-alert{margin:20px 0;padding:13px 16px;border:1px solid rgba(245,158,11,.3);border-radius:12px;background:rgba(245,158,11,.08);color:#f4c46b;font-size:12px}.stats-alert button{margin-left:10px;background:transparent;color:#fff;text-decoration:underline;cursor:pointer}.stats-login-wrap{width:min(100%,740px);margin:auto}.stats-login-wrap :deep(.public-login-card){margin:0 auto}.stats-login-wrap :deep(.public-login-card form){grid-template-columns:1fr}.stats-login-wrap :deep(.public-login-card button){grid-column:1/-1}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:850px){.stats-title-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.stats-lower-grid{grid-template-columns:1fr}}@media(max-width:580px){.my-stats-page{padding:20px 16px 50px}.stats-header{padding-top:3px;margin-bottom:24px}.stats-header h1{letter-spacing:-1.7px}.stats-metrics{gap:8px;margin:16px 0 38px}.stats-metric{min-height:125px;padding:15px 12px;border-radius:15px}.stats-metric strong{font-size:21px}.stats-metric span{font-size:7px;letter-spacing:1px}.stats-metric small{font-size:10px}.stats-title-grid{gap:9px}.stats-section-heading h2{font-size:20px}.stats-panel{padding:18px}.stats-logout{padding:9px 11px;font-size:10px}}
.stats-metrics{grid-template-columns:repeat(4,minmax(0,1fr))}@media(max-width:850px){.stats-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
.usage-count{color:#9ba8b3;font-size:11px}.usage-list{overflow:hidden;border:1px solid rgba(255,255,255,.1);border-radius:17px;background:rgba(15,20,28,.7)}.usage-row{display:grid;grid-template-columns:64px minmax(0,1fr) minmax(100px,.35fr) minmax(100px,.35fr);align-items:center;gap:16px;padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.07)}.usage-row:last-of-type{border-bottom:0}.usage-poster{display:grid;place-items:center;width:64px;height:44px;overflow:hidden;border-radius:8px;background:#151e29;color:#36cafa}.usage-poster img{width:100%;height:100%;object-fit:cover}.usage-title{display:grid;gap:5px;min-width:0}.usage-title strong{overflow:hidden;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.usage-title small,.usage-stat span{color:#8f9ba6;font-size:10px}.usage-stat{display:grid;gap:5px}.usage-stat strong{font-size:12px}.usage-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);color:#9ba8b3;font-size:11px}.usage-pagination button{padding:8px 11px;border:1px solid rgba(255,255,255,.12);border-radius:9px;background:rgba(255,255,255,.04);color:#e5f7fc;cursor:pointer;font-size:10px;font-weight:800}.usage-pagination button:disabled{opacity:.38;cursor:not-allowed}
@media(max-width:580px){.usage-row{grid-template-columns:48px minmax(0,1fr) auto;gap:10px;padding:11px}.usage-poster{width:48px;height:38px}.usage-stat{justify-items:end}.usage-stat:nth-of-type(4){grid-column:3;grid-row:1}.usage-stat:nth-of-type(3){grid-column:2;grid-row:2;justify-items:start}.usage-pagination{padding:12px 10px}.usage-pagination span{font-size:10px}}
</style>
