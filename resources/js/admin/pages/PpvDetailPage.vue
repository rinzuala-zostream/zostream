<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const route = useRoute();
const detail = ref(null); const purchases = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const loading = ref(true); const purchaseLoading = ref(false); const error = ref(''); const purchaseError = ref('');
const typeLabel = computed(() => ({ movie: 'Movie', season: 'Season', episode: 'Episode' }[route.params.type] || 'PPV item'));
const item = computed(() => detail.value?.item || {});
const money = (value, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency: currency || 'INR', maximumFractionDigits: 2 }).format(Number(value) || 0);

async function loadPurchases(page = 1) {
    purchaseLoading.value = true; purchaseError.value = '';
    try {
        const ids = (detail.value?.purchase_content_ids || []).join(',');
        const query = new URLSearchParams({ page: String(page), per_page: '20', content_ids: ids });
        purchases.value = (await api(`/admin/catalog/ppv/purchases?${query}`, { cache: 'no-store' })).data;
    } catch (reason) { purchaseError.value = reason.message || 'Could not load purchase history.'; }
    finally { purchaseLoading.value = false; }
}
async function load() {
    loading.value = true; error.value = ''; detail.value = null;
    try { detail.value = (await api(`/admin/catalog/ppv/${route.params.type}/${encodeURIComponent(route.params.id)}`, { cache: 'no-store' })).data; await loadPurchases(1); }
    catch (reason) { error.value = reason.message || 'Could not load PPV item details.'; }
    finally { loading.value = false; }
}
onMounted(load);
watch(() => [route.params.type, route.params.id], load);
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Content · PPV" :title="item.title || `${typeLabel} details`" description="PPV item information and paginated purchase history.">
            <RouterLink class="admin-secondary" to="/ppv">Back to PPV</RouterLink>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-loading">Loading PPV item…</div>
        <template v-else-if="detail">
            <section class="ppv-detail-card">
                <div class="ppv-detail-heading"><img v-if="item.poster || item.thumbnail || item.thumbnail_url" :src="item.poster || item.thumbnail || item.thumbnail_url" :alt="item.title || typeLabel"><div><span>{{ typeLabel }} · Pay per view</span><h2>{{ item.title || `${typeLabel} #${item.num || item.id}` }}</h2><p v-if="item.description || item.desc">{{ item.description || item.desc }}</p></div><b>{{ money(item.ppv_amount ?? item.amount) }}</b></div>
                <dl>
                    <div><dt>Content ID</dt><dd>{{ item.num || item.id || '—' }}</dd></div>
                    <div v-if="route.params.type === 'season'"><dt>Season</dt><dd>{{ item.season_number || '—' }}</dd></div>
                    <div v-if="route.params.type === 'season'"><dt>PPV episodes</dt><dd>{{ item.ppv_episode_count || 0 }}</dd></div>
                    <div v-if="route.params.type === 'episode'"><dt>Episode</dt><dd>{{ item.episode_number || '—' }}</dd></div>
                    <div v-if="item.genre"><dt>Genre</dt><dd>{{ item.genre }}</dd></div>
                    <div v-if="item.duration"><dt>Duration</dt><dd>{{ item.duration }}</dd></div>
                    <div v-if="item.views !== undefined"><dt>Views</dt><dd>{{ item.views }}</dd></div>
                    <div v-if="route.params.type === 'season'"><dt>Parent movie</dt><dd>{{ item.movie?.title || `Movie #${item.movie_id}` }}</dd></div>
                    <div v-if="route.params.type === 'episode'"><dt>Parent movie</dt><dd>{{ item.season?.movie?.title || `Movie #${item.season?.movie_id}` }}</dd></div>
                    <div v-if="route.params.type === 'episode'"><dt>Parent season</dt><dd>{{ item.season?.title || `Season ${item.season?.season_number || ''}` }}</dd></div>
                    <div><dt>Status</dt><dd>{{ item.status || (item.is_active === false ? 'Inactive' : 'Available') }}</dd></div>
                    <div><dt>Release date</dt><dd>{{ item.release_date || item.release_on || '—' }}</dd></div>
                </dl>
            </section>
            <section v-if="route.params.type === 'season' && item.episodes?.length" class="admin-table-card ppv-season-episodes"><header><b>PPV episodes in this season</b><span>{{ item.episodes.length }} episodes</span></header><div class="admin-table-scroll"><table><thead><tr><th>Episode</th><th>Title</th><th>PPV amount</th></tr></thead><tbody><tr v-for="episode in item.episodes" :key="episode.id || episode.num"><td>{{ episode.episode_number }}</td><td>{{ episode.title || `Episode ${episode.episode_number}` }}</td><td>{{ money(episode.amount) }}</td></tr></tbody></table></div></section>
            <section class="admin-table-card ppv-detail-purchases">
                <header><div><b>Purchase history</b><small>{{ purchases.total || 0 }} purchases · 20 per page</small></div><span>Page {{ purchases.current_page || 1 }} / {{ purchases.last_page || 1 }}</span></header>
                <StatusPanel tone="error" :message="purchaseError" />
                <div v-if="purchaseLoading" class="admin-loading">Loading this page…</div>
                <div v-else-if="!purchases.data?.length" class="admin-empty">No completed purchases for this PPV item.</div>
                <div v-else class="admin-table-scroll"><table><thead><tr><th>Date</th><th>User</th><th>Content ID</th><th>Device / gateway</th><th>Amount</th></tr></thead><tbody><tr v-for="purchase in purchases.data" :key="purchase.id"><td>{{ purchase.payment_date || purchase.created_at || '—' }}</td><td>{{ purchase.user_id || '—' }}</td><td>{{ purchase.movie_id || '—' }}</td><td>{{ [purchase.device_type, purchase.payment_gateway].filter(Boolean).join(' · ') || '—' }}</td><td>{{ money(purchase.amount, purchase.currency || 'INR') }}</td></tr></tbody></table></div>
                <footer class="ppv-pagination"><span>{{ purchases.total || 0 }} records</span><div><button class="admin-secondary" :disabled="purchaseLoading || purchases.current_page <= 1" @click="loadPurchases(purchases.current_page - 1)">Previous</button><button class="admin-secondary" :disabled="purchaseLoading || purchases.current_page >= purchases.last_page" @click="loadPurchases(purchases.current_page + 1)">Next</button></div></footer>
            </section>
        </template>
    </div>
</template>

<style scoped>
.ppv-detail-card{margin-bottom:15px;padding:20px;border:1px solid var(--a-line);border-radius:16px;background:var(--a-panel)}.ppv-detail-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding-bottom:18px;border-bottom:1px solid var(--a-line)}.ppv-detail-heading img{width:110px;max-height:155px;object-fit:cover;border-radius:10px}.ppv-detail-heading>div{flex:1}.ppv-detail-heading span{color:var(--a-cyan);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em}.ppv-detail-heading h2{margin:6px 0;font:750 24px 'Manrope',sans-serif}.ppv-detail-heading p{max-width:800px;color:var(--a-muted);font-size:12px;line-height:1.7}.ppv-detail-heading>b{align-self:flex-start;color:var(--a-cyan);font:750 22px 'Manrope',sans-serif;white-space:nowrap}.ppv-detail-card dl{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px 20px;margin:18px 0 0}.ppv-detail-card dl>div{min-width:0}.ppv-detail-card dt{color:var(--a-muted);font-size:10px}.ppv-detail-card dd{margin:5px 0 0;font-size:12px;font-weight:650;overflow-wrap:anywhere}.ppv-season-episodes{margin-bottom:15px}.ppv-season-episodes>header{display:flex;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--a-line)}.ppv-season-episodes>header span{color:var(--a-muted);font-size:10px}.ppv-detail-purchases>header{display:flex;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--a-line)}.ppv-detail-purchases>header>div{display:grid;gap:5px}.ppv-detail-purchases header small,.ppv-detail-purchases header>span{color:var(--a-muted);font-size:10px}.ppv-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;border-top:1px solid var(--a-line);color:var(--a-muted);font-size:11px}.ppv-pagination>div{display:flex;gap:8px}.ppv-pagination button:disabled{opacity:.45;cursor:not-allowed}@media(max-width:700px){.ppv-detail-card dl{grid-template-columns:repeat(2,minmax(0,1fr))}.ppv-detail-heading{display:grid}.ppv-detail-heading>b{grid-row:1}}
</style>
