<script setup>
import { onMounted, ref, watch } from 'vue';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';
import { formatAdminDate } from '../lib/adminDate';

const items = ref([]); const counts = ref({}); const pagination = ref({ current_page: 1, last_page: 1 });
const loading = ref(false); const error = ref(''); const status = ref('pending_review'); const search = ref('');
let debounce;
const filters = [
    ['pending_review', 'Pending'], ['changes_requested', 'Changes requested'],
    ['approved', 'Approved'], ['rejected', 'Rejected'], ['', 'All'],
];
const labels = Object.fromEntries(filters);

async function load(page = 1) {
    loading.value = true; error.value = '';
    try {
        const response = await api(`/admin/ad-submissions${queryString({ status: status.value, search: search.value.trim(), page, per_page: 20 })}`);
        items.value = response.items || []; counts.value = response.counts || {}; pagination.value = response.pagination || { current_page: 1, last_page: 1 };
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
watch(status, () => load(1));
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 350); });
onMounted(load);
</script>

<template>
    <div class="admin-page ad-admin-list">
        <PageHeader eyebrow="Advertising" title="Ad submissions" description="Review advertiser campaigns before they are published.">
            <a class="admin-secondary" href="/advertise" target="_blank">Open public form ↗</a>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <section class="ad-review-stats">
            <button v-for="([value, label]) in filters" :key="value || 'all'" :class="{ active: status === value }" @click="status = value"><span>{{ label }}</span><b>{{ value ? (counts[value] || 0) : Object.values(counts).reduce((sum, count) => sum + Number(count), 0) }}</b></button>
        </section>
        <section class="admin-list-toolbar"><label class="admin-search"><AdminIcon name="search" /><input v-model="search" placeholder="Reference, business, contact or ad title…"></label><button class="admin-icon-button" aria-label="Refresh" @click="load(pagination.current_page)">↻</button></section>
        <section class="admin-table-card">
            <div v-if="loading" class="admin-loading">Loading ad submissions…</div>
            <div v-else-if="!items.length" class="admin-empty-state"><AdminIcon name="image" /><h2>No submissions found</h2><p>This review queue is empty.</p></div>
            <div v-else class="admin-table-scroll"><table><thead><tr><th>Reference</th><th>Advertiser</th><th>Campaign</th><th>Type</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="item in items" :key="item.id"><td><b>{{ item.reference_no }}</b></td><td>{{ item.business_name }}<small>{{ item.contact_name }} · {{ item.contact_phone }}</small></td><td>{{ item.ads_name }}<small>{{ item.requested_period_days }} days</small></td><td>{{ item.type }}</td><td>{{ formatAdminDate(item.created_at) }}</td><td><span class="admin-pill" :class="`is-${item.status}`">{{ labels[item.status] || item.status }}</span></td><td><RouterLink class="ad-review-link" :to="`/ads/submissions/${item.id}`">Review →</RouterLink></td></tr></tbody></table></div>
            <footer v-if="pagination.last_page > 1" class="admin-pagination"><button :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</button><span>Page {{ pagination.current_page }} / {{ pagination.last_page }}</span><button :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</button></footer>
        </section>
    </div>
</template>

<style scoped>
.ad-review-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}.ad-review-stats button{display:flex;min-height:78px;align-items:center;justify-content:space-between;padding:15px;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel);color:var(--a-muted);text-align:left}.ad-review-stats button:hover,.ad-review-stats button.active{border-color:rgba(53,213,208,.34);background:rgba(53,213,208,.07);color:var(--a-text)}.ad-review-stats span{font-size:11px;font-weight:700}.ad-review-stats b{color:var(--a-cyan);font:750 24px 'Manrope',sans-serif}.admin-table-card td small{display:block;margin-top:5px;color:var(--a-muted);font-size:10px}.ad-review-link{display:inline-flex;min-height:34px;align-items:center;padding:0 11px;border:1px solid rgba(53,213,208,.22);border-radius:8px;color:var(--a-cyan);font-size:11px;font-weight:800}.admin-pill.is-approved{background:rgba(178,255,75,.1);color:var(--a-lime)}.admin-pill.is-rejected{background:rgba(255,115,123,.11);color:var(--a-red)}.admin-pill.is-changes_requested{background:rgba(255,196,80,.1);color:#ffd071}@media(max-width:900px){.ad-review-stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.ad-review-stats{grid-template-columns:1fr 1fr}.ad-review-stats button:last-child{grid-column:1/-1}}
</style>
