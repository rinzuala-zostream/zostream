<script setup>
import { onMounted, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';
import { formatAdminDate } from '../lib/adminDate';

const polls = ref([]); const selected = ref(''); const voters = ref([]); const loading = ref(true); const error = ref('');
const page = ref(1); const lastPage = ref(1);
function rows(response) { const value = response?.data ?? response; return Array.isArray(value) ? value : value?.data || []; }
async function loadPolls() {
    const response = await api('/admin/polls?limit=100&per_page=100'); polls.value = rows(response);
    selected.value ||= String(polls.value[0]?.id || '');
}
async function loadVoters() {
    if (!selected.value) { voters.value = []; return; }
    loading.value = true; error.value = '';
    try { const response = await api(`/admin/polls/${selected.value}/voters${queryString({ page: page.value })}`); const value = response?.data ?? response; voters.value = rows(response); lastPage.value = value?.last_page || 1; }
    catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
watch(selected, () => { page.value = 1; loadVoters(); });
onMounted(async () => { try { await loadPolls(); await loadVoters(); } catch (reason) { error.value = reason.message; loading.value = false; } });
</script>

<template><div class="admin-page"><PageHeader eyebrow="Poll voters" title="Voter list" description="Choose a poll to review voter identity, contact, selected answer and timestamp." back="/manage/polls"/><StatusPanel tone="error" :message="error"/><section class="admin-list-toolbar"><label class="admin-search"><span>Poll</span><select v-model="selected"><option v-for="poll in polls" :key="poll.id" :value="String(poll.id)">{{poll.question || `Poll #${poll.id}`}}</option></select></label><button class="admin-secondary" @click="loadVoters">Refresh</button></section><section class="admin-table-card"><div v-if="loading" class="admin-loading">Loading voters…</div><div v-else-if="!voters.length" class="admin-empty-state"><h2>No votes yet</h2><p>Votes will appear here after users participate.</p></div><div v-else class="admin-table-scroll"><table><thead><tr><th>Vote</th><th>User</th><th>Email</th><th>Phone</th><th>Selected option</th><th>UID</th><th>Voted at</th></tr></thead><tbody><tr v-for="vote in voters" :key="vote.id"><td>#{{vote.id}}</td><td>{{vote.user?.name || 'Unknown user'}}</td><td>{{vote.user?.mail || '—'}}</td><td>{{vote.user?.call || vote.user?.auth_phone || '—'}}</td><td>{{vote.option?.option_text || '—'}}</td><td>{{vote.uid || vote.user?.uid || '—'}}</td><td>{{formatAdminDate(vote.created_at, true)}}</td></tr></tbody></table></div><footer v-if="lastPage > 1" class="admin-pagination"><button :disabled="page <= 1" @click="page--;loadVoters()">Previous</button><span>Page {{page}} / {{lastPage}}</span><button :disabled="page >= lastPage" @click="page++;loadVoters()">Next</button></footer></section></div></template>
