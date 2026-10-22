<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';
import { formatAdminDate } from '../lib/adminDate';
const route = useRoute(); const loading = ref(true); const error = ref(''); const results = ref({}); const voters = ref([]); const view = ref('results');
const poll = computed(() => results.value.poll || {}); const total = computed(() => Number(results.value.total_votes || 0));
const leadingOption = computed(() => [...(poll.value.options || [])].sort((a, b) => Number(b.votes_count || 0) - Number(a.votes_count || 0))[0] || null);
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
function tiltSection(event) {
    const target = event.currentTarget;
    const bounds = target.getBoundingClientRect();
    const x = (event.clientX - bounds.left) / bounds.width;
    const y = (event.clientY - bounds.top) / bounds.height;
    target.style.setProperty('--poll-tilt-x', `${((0.5 - y) * 0.75).toFixed(2)}deg`);
    target.style.setProperty('--poll-tilt-y', `${((x - 0.5) * 0.75).toFixed(2)}deg`);
    target.style.setProperty('--poll-edge-x', `${(x * 100).toFixed(1)}%`);
    target.style.setProperty('--poll-edge-y', `${(y * 100).toFixed(1)}%`);
}
function resetTilt(event) {
    event.currentTarget.style.removeProperty('--poll-tilt-x');
    event.currentTarget.style.removeProperty('--poll-tilt-y');
    event.currentTarget.style.removeProperty('--poll-edge-x');
    event.currentTarget.style.removeProperty('--poll-edge-y');
}
onMounted(load);
</script>
<template>
    <div class="admin-page narrow admin-poll-insights">
        <PageHeader eyebrow="Poll analytics" :title="poll.question || 'Poll results'" :description="`${total} recorded vote${total === 1 ? '' : 's'} · ${poll.options?.length || 0} answer options`" back="/manage/polls">
            <button class="admin-secondary" :disabled="loading" @click="load">↻ Refresh</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <div class="admin-poll-view-switch" role="tablist" aria-label="Poll analytics view">
            <button :class="{ active: view === 'results' }" role="tab" :aria-selected="view === 'results'" @click="view = 'results'">Results <span>{{ poll.options?.length || 0 }}</span></button>
            <button :class="{ active: view === 'voters' }" role="tab" :aria-selected="view === 'voters'" @click="view = 'voters'">Voters <span>{{ total }}</span></button>
        </div>
        <div v-if="loading" class="admin-loading">Loading poll activity…</div>
        <template v-else-if="view === 'results'">
            <section v-if="total && leadingOption" class="admin-poll-leader admin-poll-tilt" @pointermove="tiltSection" @pointerleave="resetTilt">
                <div class="admin-poll-leader-icon">✦</div>
                <div><span>LEADING ANSWER</span><h2>{{ leadingOption.option_text }}</h2><p>{{ leadingOption.votes_count || 0 }} votes · {{ percent(leadingOption.votes_count) }}% of all responses</p></div>
                <strong>{{ percent(leadingOption.votes_count) }}<small>%</small></strong>
            </section>
            <section class="admin-stat-grid admin-poll-summary">
                <article class="admin-poll-tilt" @pointermove="tiltSection" @pointerleave="resetTilt"><p>Total votes</p><strong>{{ total }}</strong><span>Recorded responses</span></article>
                <article class="admin-poll-tilt" @pointermove="tiltSection" @pointerleave="resetTilt"><p>Answer options</p><strong>{{ poll.options?.length || 0 }}</strong><span>Available choices</span></article>
                <article class="admin-poll-tilt" @pointermove="tiltSection" @pointerleave="resetTilt"><p>Poll status</p><strong>{{ total ? 'Active' : 'Waiting' }}</strong><span>{{ total ? 'Responses received' : 'No votes recorded yet' }}</span></article>
            </section>
            <section class="admin-panel admin-poll-results admin-poll-tilt" @pointermove="tiltSection" @pointerleave="resetTilt">
                <header><div><p>RESULT BREAKDOWN</p><h2>How people voted</h2></div><strong>{{ total }} <small>total votes</small></strong></header>
                <div class="admin-poll-result-bars">
                    <article v-for="(option, index) in poll.options || []" :key="option.id" :class="{ leader: option.id === leadingOption?.id && total > 0 }">
                        <div class="admin-poll-option-heading"><span class="admin-poll-option-index">{{ String(index + 1).padStart(2, '0') }}</span><b>{{ option.option_text }}</b><strong>{{ percent(option.votes_count) }}%</strong></div>
                        <div class="admin-poll-meter"><i :style="`width:${percent(option.votes_count)}%`" /></div>
                        <small>{{ option.votes_count || 0 }} {{ Number(option.votes_count || 0) === 1 ? 'vote' : 'votes' }}</small>
                    </article>
                    <p v-if="!poll.options?.length" class="admin-empty">No answer options are available.</p>
                </div>
            </section>
        </template>
        <section v-else class="admin-panel admin-voter-list admin-poll-tilt" @pointermove="tiltSection" @pointerleave="resetTilt">
            <header><div><p>VOTER ACTIVITY</p><h2>Recent participation</h2><span>Latest voter records for this poll.</span></div><strong>{{ voters.length }} <small>shown</small></strong></header>
            <div v-if="voters.length" class="admin-table-scroll"><table><thead><tr><th>User</th><th>Selected answer</th><th>Voted at</th></tr></thead><tbody>
                <tr v-for="vote in voters" :key="vote.id"><td><b>{{ vote.user?.name || vote.uid || vote.user_id || vote.user?.uid || 'Unknown user' }}</b><small>{{ vote.user?.mail || vote.user?.call || '' }}</small></td><td><span class="admin-poll-vote-answer">{{ vote.option?.option_text || vote.poll_option_id || '—' }}</span></td><td>{{ formatAdminDate(vote.created_at || vote.updated_at, true) }}</td></tr>
            </tbody></table></div>
            <p v-else class="admin-empty">No voters recorded yet. Voter activity will appear here after people participate.</p>
        </section>
    </div>
</template>
