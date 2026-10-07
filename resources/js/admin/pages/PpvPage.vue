<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const router = useRouter();
const report = ref({ summary: {}, available: { movies: [], series: [] } });
const purchases = ref({ data: [], current_page: 1, last_page: 1, total: 0, per_page: 20 });
const loading = ref(true); const purchaseLoading = ref(false); const error = ref(''); const purchaseError = ref('');
const currencies = () => report.value.summary?.currency_totals || [];
const money = (value, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency: currency || 'INR', maximumFractionDigits: 2 }).format(Number(value) || 0);
const itemPath = (type, item) => `/ppv/${type}/${encodeURIComponent(item.id ?? item.num)}`;

async function loadPurchases(page = 1) {
    purchaseLoading.value = true; purchaseError.value = '';
    try { purchases.value = (await api(`/admin/catalog/ppv/purchases?page=${page}&per_page=20`, { cache: 'no-store' })).data; }
    catch (reason) { purchaseError.value = reason.message || 'Could not load purchases.'; }
    finally { purchaseLoading.value = false; }
}
async function load() {
    loading.value = true; error.value = '';
    try { report.value = (await api('/admin/catalog/ppv', { cache: 'no-store' })).data || report.value; await loadPurchases(1); }
    catch (reason) { error.value = reason.message || 'Could not load PPV report.'; }
    finally { loading.value = false; }
}
onMounted(load);
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Content" title="PPV" description="Overall PPV performance, available titles and completed purchases.">
            <button class="admin-secondary" type="button" @click="load">Refresh</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-loading">Loading PPV overview…</div>
        <template v-else>
            <section class="ppv-summary">
                <article><span>PPV movies</span><b>{{ report.summary.available_movies || 0 }}</b></article>
                <article><span>PPV seasons</span><b>{{ report.summary.available_seasons || 0 }}</b></article>
                <article><span>PPV episodes</span><b>{{ report.summary.available_episodes || 0 }}</b></article>
                <article><span>Completed purchases</span><b>{{ report.summary.purchased_count || 0 }}</b></article>
                <article><span>Total received</span><b>{{ currencies().length > 1 ? 'Multiple currencies' : money(report.summary.total_amount, currencies()[0]?.currency || 'INR') }}</b><small v-if="currencies().length > 1">{{ currencies().map(row => `${row.currency || 'INR'}: ${money(row.total_amount, row.currency || 'INR')}`).join(' · ') }}</small><small v-else>Successful PPV payments</small></article>
            </section>

            <section class="admin-table-card ppv-library">
                <header><div><b>PPV movies</b><small>Movies offered individually as pay-per-view.</small></div><span>{{ report.available.movies?.length || 0 }} movies</span></header>
                <div v-if="!report.available.movies?.length" class="admin-empty">No PPV movies available.</div>
                <div v-else class="admin-table-scroll"><table><thead><tr><th>Movie</th><th>Amount</th><th></th></tr></thead><tbody>
                    <tr v-for="movie in report.available.movies" :key="movie.num" class="ppv-clickable" @click="router.push(itemPath('movie', movie))">
                        <td><b>{{ movie.title || `Movie #${movie.num}` }}</b><small>ID {{ movie.num }}</small></td><td>{{ money(movie.ppv_amount) }}</td><td class="ppv-open">Details →</td>
                    </tr>
                </tbody></table></div>
            </section>

            <section class="admin-table-card ppv-library ppv-series">
                <header><div><b>PPV seasons & episodes</b><small>Grouped under each parent movie.</small></div><span>{{ report.available.series?.length || 0 }} parent movies</span></header>
                <div v-if="!report.available.series?.length" class="admin-empty">No PPV seasons or episodes available.</div>
                <div v-else class="ppv-parent-list">
                    <article v-for="parent in report.available.series" :key="parent.movie_id" class="ppv-parent">
                        <div class="ppv-parent-heading"><b>{{ parent.title || `Movie #${parent.movie_id}` }}</b><small>Parent movie · #{{ parent.movie_id }}</small></div>
                        <div v-if="parent.ppv_seasons?.length" class="ppv-child-list">
                            <RouterLink v-for="season in parent.ppv_seasons" :key="season.id || season.num" :to="itemPath('season', season)" class="ppv-child">
                                <span><b>Season {{ season.season_number }} · {{ season.title || `Season ${season.season_number}` }}</b><small>{{ season.isPayPerView ? 'Full season PPV' : 'Includes PPV episodes' }}</small></span><span>{{ season.isPayPerView ? money(season.amount) : `${season.ppv_episode_count || 0} PPV episodes` }}</span><i>Details →</i>
                            </RouterLink>
                        </div>
                        <div v-if="parent.ppv_episodes?.length" class="ppv-child-list">
                            <RouterLink v-for="episode in parent.ppv_episodes" :key="episode.id || episode.num" :to="itemPath('episode', episode)" class="ppv-child">
                                <span><b>{{ episode.season?.title || `Season ${episode.season?.season_number || ''}` }} · Episode {{ episode.episode_number }}</b><small>{{ episode.title || `Episode ${episode.episode_number}` }}</small></span><span>{{ money(episode.amount) }}</span><i>Details →</i>
                            </RouterLink>
                        </div>
                    </article>
                </div>
            </section>

            <section class="admin-table-card ppv-purchases">
                <header><div><b>PPV purchases</b><small>{{ purchases.total || 0 }} completed purchases · 20 per page</small></div><span>Page {{ purchases.current_page || 1 }} / {{ purchases.last_page || 1 }}</span></header>
                <StatusPanel tone="error" :message="purchaseError" />
                <div v-if="purchaseLoading" class="admin-loading">Loading this page…</div>
                <div v-else-if="!purchases.data?.length" class="admin-empty">No completed PPV purchases yet.</div>
                <div v-else class="admin-table-scroll"><table><thead><tr><th>Date</th><th>User</th><th>Content ID</th><th>Device / gateway</th><th>Amount</th></tr></thead><tbody>
                    <tr v-for="purchase in purchases.data" :key="purchase.id"><td>{{ purchase.payment_date || purchase.created_at || '—' }}</td><td>{{ purchase.user_id || '—' }}</td><td>{{ purchase.movie_id || '—' }}</td><td>{{ [purchase.device_type, purchase.payment_gateway].filter(Boolean).join(' · ') || '—' }}</td><td>{{ money(purchase.amount, purchase.currency || 'INR') }}</td></tr>
                </tbody></table></div>
                <footer class="ppv-pagination"><span>{{ purchases.total || 0 }} records</span><div><button class="admin-secondary" :disabled="purchaseLoading || purchases.current_page <= 1" @click="loadPurchases(purchases.current_page - 1)">Previous</button><button class="admin-secondary" :disabled="purchaseLoading || purchases.current_page >= purchases.last_page" @click="loadPurchases(purchases.current_page + 1)">Next</button></div></footer>
            </section>
        </template>
    </div>
</template>

<style scoped>
.ppv-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 15px}.ppv-summary article{min-width:0;padding:17px;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel)}.ppv-summary span{display:block;color:var(--a-muted);font-size:10px}.ppv-summary b{display:block;margin-top:10px;color:var(--a-cyan);font:750 20px 'Manrope',sans-serif}.ppv-summary small,.ppv-library td small{display:block;margin-top:6px;color:var(--a-muted);font-size:10px}.ppv-library{margin-bottom:14px}.ppv-library>header,.ppv-purchases>header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--a-line)}.ppv-library>header>div,.ppv-purchases>header>div{display:grid;gap:5px}.ppv-library>header small,.ppv-library>header>span,.ppv-purchases>header small,.ppv-purchases>header>span{color:var(--a-muted);font-size:10px}.ppv-clickable{cursor:pointer}.ppv-clickable:hover,.ppv-child:hover{background:color-mix(in srgb,var(--a-cyan) 7%,transparent)}.ppv-open{text-align:right;color:var(--a-cyan);font-weight:700}.ppv-parent-list{padding:8px 14px}.ppv-parent{padding:12px 0;border-bottom:1px solid var(--a-line)}.ppv-parent:last-child{border-bottom:0}.ppv-parent-heading{display:flex;align-items:baseline;justify-content:space-between;padding:4px 5px 10px}.ppv-parent-heading small{color:var(--a-muted);font-size:10px}.ppv-child-list{margin:0 0 4px 12px;border-left:1px solid var(--a-line)}.ppv-child{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:16px;padding:10px 12px;border-radius:9px;color:inherit;text-decoration:none}.ppv-child>span:first-child b,.ppv-child small{display:block}.ppv-child small,.ppv-child i{color:var(--a-muted);font-size:10px;font-style:normal}.ppv-child>span:nth-child(2){font-size:11px}.ppv-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;border-top:1px solid var(--a-line);color:var(--a-muted);font-size:11px}.ppv-pagination>div{display:flex;gap:8px}.ppv-pagination button:disabled{opacity:.45;cursor:not-allowed}@media(max-width:1000px){.ppv-summary{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:620px){.ppv-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.ppv-summary article:last-child{grid-column:1/-1}.ppv-child{grid-template-columns:minmax(0,1fr) auto;gap:8px}.ppv-child i{display:none}.ppv-parent-heading{display:grid;gap:4px}}
</style>
