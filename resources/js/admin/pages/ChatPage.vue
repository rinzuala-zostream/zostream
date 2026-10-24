<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import ChatAnalyticsPanel from '../components/ChatAnalyticsPanel.vue';
import { api, queryString } from '../lib/api';

const route = useRoute();
const tab = ref('rooms'), loading = ref(false), error = ref(''), notice = ref('');
const pager = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const search = ref(''), roomType = ref(''), roomState = ref(''), reportStatus = ref('pending');
const roomFilter = ref(''), userFilter = ref('');
const selected = ref(null), messages = ref([]), messageSearch = ref(''), hasOlder = ref(false), roomLoading = ref(false), roomError = ref('');
const transcript = ref(null), autoRefresh = ref(true);
const action = ref(null), reason = ref(''), expiry = ref(''), globalScope = ref(false), submitting = ref(false), actionError = ref('');
const dialog = ref(null);
const roomIdInput = ref(''), userIdInput = ref('');
const from = new Date(); from.setUTCDate(from.getUTCDate() - 6);
const metricsDraft = ref({ from: from.toISOString().slice(0,10), to: new Date().toISOString().slice(0,10), timezone: 'UTC', platform: '' });
const metricsFilters = ref({ ...metricsDraft.value });
const tabs = ['rooms', 'reports', 'restrictions', 'audit', 'analytics'];
const title = computed(() => selected.value?.title || selected.value?.room_key || 'Room history');
const number = value => new Intl.NumberFormat('en-IN').format(Number(value || 0));
const stamp = value => value ? `${String(value).replace('T', ' ').replace('.000000Z', '').replace('Z', '')} UTC` : '—';
let listSeq = 0, roomSeq = 0, active = true, timer, controllers = new Set();
async function request(path, options = {}) {
    if (!active) throw new DOMException('Page closed', 'AbortError');
    const controller = new AbortController(); controllers.add(controller);
    try { return await api(path, { ...options, signal: controller.signal, cache: 'no-store' }); }
    finally { controllers.delete(controller); }
}
async function load(page = 1, quiet = false) {
    if (tab.value === 'analytics') { metricsFilters.value = { ...metricsDraft.value }; return; }
    const current = ++listSeq, currentTab = tab.value;
    if (!quiet) loading.value = true; error.value = '';
    const values = { page, per_page: 25 };
    if (currentTab === 'rooms') Object.assign(values, { q: search.value, type: roomType.value, state: roomState.value });
    if (currentTab === 'reports') Object.assign(values, { status: reportStatus.value, room_id: roomFilter.value });
    if (currentTab === 'restrictions') Object.assign(values, { room_id: roomFilter.value, user_id: userFilter.value });
    if (currentTab === 'audit') values.room_id = roomFilter.value;
    try {
        const data = await request(`/admin/chat/${currentTab}${queryString(values)}`);
        if (active && current === listSeq) {
            if (data.current_page > data.last_page && data.last_page >= 1) { await load(data.last_page, quiet); return; }
            pager.value = data;
        }
    } catch (failure) { if (active && current === listSeq && failure.name !== 'AbortError') error.value = failure.message; }
    finally { if (active && current === listSeq) loading.value = false; }
}
function selectTab(value) { tab.value = value; pager.value = { data: [], current_page: 1, last_page: 1, total: 0 }; void load(); }
async function openRoom(id) {
    if (!/^\d+$/.test(String(id))) return;
    roomSeq++; selected.value = null; messages.value = []; messageSearch.value = '';
    roomIdInput.value = String(id); await history(id);
}
async function history(id = selected.value?.id, older = false, quiet = false) {
    if (!id || (quiet && roomLoading.value)) return;
    const current = ++roomSeq, query = { q: messageSearch.value };
    if (older && messages.value.length) query.before = messages.value[0].id;
    roomLoading.value = true; roomError.value = '';
    const view = transcript.value, oldHeight = view?.scrollHeight || 0, oldTop = view?.scrollTop || 0;
    const nearBottom = !view || view.scrollHeight - view.scrollTop - view.clientHeight < 80;
    try {
        const data = await request(`/admin/chat/rooms/${id}/messages${queryString(query)}`);
        if (!active || current !== roomSeq) return;
        selected.value = data.room; hasOlder.value = data.has_more;
        const rows = older ? [...data.messages, ...messages.value] : data.messages;
        messages.value = [...new Map(rows.map(row => [row.id, row])).values()];
        await nextTick();
        if (transcript.value) {
            if (older) transcript.value.scrollTop = oldTop + transcript.value.scrollHeight - oldHeight;
            else if (!quiet || nearBottom) transcript.value.scrollTop = transcript.value.scrollHeight;
        }
    } catch (failure) { if (active && current === roomSeq && failure.name !== 'AbortError') roomError.value = failure.message; }
    finally { if (active && current === roomSeq) roomLoading.value = false; }
}
async function confirmAction(value) {
    action.value = { ...value }; reason.value = ''; expiry.value = ''; globalScope.value = value.scope === 0;
    actionError.value = ''; await nextTick(); dialog.value?.showModal();
}
function closeAction() { if (submitting.value) return; dialog.value?.close(); action.value = null; }
async function applyAction() {
    const value = { ...action.value }, why = reason.value.trim();
    if (!why || submitting.value) return;
    submitting.value = true; actionError.value = '';
    try {
        if (value.kind === 'resolve') {
            await request(`/chat/reports/${value.report}/resolve`, { method: 'POST', body: { reason: why } });
        } else {
            const scope = ['mute','ban','unban'].includes(value.kind) && globalScope.value ? 0 : value.scope;
            const body = { action: value.kind, target: String(value.target), reason: why };
            if (['mute','ban'].includes(value.kind) && expiry.value) body.expires_at = new Date(expiry.value).toISOString();
            await request(`/chat/rooms/${scope}/moderate`, { method: 'POST', body });
        }
        notice.value = 'Moderation action saved and audited.';
        dialog.value?.close(); action.value = null;
        await load(pager.value.current_page);
        if (selected.value) await history(selected.value.id);
    } catch (failure) { if (failure.name !== 'AbortError') actionError.value = failure.message; }
    finally { submitting.value = false; }
}
function userAction(kind, uid, room, name) { void confirmAction({ kind, target: uid, scope: Number(room), label: `${kind} ${name || uid}` }); }
function manualRestriction(kind) {
    if (!userIdInput.value.trim() || !/^\d+$/.test(roomIdInput.value)) { error.value = 'Enter a viewer ID and numeric room ID (0 for all rooms).'; return; }
    userAction(kind, userIdInput.value.trim(), roomIdInput.value, userIdInput.value.trim());
}
function messageAction(row) { void confirmAction({ kind: 'delete', target: row.id, scope: Number(row.room_id), label: `Delete message by ${row.display_name}` }); }
function roomAction() { const room = selected.value; if (room) void confirmAction({ kind: room.closed ? 'open' : 'close', scope: Number(room.id), target: room.id, label: `${room.closed ? 'Open' : 'Close'} ${title.value}` }); }
watch(() => route.query.room, value => { if (value) { selectTab('rooms'); void openRoom(value); } });
onMounted(() => {
    void load(); if (route.query.room) void openRoom(route.query.room);
    timer = window.setInterval(() => {
        if (!active || !autoRefresh.value || document.hidden || action.value || loading.value || submitting.value || tab.value === 'analytics') return;
        void load(pager.value.current_page, true);
        // Keep an older/search window stable until the operator explicitly refreshes it.
        if (selected.value && !messageSearch.value && (!transcript.value || transcript.value.scrollTop + transcript.value.clientHeight >= transcript.value.scrollHeight - 80)) void history(selected.value.id, false, true);
    }, 15000);
});
onUnmounted(() => { active = false; listSeq++; roomSeq++; window.clearInterval(timer); controllers.forEach(controller => controller.abort()); dialog.value?.close(); });
</script>
<template>
    <div class="admin-page chat-page">
        <PageHeader eyebrow="Community safety" title="Chat control room" description="Review global and movie conversations, handle reports and manage viewer access.">
            <label class="chat-check"><input v-model="autoRefresh" type="checkbox"> Refresh every 15s</label>
            <button class="admin-secondary" :disabled="loading" @click="load(pager.current_page)">Refresh</button>
        </PageHeader>
        <StatusPanel tone="success" :message="notice" />
        <StatusPanel tone="error" :message="error" />
        <nav class="analytics-tabs" aria-label="Chat sections"><button v-for="value in tabs" :key="value" :class="{active:tab===value}" @click="selectTab(value)">{{ value === 'audit' ? 'Audit log' : value[0].toUpperCase()+value.slice(1) }}</button></nav>
        <template v-if="tab === 'analytics'">
            <form class="chat-toolbar" @submit.prevent="metricsFilters = {...metricsDraft}"><label>From<input v-model="metricsDraft.from" type="date" required></label><label>To<input v-model="metricsDraft.to" type="date" required></label><label>Platform<select v-model="metricsDraft.platform"><option value="">All platforms</option><option value="ios">iOS</option><option value="android">Android</option></select></label><button class="admin-primary">Apply filters</button></form>
            <ChatAnalyticsPanel :filters="metricsFilters" />
        </template>
        <template v-else>
            <form class="chat-toolbar" @submit.prevent="load()">
                <template v-if="tab === 'rooms'"><label>Room / content ID<input v-model.trim="search" maxlength="150" placeholder="Search rooms"></label><label>Type<select v-model="roomType"><option value="">All types</option><option value="global">Global</option><option value="movie">Movie</option><option value="episode">Episode</option></select></label><label>State<select v-model="roomState"><option value="">All states</option><option value="open">Open</option><option value="closed">Closed</option></select></label></template>
                <template v-else><label>Room ID<input v-model="roomFilter" type="number" :min="tab==='reports'?1:0" placeholder="All rooms"></label><label v-if="tab==='reports'">Status<select v-model="reportStatus"><option value="pending">Pending</option><option value="resolved">Resolved</option><option value="all">All</option></select></label><label v-if="tab==='restrictions'">Viewer ID<input v-model.trim="userFilter" maxlength="191" placeholder="All viewers"></label></template>
                <button class="admin-primary" :disabled="loading">Apply filters</button>
            </form>
            <div v-if="loading" class="admin-loading" role="status">Loading chat records…</div>
            <template v-else-if="!error">
                <section class="admin-panel chat-card chat-table-wrap">
                    <table class="chat-table" v-if="tab==='rooms'"><thead><tr><th>Room</th><th>State</th><th>Messages</th><th>Reports</th><th>Last message</th><th></th></tr></thead><tbody><tr v-for="room in pager.data" :key="room.id"><td><b>{{ room.title }}</b><small>{{ room.room_key }} · #{{ room.id }}</small></td><td>{{ room.closed ? 'Closed' : 'Open' }}</td><td>{{ number(room.message_count) }}</td><td>{{ number(room.pending_reports) }}</td><td>{{ stamp(room.last_message_at) }}</td><td><button class="admin-secondary" @click="openRoom(room.id)">Review</button></td></tr></tbody></table>
                    <table class="chat-table" v-else-if="tab==='reports'"><thead><tr><th>Reported message</th><th>Reason / reporter</th><th>Room / status</th><th>Actions</th></tr></thead><tbody><tr v-for="row in pager.data" :key="row.id"><td><b>{{ row.display_name || row.sender_id }}</b><p class="chat-message-body">{{ row.deleted_at ? 'Message removed' : row.body || 'Message no longer retained' }}</p><small>{{ stamp(row.created_at) }}</small></td><td>{{ row.reason }}<small>{{ row.user_id }}</small></td><td>{{ row.room_key || 'Unknown room' }}<small>{{ row.status }}</small></td><td><div class="chat-actions"><button v-if="row.room_id" class="admin-secondary" @click="openRoom(row.room_id)">Review room</button><button v-if="row.room_id && !row.deleted_at" class="admin-secondary" @click="messageAction({id:row.message_id,room_id:row.room_id,display_name:row.display_name})">Delete</button><button v-if="row.sender_id && row.room_id" class="admin-secondary" @click="userAction('ban',row.sender_id,row.room_id,row.display_name)">Ban sender</button><button v-if="row.status==='pending'" class="admin-primary" @click="confirmAction({kind:'resolve',report:row.id,label:'Resolve report #'+row.id})">Resolve</button></div></td></tr></tbody></table>
                    <table class="chat-table" v-else-if="tab==='restrictions'"><thead><tr><th>Viewer</th><th>Restriction</th><th>Scope</th><th>Expires</th><th></th></tr></thead><tbody><tr v-for="row in pager.data" :key="row.id"><td>{{ row.display_name || row.user_id }}<small>{{ row.user_id }}</small></td><td>{{ row.kind }}</td><td>{{ Number(row.room_id)===0 ? 'All rooms' : row.room_key || '#'+row.room_id }}</td><td>{{ row.expires_at ? stamp(row.expires_at) : 'Indefinite' }}</td><td><button class="admin-secondary" @click="userAction('unban',row.user_id,row.room_id,row.display_name)">Remove restriction</button></td></tr></tbody></table>
                    <table class="chat-table" v-else><thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target / room</th><th>Reason</th></tr></thead><tbody><tr v-for="row in pager.data" :key="row.id"><td>{{ stamp(row.created_at) }}</td><td>{{ row.admin_id }}</td><td>{{ row.action }}</td><td>{{ row.target }}<small>Room {{ row.room_id }}</small></td><td>{{ row.reason }}</td></tr></tbody></table>
                    <div v-if="!pager.data.length" class="admin-empty">No {{ tab }} match these filters.</div>
                </section>
                <div class="chat-pagination"><button class="admin-secondary" :disabled="pager.current_page<=1" @click="load(pager.current_page-1)">Previous</button><span>{{ number(pager.total) }} records · Page {{ pager.current_page }} of {{ pager.last_page }}</span><button class="admin-secondary" :disabled="pager.current_page>=pager.last_page" @click="load(pager.current_page+1)">Next</button></div>
            </template>
            <section v-if="tab==='restrictions'" class="admin-panel chat-card"><h2>Restrict a viewer</h2><form class="chat-toolbar" @submit.prevent="manualRestriction('mute')"><label>Viewer ID<input v-model.trim="userIdInput" maxlength="191" required></label><label>Room ID · 0 = all rooms<input v-model="roomIdInput" type="number" min="0" required></label><button class="admin-secondary">Mute viewer</button><button class="admin-secondary" type="button" @click="manualRestriction('ban')">Ban viewer</button></form></section>
            <section v-if="selected || roomLoading || roomError" class="admin-panel chat-card">
                <header class="chat-room-header"><div><h2>{{ title }}</h2><p v-if="selected">{{ selected.room_key }} · {{ selected.closed ? 'Closed' : 'Open' }} · Admin review</p></div><div class="chat-actions"><button v-if="selected" class="admin-secondary" @click="roomAction">{{ selected.closed ? 'Open room' : 'Close room' }}</button><button v-if="selected" class="admin-secondary" :disabled="roomLoading" @click="history(selected.id)">Refresh history</button><button class="admin-secondary" @click="roomSeq++; selected=null; messages=[]; roomLoading=false; roomError=''">Close review</button></div></header>
                <StatusPanel tone="error" :message="roomError" />
                <form v-if="selected" class="chat-toolbar" @submit.prevent="history(selected.id)"><label>Search history<input v-model.trim="messageSearch" maxlength="200" placeholder="Message, viewer ID or name"></label><button class="admin-secondary" :disabled="roomLoading">Search</button></form>
                <div ref="transcript" class="chat-transcript" :aria-busy="roomLoading">
                    <button v-if="hasOlder && selected" class="admin-secondary" :disabled="roomLoading" @click="history(selected.id,true)">Load older messages</button>
                    <p v-if="roomLoading" role="status">Loading history…</p>
                    <p v-if="!roomLoading && !messages.length" class="admin-empty">No messages in this window.</p>
                    <article v-for="message in messages" :key="message.id" class="chat-message"><header><b>{{ message.display_name }}</b><small>{{ stamp(message.created_at) }} · #{{ message.id }}</small><span v-if="message.spoiler" class="admin-pill">Spoiler</span></header><small>{{ message.user_id }}</small><p class="chat-message-body">{{ message.deleted ? 'Message removed' : message.body }}</p><div v-if="!message.deleted" class="chat-actions"><button class="admin-secondary" @click="messageAction(message)">Delete</button><button class="admin-secondary" @click="userAction('mute',message.user_id,message.room_id,message.display_name)">Mute viewer</button><button class="admin-secondary" @click="userAction('ban',message.user_id,message.room_id,message.display_name)">Ban viewer</button></div></article>
                </div>
            </section>
        </template>
        <dialog ref="dialog" class="chat-dialog" @cancel.prevent="closeAction">
            <form v-if="action" @submit.prevent="applyAction"><h2>{{ action.label }}</h2><p v-if="action.kind!=='resolve'">{{ globalScope && ['mute','ban','unban'].includes(action.kind) ? 'Applies to every chat room.' : 'Room #'+action.scope }}</p><label v-if="['mute','ban','unban'].includes(action.kind)" class="chat-check"><input v-model="globalScope" type="checkbox" :disabled="action.scope===0">Apply to all rooms</label><label>Reason<textarea v-model="reason" required maxlength="500" rows="3" autofocus /></label><label v-if="['mute','ban'].includes(action.kind)">Expiry · optional<input v-model="expiry" type="datetime-local"><small>Leave blank for an indefinite restriction. Times use your browser timezone.</small></label><StatusPanel tone="error" :message="actionError" /><div class="chat-actions"><button class="admin-primary" :disabled="submitting || !reason.trim()">{{ submitting ? 'Saving…' : 'Confirm action' }}</button><button type="button" class="admin-secondary" :disabled="submitting" @click="closeAction">Cancel</button></div></form>
        </dialog>
    </div>
</template>
