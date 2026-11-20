<script setup>
import QRCodeStyling from 'qr-code-styling';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';

const options = ref({ movies: [], plans: [] });
const rows = ref([]); const page = ref(1); const lastPage = ref(1); const filter = ref('');
const loading = ref(false); const saving = ref(false); const cancelling = ref(null);
const error = ref(''); const notice = ref(''); const selected = ref(null); const qrMount = ref(null);
const form = ref({ label: '', type: 'ppv', target: '', rental_days: 7, expires_at: '' });
const targets = computed(() => form.value.type === 'ppv' ? options.value.movies : options.value.plans);
const formatDate = value => value ? new Date(value).toLocaleString() : '—';
let qr;

async function load() {
    loading.value = true; error.value = '';
    try {
        const result = await api(`/admin/redemption-codes${queryString({ page: page.value, status: filter.value })}`);
        rows.value = result.data; lastPage.value = result.last_page;
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
async function preview(row) {
    selected.value = row;
    await nextTick();
    qrMount.value.replaceChildren();
    qr = new QRCodeStyling({ width: 300, height: 300, type: 'canvas', data: row.qr_payload,
        qrOptions: { errorCorrectionLevel: 'M' }, margin: 12,
        dotsOptions: { color: '#111827', type: 'square' }, backgroundOptions: { color: '#ffffff' } });
    qr.append(qrMount.value);
}
async function download() {
    try { await qr?.download({ name: `zostream-qr-${selected.value.id}`, extension: 'png' }); }
    catch { error.value = 'Could not download the QR image. Please try again.'; }
}
async function create() {
    saving.value = true; error.value = ''; notice.value = '';
    try {
        const data = { label: form.value.label, type: form.value.type,
            expires_at: form.value.expires_at ? new Date(form.value.expires_at).toISOString() : null };
        if (data.type === 'ppv') { data.movie_id = String(form.value.target); data.rental_days = Number(form.value.rental_days); }
        else data.plan_id = Number(form.value.target);
        const row = await api('/admin/redemption-codes', { method: 'POST', body: data });
        notice.value = 'Single-use QR created. Download it below.';
        filter.value = ''; page.value = 1;
        await load(); await preview(row);
    } catch (reason) { error.value = reason.message; }
    finally { saving.value = false; }
}
async function cancel(row) {
    if (!window.confirm(`Cancel “${row.label}”? This QR will stop working.`)) return;
    cancelling.value = row.id; error.value = '';
    try {
        await api(`/admin/redemption-codes/${row.id}/cancel`, { method: 'POST' });
        if (selected.value?.id === row.id) selected.value = null;
        notice.value = 'QR cancelled.'; await load();
    } catch (reason) { error.value = reason.message; }
    finally { cancelling.value = null; }
}
watch(() => form.value.type, () => { form.value.target = ''; });
onMounted(async () => {
    await load();
    try { options.value = await api('/admin/redemption-codes/options'); }
    catch (reason) { error.value = reason.message; }
});
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Audience" title="Activation QR codes" description="Issue a single-use PPV or subscription QR. The customer scans it and selects a device.">
            <button class="admin-secondary" type="button" :disabled="loading" @click="load">Refresh</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <StatusPanel tone="success" :message="notice" />
        <section class="redemption-top">
            <form class="admin-table-card redemption-form" @submit.prevent="create">
                <h2>Create QR</h2>
                <label>Label<input v-model="form.label" required maxlength="150" placeholder="Campaign or recipient reference" /></label>
                <label>Activation type<select v-model="form.type"><option value="ppv">PPV movie</option><option value="subscription">Subscription</option></select></label>
                <label>{{ form.type === 'ppv' ? 'Movie' : 'Plan' }}<select v-model="form.target" required>
                    <option disabled value="">Select {{ form.type === 'ppv' ? 'a movie' : 'a plan' }}</option>
                    <option v-for="target in targets" :key="target.id" :value="target.id">{{ target.title || `${target.name} · ${target.device_type} · ${target.duration_days} days` }}</option>
                </select></label>
                <label v-if="form.type === 'ppv'">Rental days from activation<input v-model="form.rental_days" type="number" min="1" max="365" required /></label>
                <p v-else>New subscriptions use the plan duration. An active subscription with the same plan extends by 30 days from its current expiry.</p>
                <label>QR expiry (optional, local time)<input v-model="form.expires_at" type="datetime-local" /></label>
                <p>The QR is consumed only after successful activation. No payment is collected when it is redeemed.</p>
                <button class="admin-primary" type="submit" :disabled="saving">{{ saving ? 'Creating…' : 'Generate QR' }}</button>
            </form>
            <article v-if="selected" class="admin-table-card redemption-preview">
                <h2>{{ selected.label }}</h2><div ref="qrMount" class="redemption-qr" />
                <p>Single use · {{ selected.type === 'ppv' ? 'PPV' : 'Subscription' }}</p>
                <button class="admin-primary" type="button" @click="download">Download PNG</button>
                <small>Keep this QR private. Anyone with the code can claim it once.</small>
            </article>
        </section>
        <section class="admin-table-card">
            <div class="redemption-toolbar"><h2>QR history</h2><select v-model="filter" aria-label="Filter status" @change="page = 1; load()">
                <option value="">All statuses</option><option value="available">Available</option><option value="redeemed">Redeemed</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option>
            </select></div>
            <div v-if="loading" class="admin-loading">Loading QR codes…</div>
            <div v-else class="admin-table-scroll"><table><thead><tr><th>Label / target</th><th>Status</th><th>Expiry</th><th>Activation</th><th>Actions</th></tr></thead><tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td><b>{{ row.label }}</b><small>{{ row.type }} · {{ row.movie_id || `Plan #${row.plan_id}` }}</small></td>
                    <td>{{ row.status }}</td><td>{{ formatDate(row.expires_at) }}</td>
                    <td><template v-if="row.redeemed_at">{{ row.redeemed_by }}<small>Device #{{ row.device_id }} · {{ row.result?.action }} · {{ formatDate(row.redeemed_at) }}</small></template><span v-else>—</span></td>
                    <td><template v-if="row.status === 'available'"><button class="admin-secondary" @click="preview(row)">View QR</button> <button class="admin-secondary" :disabled="cancelling === row.id" @click="cancel(row)">Cancel</button></template></td>
                </tr>
                <tr v-if="!rows.length"><td colspan="5">No QR codes found.</td></tr>
            </tbody></table></div>
            <div class="redemption-toolbar"><button class="admin-secondary" :disabled="page <= 1 || loading" @click="page--; load()">Previous</button><span>{{ page }} / {{ lastPage }}</span><button class="admin-secondary" :disabled="page >= lastPage || loading" @click="page++; load()">Next</button></div>
        </section>
    </div>
</template>

<style scoped>
.redemption-top { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 20px; margin-bottom: 20px; }
.redemption-form, .redemption-preview { padding: 24px; }
.redemption-form { display: grid; gap: 16px; }
.redemption-form label { display: grid; gap: 6px; }
input, select { padding: 10px 12px; border: 1px solid var(--a-line); border-radius: 8px; background: var(--a-bg); color: var(--a-text); min-width: 0; }
.redemption-form p, .redemption-preview small { opacity: .75; line-height: 1.5; }
.redemption-preview { display: flex; flex-direction: column; align-items: center; gap: 16px; }
.redemption-qr { background: white; width: 300px; max-width: 100%; }
.redemption-qr :deep(canvas) { max-width: 100%; height: auto; }
.redemption-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px; }
td small { display: block; opacity: .7; margin-top: 5px; }
</style>
