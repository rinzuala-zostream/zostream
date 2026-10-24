<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, queryString } from '../lib/api';
import StatusPanel from './StatusPanel.vue';
const props = defineProps({ filters: { type: Object, default: () => ({}) } });
const report = ref(null), loading = ref(false), error = ref('');
const roomType = ref(''), contentId = ref('');
let sequence = 0, controller;
const number = (value) => new Intl.NumberFormat('en-IN').format(Number(value || 0));
const maximum = computed(() => Math.max(1, ...(report.value?.trend || []).map(row => Number(row.messages))));
async function load() {
    const current = ++sequence;
    controller?.abort(); controller = new AbortController(); loading.value = true; error.value = '';
    const keys = ['from', 'to', 'timezone', 'platform', 'app_version', 'user_id', 'content_type'];
    const values = Object.fromEntries(keys.map(key => [key, props.filters[key]]));
    try {
        const value = await api(`/admin/chat/metrics${queryString({ ...values, room_type: roomType.value, content_id: contentId.value })}`, { signal: controller.signal, cache: 'no-store' });
        if (current === sequence) report.value = value;
    } catch (reason) { if (current === sequence && reason.name !== 'AbortError') { error.value = reason.message; report.value = null; } }
    finally { if (current === sequence) loading.value = false; }
}
function download() {
    if (!report.value) return;
    const url = URL.createObjectURL(new Blob([JSON.stringify(report.value, null, 2)], { type: 'application/json' }));
    const link = document.createElement('a'); link.href = url; link.download = 'zostream-chat-analytics.json'; link.click(); URL.revokeObjectURL(url);
}
watch(() => props.filters, load, { deep: true, immediate: true });
onUnmounted(() => { sequence++; controller?.abort(); });
</script>
<template>
    <section class="chat-analytics">
        <form class="chat-toolbar" @submit.prevent="load">
            <label>Chat room type<select v-model="roomType"><option value="">All rooms</option><option value="global">Global</option><option value="movie">Movie</option><option value="episode">Episode</option></select></label>
            <label>Content ID<input v-model.trim="contentId" placeholder="All content" maxlength="191"></label>
            <button class="admin-secondary" :disabled="loading">{{ loading ? 'Loading…' : 'Refresh chat metrics' }}</button>
            <button class="admin-secondary" type="button" :disabled="!report || loading" @click="download">Export JSON</button>
            <RouterLink class="admin-secondary" to="/chat">Open moderation</RouterLink>
        </form>
        <StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-loading" role="status">Loading chat analytics…</div>
        <template v-else-if="report">
            <div class="chat-metrics">
                <article v-for="[key, label] in [['rooms','Rooms'],['pending_reports','Pending reports'],['active_restrictions','Active restrictions'],['pending_broadcasts','Pending deliveries'],['pending_analytics','Pending analytics']]" :key="key" class="admin-panel"><span>{{ label }}</span><b>{{ number(report.operational[key]) }}</b></article>
            </div>
            <p class="chat-note">Operational counts cover all rooms and platforms now. Viewer chat is {{ report.operational.chat_enabled ? 'enabled' : 'disabled' }}. {{ report.operational.oldest_pending_broadcast ? `Oldest pending delivery: ${report.operational.oldest_pending_broadcast} UTC.` : '' }}</p>
            <StatusPanel v-if="!report.analytics_available" tone="error" message="Chat analytics is unavailable. Operational counts remain available; retry when the analytics service is restored." />
            <template v-else>
                <div class="chat-metrics">
                    <article v-for="[key,label] in [['messages','Messages sent'],['opens','Chat opens'],['unique_visitors','Unique visitors'],['unique_chatters','Unique senders'],['reports','Reports submitted'],['message_failures','Send failures'],['connection_failures','Connection failures']]" :key="key" class="admin-panel"><span>{{ label }}</span><b>{{ number(report.summary?.[key]) }}</b></article>
                </div>
                <p class="chat-note">Collected analytics for the selected date, platform, app, user and room filters. Collection-off periods have no telemetry; these are not total stored-message counts. Daily chart buckets use UTC.</p>
                <article v-if="report.linked_playback" class="admin-panel chat-card"><h2>Linked movie playback</h2><p>{{ number(report.linked_playback.sessions) }} playback sessions · {{ Number(report.linked_playback.watch_hours || 0).toFixed(2) }} watch hours · {{ Number(report.linked_playback.completion_rate || 0).toFixed(1) }}% completed</p><p class="chat-note">Only sessions linked by the same viewer and playback analytics ID are counted. This shows association with chat use, not proof that chat increased watch time.</p></article>
                <div class="chat-columns">
                    <article class="admin-panel chat-card"><h2>Daily chat activity</h2><div v-if="!report.trend.length" class="admin-empty">No chat activity in this range.</div><div v-for="row in report.trend" :key="row.date" class="chat-trend"><span>{{ row.date }}</span><i :style="{ width: `${Math.max(1, Number(row.messages) / maximum * 100)}%` }" /><small>{{ number(row.messages) }} messages · {{ number(row.opens) }} opens</small></div></article>
                    <article class="admin-panel chat-card"><h2>Platforms</h2><div v-if="!report.platforms.length" class="admin-empty">No platform data.</div><div v-for="row in report.platforms" :key="row.platform" class="chat-summary-row"><b>{{ row.platform }}</b><span>{{ number(row.messages) }} messages · {{ number(row.users) }} users</span></div></article>
                </div>
                <article class="admin-panel chat-card"><h2>Most active rooms</h2><div v-if="!report.rooms.length" class="admin-empty">No room events yet.</div><div v-for="row in report.rooms" :key="row.room_id || 'unknown'" class="chat-summary-row"><RouterLink v-if="row.room_id" :to="{path:'/chat',query:{room:row.room_id}}">{{ row.title }}</RouterLink><span v-else>Unknown room</span><span>{{ number(row.messages) }} messages · {{ number(row.users) }} users</span></div></article>
                <article class="admin-panel chat-card"><h2>Chat events</h2><div v-if="!report.events.length" class="admin-empty">No events yet.</div><div v-for="row in report.events" :key="row.name" class="chat-summary-row"><span>{{ row.name.replaceAll('_',' ') }}</span><span>{{ number(row.count) }} events · {{ number(row.users) }} users</span></div></article>
            </template>
        </template>
    </section>
</template>
