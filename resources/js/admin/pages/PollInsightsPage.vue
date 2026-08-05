<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';
const route = useRoute(); const loading = ref(true); const error = ref(''); const results = ref({}); const voters = ref([]);
const poll = computed(() => results.value.poll || {}); const total = computed(() => Number(results.value.total_votes || 0));
async function load() {
    loading.value = true; error.value = '';
    try {
        const [resultResponse, voterResponse] = await Promise.all([api(`/admin/polls/${route.params.id}/results`), api(`/admin/polls/${route.params.id}/voters`)]);
        results.value = resultResponse?.data || resultResponse || {};
        const rawVoters = voterResponse?.data || voterResponse || [];
        voters.value = Array.isArray(rawVoters) ? rawVoters : rawVoters.data || [];
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
const percent = (votes) => total.value ? Math.round((Number(votes || 0) / total.value) * 100) : 0;
onMounted(load);
</script>
<template><div class="admin-page narrow"><PageHeader eyebrow="Poll analytics" :title="poll.question || 'Poll results'" :description="`${total} recorded vote${total === 1 ? '' : 's'}.`" back="/manage/polls"><button class="admin-secondary" @click="load">Refresh</button></PageHeader><StatusPanel tone="error" :message="error"/><div v-if="loading" class="admin-loading">Loading results…</div><template v-else><section class="admin-panel admin-poll-results"><article v-for="option in poll.options || []" :key="option.id"><header><span>{{option.option_text}}</span><strong>{{percent(option.votes_count)}}%</strong></header><div><i :style="`width:${percent(option.votes_count)}%`"/></div><small>{{option.votes_count || 0}} votes</small></article></section><section class="admin-panel admin-voter-list"><header><div><p>VOTERS</p><h2>Recent participation</h2></div></header><div v-if="voters.length" class="admin-table-scroll"><table><thead><tr><th>User</th><th>Option</th><th>Voted at</th></tr></thead><tbody><tr v-for="vote in voters" :key="vote.id"><td>{{vote.uid || vote.user_id}}</td><td>{{vote.option?.option_text || vote.poll_option_id}}</td><td>{{vote.created_at || vote.updated_at}}</td></tr></tbody></table></div><p v-else class="admin-empty">No voters recorded.</p></section></template></div></template>
