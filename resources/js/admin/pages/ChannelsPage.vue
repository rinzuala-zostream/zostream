<script setup>
import { onBeforeUnmount, ref } from 'vue';
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
        <ChannelForm v-if="creating" :fields="channelFields" :busy="saving" title="Create channel" submit-label="Create channel" @save="create" @cancel="creating = false" />
        <form class="channel-filters admin-panel" @submit.prevent="load(1)">
            <label>Search<input v-model="search" type="search" placeholder="Channel name or slug"></label>
            <label>Status<select v-model="status"><option value="">All statuses</option><option v-for="state in ['pending', 'active', 'suspended', 'deleted']" :key="state">{{ state }}</option></select></label>
            <label>Owner UID<input v-model="owner" placeholder="Any owner"></label>
            <button class="admin-secondary" type="submit" :disabled="loading">Apply filters</button>
        </form>
        <ChannelTable :rows="rows" :columns="columns" :loading="loading" :page="page" :last-page="lastPage" :total="total" @page="load">
            <template #actions="{ row }"><RouterLink :to="`/channels/${row.id}`">Manage</RouterLink></template>
        </ChannelTable>
    </div>
</template>
<style scoped>
.channel-page :deep(.channel-form){margin-bottom:18px}.channel-filters{display:flex;align-items:end;gap:12px;padding:16px;margin-bottom:16px;flex-wrap:wrap}.channel-filters label{display:grid;gap:6px;flex:1;color:var(--a-muted);font-size:11px}.channel-filters input,.channel-filters select{min-height:43px;padding:10px;border:1px solid var(--a-line);border-radius:8px;background:var(--a-panel);color:var(--a-text);min-width:120px}
</style>
