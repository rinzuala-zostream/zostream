<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import ChannelForm from '../components/channels/ChannelForm.vue';
import ChannelTable from '../components/channels/ChannelTable.vue';
import { api, queryString } from '../lib/api';
import { channelFields, planFields, contentFields, mediaFields, ppvFields } from '../lib/channels';

const route = useRoute(); const router = useRouter();
const channel = ref(null); const content = ref(null); const editor = ref(null); const mediaEditor = ref(null);
const loading = ref(false); const saving = ref(false); const contentLoading = ref(false);
const error = ref(''); const notice = ref(''); const rows = ref([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const filter = ref(''); const userFilter = ref(''); const history = ref(null); const historyLoading = ref(false);
const id = computed(() => encodeURIComponent(route.params.id));
const tabs = ['profile', 'plans', 'contents', 'subscribers', 'history'];
const tab = computed(() => tabs.includes(route.query.tab) ? route.query.tab : 'profile');
const base = computed(() => `/admin/channels/${id.value}`);
let generation = 0; let contentGeneration = 0; let historyGeneration = 0;
const endpoint = computed(() => ({ plans: 'plans', contents: 'contents', subscribers: 'subscribers', history: 'subscription-history' })[tab.value]);
const columns = computed(() => ({
    plans: [{ key: 'name', label: 'Plan' }, { key: 'duration_days', label: 'Days' }, { key: 'price', label: 'Price', money: true }, { key: 'discount_percent', label: 'Discount %' }, { key: 'final_price', label: 'Final price', money: true }, { key: 'is_active', label: 'Active' }],
    contents: [{ key: 'title', label: 'Title' }, { key: 'content_type', label: 'Type' }, { key: 'access_type', label: 'Access' }, { key: 'status', label: 'Visibility' }, { key: 'release_date', label: 'Release', date: true }],
    subscribers: [{ key: 'user.name', label: 'Member' }, { key: 'user_id', label: 'User UID' }, { key: 'plan.name', label: 'Plan' }, { key: 'status', label: 'Stored status' }, { key: 'expires_at', label: 'Expires', date: true }],
    history: [{ key: 'user.name', label: 'Member' }, { key: 'user_id', label: 'User UID' }, { key: 'amount', label: 'Amount', money: true }, { key: 'transaction_id', label: 'Transaction' }, { key: 'payment_method', label: 'Method' }, { key: 'status', label: 'Status' }, { key: 'start_date', label: 'Starts', date: true }, { key: 'end_date', label: 'Ends', date: true }],
})[tab.value] || []);
const mediaColumns = [{ key: 'media_type', label: 'Type' }, { key: 'quality', label: 'Quality' }, { key: 'language', label: 'Language' }, { key: 'url', label: 'URL' }, { key: 'file_size', label: 'Bytes' }];
const historyColumns = [{ key: 'user.name', label: 'Member' }, { key: 'user_id', label: 'User UID' }, { key: 'amount', label: 'Amount', money: true }, { key: 'status', label: 'Status' }, { key: 'transaction_id', label: 'Transaction' }, { key: 'expires_at', label: 'Expires', date: true }];
const editorFields = computed(() => editor.value?.type === 'plan' ? planFields : contentFields);
const filterOptions = computed(() => ({ contents: ['draft', 'published', 'private'], subscribers: ['active', 'expired', 'cancelled'] })[tab.value] || []);

async function load(nextPage = 1) {
    const current = ++generation;
    loading.value = true; error.value = ''; rows.value = [];
    try {
        const record = await api(base.value);
        if (current !== generation) return;
        channel.value = record;
        if (endpoint.value) {
            const data = await api(`${base.value}/${endpoint.value}${queryString({ page: nextPage, per_page: 20, status: filter.value, user_id: tab.value === 'history' ? userFilter.value.trim() : '' })}`);
            if (current !== generation) return;
            rows.value = data.data || []; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total;
        }
    } catch (reason) { if (current === generation) error.value = reason.message; }
    finally { if (current === generation) loading.value = false; }
}
function changeTab(next) { router.replace({ query: { tab: next } }); }
async function mutate(action, message) {
    if (saving.value) return;
    saving.value = true; error.value = ''; notice.value = '';
    try { await action(); notice.value = message; }
    catch (reason) { error.value = reason.message; }
    finally { saving.value = false; }
}
async function saveProfile(body) {
    await mutate(async () => { channel.value = await api(base.value, { method: 'PUT', body }); }, 'Channel profile saved.');
}
async function removeChannel() {
    if (!confirm(`Remove ${channel.value.name} from public discovery? Records will be retained.`)) return;
    await mutate(async () => { channel.value = await api(base.value, { method: 'DELETE' }); }, 'Channel removed from public discovery.');
}
function openEditor(type, record = {}) {
    editor.value = { type, record: { ...record } }; content.value = null; contentGeneration++;
    contentLoading.value = false; mediaEditor.value = null; history.value = null; historyGeneration++; historyLoading.value = false;
}
async function saveRecord(body) {
    const selected = editor.value;
    const collection = selected.type === 'plan' ? 'plans' : 'contents';
    const recordEndpoint = selected.type === 'plan' ? 'channel-plans' : 'channel-contents';
    await mutate(async () => {
        const saved = await api(selected.record.id ? `/admin/${recordEndpoint}/${selected.record.id}` : `${base.value}/${collection}`, { method: selected.record.id ? 'PUT' : 'POST', body });
        editor.value = null;
        await load(page.value);
        if (collection === 'contents') await openContent(saved);
    }, selected.type === 'plan' ? 'Plan saved.' : 'Content saved.');
}
async function removeRecord(row, type) {
    if (!confirm(type === 'plan' ? 'Deactivate this plan? Existing memberships are retained.' : 'Withdraw this content? Media and history are retained.')) return;
    await mutate(async () => {
        await api(`/admin/${type === 'plan' ? 'channel-plans' : 'channel-contents'}/${row.id}`, { method: 'DELETE' });
        editor.value = null; content.value = null; contentGeneration++;
        await load(page.value);
    }, type === 'plan' ? 'Plan deactivated.' : 'Content withdrawn.');
}
async function openContent(row) {
    const current = ++contentGeneration;
    contentLoading.value = true; content.value = null; editor.value = null; mediaEditor.value = null; history.value = null; historyGeneration++; historyLoading.value = false; error.value = '';
    try {
        const record = await api(`/admin/channel-contents/${row.id}`);
        if (current !== contentGeneration) return;
        if (String(record.channel_id) !== String(route.params.id)) throw new Error('This content belongs to another channel.');
        content.value = record;
    } catch (reason) { if (current === contentGeneration) error.value = reason.message; }
    finally { if (current === contentGeneration) contentLoading.value = false; }
}
async function saveMedia(body) {
    const selected = mediaEditor.value; const videoId = content.value.id;
    await mutate(async () => {
        await api(selected.id ? `/admin/channel-media/${selected.id}` : `/admin/channel-contents/${videoId}/media`, { method: selected.id ? 'PUT' : 'POST', body });
        await openContent({ id: videoId });
    }, 'Media saved.');
}
async function removeMedia(row) {
    if (!confirm('Remove this media reference?')) return;
    const videoId = content.value.id;
    await mutate(async () => { await api(`/admin/channel-media/${row.id}`, { method: 'DELETE' }); await openContent({ id: videoId }); }, 'Media reference removed.');
}
async function savePpv(body) {
    const videoId = content.value.id;
    await mutate(async () => { await api(`/admin/channel-contents/${videoId}/ppv`, { method: 'PUT', body }); await openContent({ id: videoId }); }, 'Rental settings saved.');
}
async function removePpv() {
    if (!confirm('Remove rental settings?')) return;
    const videoId = content.value.id;
    await mutate(async () => { await api(`/admin/channel-contents/${videoId}/ppv`, { method: 'DELETE' }); await openContent({ id: videoId }); }, 'Rental settings removed.');
}
async function revoke(row) {
    if (!confirm(`Revoke channel access for ${row.user?.name || row.user_id} immediately?`)) return;
    await mutate(async () => { await api(`${base.value}/subscribers/${encodeURIComponent(row.user_id)}`, { method: 'DELETE' }); await load(page.value); }, 'Membership access revoked.');
}
async function loadRentalHistory(nextPage = 1) {
    const current = ++historyGeneration; const videoId = content.value.id;
    historyLoading.value = true; error.value = '';
    try {
        const data = await api(`/admin/channel-contents/${videoId}/rental-history${queryString({ page: nextPage, per_page: 20 })}`);
        if (current === historyGeneration) history.value = data;
    } catch (reason) { if (current === historyGeneration) error.value = reason.message; }
    finally { if (current === historyGeneration) historyLoading.value = false; }
}
async function cancelRental(row) {
    if (!confirm(`Cancel the current rental for ${row.user?.name || row.user_id}?`)) return;
    const videoId = content.value.id;
    await mutate(async () => {
        await api(`/admin/channel-contents/${videoId}/rentals/${encodeURIComponent(row.user_id)}`, { method: 'DELETE' });
        await loadRentalHistory(history.value?.current_page || 1);
    }, 'Rental cancelled.');
}
// Finish an in-flight write before changing the channel context.
onBeforeRouteLeave(() => !saving.value);
onBeforeRouteUpdate(() => !saving.value);
watch(() => [route.params.id, tab.value], () => {
    channel.value = null; editor.value = null; content.value = null; mediaEditor.value = null; history.value = null;
    contentGeneration++; historyGeneration++; contentLoading.value = false; historyLoading.value = false;
    filter.value = ''; userFilter.value = ''; notice.value = ''; page.value = 1;
    load();
}, { immediate: true });
onBeforeUnmount(() => { generation++; contentGeneration++; historyGeneration++; });
</script>

<template>
    <div class="admin-page channel-detail">
        <PageHeader eyebrow="Channel management" :title="channel?.name || 'Channel'" description="Manage profile, plans, content and audience." back="/channels">
            <button class="admin-secondary" type="button" :disabled="loading || saving" @click="load(page)">Refresh</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" /><StatusPanel tone="success" :message="notice" />
        <nav class="channel-tabs" aria-label="Channel sections"><button v-for="name in tabs" :key="name" type="button" :class="{ active: tab === name }" :aria-pressed="tab === name" :disabled="saving" @click="changeTab(name)">{{ name === 'history' ? 'Payment history' : name }}</button></nav>
        <p class="channel-hint">New channel purchases are currently unavailable. Existing memberships and payment history remain available.</p>
        <div v-if="loading" class="admin-loading">Loading channel…</div>
        <template v-else-if="channel">
            <template v-if="tab === 'profile'">
                <ChannelForm :key="channel.updated_at" :fields="channelFields" :record="channel" :busy="saving" title="Channel profile" @save="saveProfile" @cancel="router.push('/channels')" />
                <button class="admin-danger channel-remove" type="button" :disabled="saving || channel.status === 'deleted'" @click="removeChannel">Remove channel from discovery</button>
            </template>
            <template v-else>
                <div class="channel-controls">
                    <button v-if="['plans', 'contents'].includes(tab)" class="admin-primary" type="button" :disabled="saving" @click="openEditor(tab === 'plans' ? 'plan' : 'content')">{{ tab === 'plans' ? 'Add plan' : 'Add content' }}</button>
                    <form v-if="filterOptions.length || tab === 'history'" class="channel-filter" @submit.prevent="load(1)">
                        <label v-if="filterOptions.length">Status<select v-model="filter"><option value="">All statuses</option><option v-for="state in filterOptions" :key="state">{{ state }}</option></select></label>
                        <label v-else>User UID<input v-model="userFilter" placeholder="Any member"></label>
                        <button class="admin-secondary" type="submit" :disabled="saving">Apply</button>
                    </form>
                </div>
                <ChannelForm v-if="editor" :key="`${editor.type}-${editor.record.id || 'new'}`" :fields="editorFields" :record="editor.record" :busy="saving" :title="`${editor.record.id ? 'Edit' : 'Add'} ${editor.type}`" @save="saveRecord" @cancel="editor = null" />
                <ChannelTable :rows="rows" :columns="columns" :page="page" :last-page="lastPage" :total="total" @page="load">
                    <template v-if="tab !== 'history'" #actions="{ row }">
                        <template v-if="tab === 'plans'"><button type="button" :disabled="saving" @click="openEditor('plan', row)">Edit</button><button type="button" :disabled="saving || !row.is_active" @click="removeRecord(row, 'plan')">Deactivate</button></template>
                        <template v-if="tab === 'contents'"><button type="button" :disabled="saving" @click="openContent(row)">Media & details</button><button type="button" :disabled="saving" @click="openEditor('content', row)">Edit</button><button type="button" :disabled="saving" @click="removeRecord(row, 'content')">Withdraw</button></template>
                        <button v-if="tab === 'subscribers'" type="button" :disabled="saving || row.status === 'expired'" @click="revoke(row)">Revoke access</button>
                    </template>
                </ChannelTable>
                <div v-if="contentLoading" class="admin-loading">Loading content details…</div>
                <section v-if="content" class="channel-content-detail">
                    <header class="channel-controls"><h2>{{ content.title || `Content ${content.id}` }}</h2><button class="admin-secondary" type="button" :disabled="saving" @click="content = null">Close details</button></header>
                    <h3>Media</h3><button class="admin-secondary" type="button" :disabled="saving" @click="mediaEditor = {}">Attach media</button>
                    <ChannelForm v-if="mediaEditor" :key="mediaEditor.id || 'new-media'" :fields="mediaFields" :record="mediaEditor" :busy="saving" title="Media reference" @save="saveMedia" @cancel="mediaEditor = null" />
                    <ChannelTable :rows="content.media || []" :columns="mediaColumns"><template #actions="{ row }"><button type="button" :disabled="saving" @click="mediaEditor = { ...row }">Edit</button><button type="button" :disabled="saving" @click="removeMedia(row)">Remove</button></template></ChannelTable>
                    <template v-if="content.access_type === 'ppv' || content.ppv">
                        <ChannelForm :key="`ppv-${content.id}-${content.ppv?.updated_at || 'new'}`" :fields="ppvFields" :record="content.ppv || {}" :busy="saving" title="Rental settings" @save="savePpv" @cancel="openContent(content)" />
                        <button v-if="content.ppv" class="admin-danger" type="button" :disabled="saving" @click="removePpv">Remove rental settings</button>
                    </template>
                    <div class="channel-controls"><h3>Rental history</h3><button class="admin-secondary" type="button" :disabled="historyLoading || saving" @click="loadRentalHistory(1)">Load history</button></div>
                    <ChannelTable v-if="history || historyLoading" :rows="history?.data || []" :columns="historyColumns" :loading="historyLoading" :page="history?.current_page || 1" :last-page="history?.last_page || 1" :total="history?.total" @page="loadRentalHistory">
                        <template #actions="{ row }"><button type="button" :disabled="saving" @click="cancelRental(row)">Cancel current rental</button></template>
                    </ChannelTable>
                </section>
            </template>
        </template>
    </div>
</template>
<style scoped>
.channel-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.channel-tabs button{padding:11px 16px;border:1px solid var(--a-line);border-radius:10px;color:var(--a-muted);background:transparent;text-transform:capitalize}.channel-tabs button.active{color:var(--a-cyan);border-color:var(--a-cyan);background:var(--a-panel)}.channel-hint{color:var(--a-muted);font-size:12px;line-height:1.6;margin-bottom:20px}.channel-controls{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:16px 0}.channel-filter{display:flex;align-items:end;gap:10px}.channel-filter label{display:grid;gap:5px;font-size:11px;color:var(--a-muted)}.channel-filter input,.channel-filter select{padding:10px;min-height:43px;border:1px solid var(--a-line);border-radius:8px;background:var(--a-panel);color:var(--a-text)}.channel-detail :deep(.channel-form){margin:16px 0}.channel-content-detail{margin-top:24px;padding-top:16px;border-top:1px solid var(--a-line)}h2{font-size:18px}h3{font-size:14px}.channel-remove{margin-top:16px}button:disabled{opacity:.5}
</style>
