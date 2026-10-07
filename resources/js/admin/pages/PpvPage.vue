<script setup>
import { onMounted, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const report = ref({ summary: {}, available: { movies: [], seasons: [], episodes: [] }, purchases: [] });
const loading = ref(true);
const error = ref('');
const currencies = () => report.value.summary?.currency_totals || [];
const money = (value, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency: currency || 'INR', maximumFractionDigits: 2 }).format(Number(value) || 0);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await api('/admin/catalog/ppv', { cache: 'no-store' });
        report.value = response.data || report.value;
    } catch (reason) {
        error.value = reason.message || 'Could not load PPV report.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Content" title="PPV" description="Available pay-per-view content, completed purchases and collected amounts.">
            <button class="admin-secondary" type="button" @click="load">Refresh</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-loading">Loading PPV report…</div>
        <template v-else>
            <section class="ppv-summary">
                <article><span>PPV movies</span><b>{{ report.summary.available_movies || 0 }}</b></article>
                <article><span>PPV seasons</span><b>{{ report.summary.available_seasons || 0 }}</b></article>
                <article><span>PPV episodes</span><b>{{ report.summary.available_episodes || 0 }}</b></article>
                <article><span>Completed purchases</span><b>{{ report.summary.purchased_count || 0 }}</b></article>
                <article><span>Total received</span><b>{{ currencies().length > 1 ? 'Multiple' : money(report.summary.total_amount, currencies()[0]?.currency || 'INR') }}</b><small v-if="currencies().length > 1">{{ currencies().map(row => `${row.currency || 'INR'} ${money(row.total_amount, row.currency || 'INR')}`).join(' · ') }}</small><small v-else>Successful PPV payments</small></article>
            </section>

            <section class="ppv-content-grid">
                <article v-for="collection in [
                    { key: 'movies', title: 'PPV movies' },
                    { key: 'seasons', title: 'PPV seasons' },
                    { key: 'episodes', title: 'PPV episodes' },
                ]" :key="collection.key" class="admin-table-card">
                    <header><b>{{ collection.title }}</b><span>{{ report.available[collection.key]?.length || 0 }} items</span></header>
                    <div v-if="!report.available[collection.key]?.length" class="admin-empty">No PPV content available.</div>
                    <div v-else class="admin-table-scroll"><table><thead><tr><th>Title</th><th>Content</th><th>Amount</th></tr></thead><tbody>
                        <tr v-for="row in report.available[collection.key]" :key="row.num">
                            <td><b>{{ row.title || `Content #${row.num}` }}</b></td>
                            <td v-if="collection.key === 'movies'">Movie #{{ row.num }}</td>
                            <td v-else-if="collection.key === 'seasons'">Movie #{{ row.movie_id }} · Season {{ row.season_number }}</td>
                            <td v-else>Episode {{ row.episode_number || row.num }}</td>
                            <td>{{ money(row.amount ?? row.ppv_amount) }}</td>
                        </tr>
                    </tbody></table></div>
                </article>
            </section>

            <section class="admin-table-card ppv-purchases">
                <header><div><b>PPV purchases</b><small>Latest 100 successful purchases; totals include all successful purchases.</small></div></header>
                <div v-if="!report.purchases?.length" class="admin-empty">No completed PPV purchases yet.</div>
                <div v-else class="admin-table-scroll"><table><thead><tr><th>Date</th><th>User</th><th>Content ID</th><th>Device / gateway</th><th>Amount</th></tr></thead><tbody>
                    <tr v-for="purchase in report.purchases" :key="purchase.id">
                        <td>{{ purchase.payment_date || purchase.created_at || '—' }}</td><td>{{ purchase.user_id || '—' }}</td><td>{{ purchase.movie_id || '—' }}</td>
                        <td>{{ [purchase.device_type, purchase.payment_gateway].filter(Boolean).join(' · ') || '—' }}</td><td>{{ money(purchase.amount, purchase.currency || 'INR') }}</td>
                    </tr>
                </tbody></table></div>
            </section>
        </template>
    </div>
</template>

<style scoped>
.ppv-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 15px}.ppv-summary article{min-width:0;padding:17px;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel)}.ppv-summary span{display:block;color:var(--a-muted);font-size:10px}.ppv-summary b{display:block;margin-top:10px;color:var(--a-cyan);font:750 20px 'Manrope',sans-serif}.ppv-summary small{display:block;margin-top:6px;color:var(--a-muted);font-size:10px}.ppv-content-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:15px}.ppv-content-grid .admin-table-card>header,.ppv-purchases>header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--a-line)}.ppv-content-grid .admin-table-card>header span,.ppv-purchases header small{color:var(--a-muted);font-size:10px}.ppv-purchases header>div{display:grid;gap:5px}.ppv-table-empty{padding:20px}@media(max-width:1000px){.ppv-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.ppv-content-grid{grid-template-columns:1fr}}@media(max-width:620px){.ppv-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.ppv-summary article:last-child{grid-column:1/-1}}
</style>
