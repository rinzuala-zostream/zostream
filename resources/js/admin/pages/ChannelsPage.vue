<script setup>
import UserLookup from '../components/UserLookup.vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import ChannelForm from '../components/channels/ChannelForm.vue';
import ChannelTable from '../components/channels/ChannelTable.vue';
import { api, queryString } from '../lib/api';
import { channelFields } from '../lib/channels';

const router = useRouter();
const search = ref(''); const status = ref(''); const owner = ref('');
const rows = ref([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const saving = ref(false); const creating = ref(false); const error = ref('');
let generation = 0;
const pageCounts = computed(() => ({ active: rows.value.filter(row => row.status === 'active').length, pending: rows.value.filter(row => row.status === 'pending').length }));
function clearFilters() { search.value = ''; status.value = ''; owner.value = ''; load(1); }
const columns = [{ key: 'name', label: 'Channel' }, { key: 'user_id', label: 'Owner UID' }, { key: 'status', label: 'Status' }, { key: 'is_verified', label: 'Verified' }, { key: 'plans_count', label: 'Plans' }, { key: 'contents_count', label: 'Content' }, { key: 'subscribers_count', label: 'Subscribers' }];
async function load(nextPage = 1) {
    const current = ++generation;
    loading.value = true; error.value = '';
    try {
        const data = await api(`/admin/channels${queryString({ search: search.value.trim(), status: status.value, user_id: owner.value.trim(), page: nextPage, per_page: 20 })}`);
        if (current !== generation) return;
        rows.value = data.data || []; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total;
    } catch (reason) { if (current === generation) { error.value = reason.message; rows.value = []; } }
    finally { if (current === generation) loading.value = false; }
}
async function create(body) {
    if (saving.value) return;
    saving.value = true; error.value = '';
    try {
        const channel = await api('/admin/channels', { method: 'POST', body });
        await router.push(`/channels/${channel.id}`);
    } catch (reason) { error.value = reason.message; }
    finally { saving.value = false; }
}
onBeforeUnmount(() => generation++);
load();
</script>
<template>
    <div class="admin-page channel-page">
        <PageHeader eyebrow="Content" title="Channels" description="Manage channel owners, membership plans and content.">
            <a class="admin-secondary" href="/creator" target="_blank" rel="noopener">Open Creator Studio</a>
            <button class="admin-primary" type="button" :disabled="saving" @click="creating = !creating">{{ creating ? 'Close form' : 'Create channel' }}</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <div class="channel-overview" aria-label="Channel results"><article><span>Matching channels</span><strong>{{ loading ? '…' : total }}</strong><small>Across all result pages</small></article><article><span>Active on this page</span><strong>{{ loading ? '…' : pageCounts.active }}</strong><small>Visible in the public catalog</small></article><article><span>Pending on this page</span><strong>{{ loading ? '…' : pageCounts.pending }}</strong><small>Awaiting channel activation</small></article></div>
        <ChannelForm v-if="creating" :fields="channelFields" :busy="saving" title="Create channel" submit-label="Create channel" @save="create" @cancel="creating = false" />
        <form class="channel-filters admin-panel" @submit.prevent="load(1)">
            <label>Search<input v-model="search" type="search" placeholder="Channel name or slug"></label>
            <label>Status<select v-model="status"><option value="">All statuses</option><option v-for="state in ['pending', 'active', 'suspended', 'deleted']" :key="state">{{ state }}</option></select></label>
            <label>Owner UID<UserLookup v-model="owner" /></label>
            <button class="admin-secondary" type="submit" :disabled="loading">Apply filters</button>
            <button v-if="search || status || owner" class="admin-secondary" type="button" :disabled="loading" @click="clearFilters">Clear filters</button>
        </form>
        <ChannelTable :rows="rows" :columns="columns" :loading="loading" :page="page" :last-page="lastPage" :total="total" empty-title="No channels to display" empty-description="Create your first channel and assign its owner, or clear the filters to see more results." @page="load">
            <template #cell-name="{ row }"><div class="channel-list-identity"><span class="channel-list-avatar">{{ (row.name || 'C').slice(0, 1) }}</span><div><RouterLink :to="`/channels/${row.id}`">{{ row.name }}</RouterLink><small>/{{ row.slug }}</small></div></div></template>
            <template #actions="{ row }"><RouterLink :to="`/channels/${row.id}`">Manage</RouterLink></template>
        </ChannelTable>
    </div>
</template>
<style scoped>
.channel-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:24px}.channel-overview article{padding:22px;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel);display:grid;gap:10px}.channel-overview span{font-size:12px;color:var(--a-muted)}.channel-overview strong{font-size:30px;font-weight:650}.channel-overview small{font-size:11px;color:var(--a-muted)}.channel-list-identity{display:flex;align-items:center;gap:12px}.channel-list-avatar{display:grid;place-items:center;width:40px;height:40px;flex-shrink:0;background:rgba(53,213,208,.1);border-radius:12px;color:var(--a-cyan);font-weight:700}.channel-list-identity a{color:var(--a-text);text-decoration:none;font-weight:600}.channel-list-identity a:hover{color:var(--a-cyan)}.channel-list-identity small{display:block;margin-top:5px;color:var(--a-muted);font-size:11px}@media(max-width:650px){.channel-overview{grid-template-columns:1fr}.channel-overview article{padding:16px}}

.channel-page :deep(.channel-form){margin-bottom:18px}.channel-filters{display:flex;align-items:end;gap:12px;padding:16px;margin-bottom:16px;flex-wrap:wrap}.channel-filters label{display:grid;gap:6px;flex:1;color:var(--a-muted);font-size:11px}.channel-filters input,.channel-filters select{min-height:43px;padding:10px;border:1px solid var(--a-line);border-radius:8px;background:var(--a-panel);color:var(--a-text);min-width:120px}
</style>
