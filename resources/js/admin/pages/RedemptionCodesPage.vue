<script setup>
import QRCodeStyling from 'qr-code-styling';
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, getSession, queryString } from '../lib/api';
import { QR_CODES_PER_PAGE, PPV_CODES_PER_PAGE, printVoucherDocument, voucherDocument } from '../lib/redemptionPrint';
import mobileIcon from '../../../images/zostream-mobile-icon.png?inline';

const options = ref({ movies: [], series: [] });
const rows = ref([]); const page = ref(1); const lastPage = ref(1); const filter = ref('');
const loading = ref(false); const saving = ref(false); const cancelling = ref(null);
const error = ref(''); const notice = ref(''); const selected = ref(null);
const batch = ref(null); const rendering = ref(false); const renderedCount = ref(0); const printing = ref(false);
const lastBatchId = ref(''); const pendingRequest = ref(null);
const storageKey = `zostream_qr_batch_${getSession()?.uid || 'admin'}`;
try { pendingRequest.value = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch { /* Storage may be unavailable. */ }
const form = ref({ label: '', type: 'subscription', target: '', rental_days: 7, expires_at: '', page_count: 1,
    ppv_kind: 'movie', series: '', season: '', ppv_scope: 'season', episode: '' });
const codesPerPage = computed(() => form.value.type === 'ppv' ? PPV_CODES_PER_PAGE : QR_CODES_PER_PAGE);
const totalCodes = computed(() => Number(form.value.page_count || 0) * codesPerPage.value);
const selectedSeries = computed(() => options.value.series?.find(series => series.id === form.value.series));
const selectedSeason = computed(() => selectedSeries.value?.seasons.find(season => season.id === form.value.season));
const ppvSelection = computed(() => {
    if (form.value.ppv_kind === 'movie') {
        const movie = options.value.movies.find(movie => movie.id === form.value.target);
        return movie ? { id: movie.id, content_type: 'movie', title: movie.title, poster: movie.poster, scope_label: 'Movie' } : null;
    }
    const series = selectedSeries.value; const season = selectedSeason.value;
    if (!series || !season) return null;
    if (form.value.ppv_scope === 'season') {
        return season.is_pay_per_view ? { id: season.id, content_type: 'season', title: series.title, subtitle: season.title,
            poster: season.poster || series.poster, scope_label: `Season ${season.season_number} · Full season` } : null;
    }
    const episode = season.episodes.find(episode => episode.id === form.value.episode);
    return episode ? { id: episode.id, content_type: 'episode', title: series.title, subtitle: episode.title,
        poster: season.poster || series.poster || episode.thumbnail, scope_label: `Season ${season.season_number} · Episode ${episode.episode_number} only` } : null;
});
const printHtml = computed(() => batch.value ? voucherDocument(batch.value, batch.value.codes, mobileIcon) : '');
const formatDate = value => value ? new Date(value).toLocaleString() : '—';

function savePending(value) {
    pendingRequest.value = value;
    try {
        if (value) sessionStorage.setItem(storageKey, JSON.stringify(value));
        else sessionStorage.removeItem(storageKey);
    } catch { /* The in-memory request still supports a safe retry. */ }
}

async function qrImage(payload) {
    const qr = new QRCodeStyling({ width: 288, height: 288, type: 'canvas', data: payload,
        qrOptions: { errorCorrectionLevel: 'M' }, margin: 16,
        dotsOptions: { color: '#111827', type: 'square' }, backgroundOptions: { color: '#ffffff' } });
    const blob = await qr.getRawData('png');
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('Could not render the QR image.'));
        reader.readAsDataURL(blob);
    });
}

async function load() {
    loading.value = true; error.value = '';
    try {
        const result = await api(`/admin/redemption-codes${queryString({ page: page.value, status: filter.value })}`);
        rows.value = result.data; lastPage.value = result.last_page;
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
async function preview(row) {
    try { selected.value = { ...row, image: await qrImage(row.qr_payload) }; }
    catch (reason) { error.value = reason.message; }
}
function download() {
    if (!selected.value) return;
    const link = document.createElement('a');
    link.href = selected.value.image; link.download = `zostream-qr-${selected.value.id}.png`; link.click();
}
async function prepareBatch(value) {
    lastBatchId.value = value.batch_id;
    rendering.value = true; renderedCount.value = 0;
    try {
        const codes = [];
        for (let offset = 0; offset < value.codes.length; offset += QR_CODES_PER_PAGE) {
            const chunk = await Promise.all(value.codes.slice(offset, offset + QR_CODES_PER_PAGE)
                .map(async code => ({ ...code, image: await qrImage(code.qr_payload) })));
            codes.push(...chunk); renderedCount.value = codes.length;
        }
        batch.value = { ...value, codes };
    } finally { rendering.value = false; }
}
async function openBatch(id) {
    if (rendering.value || saving.value || printing.value) return;
    error.value = '';
    try { await prepareBatch(await api(`/admin/redemption-codes/batches/${id}`)); }
    catch (reason) { error.value = reason.message; }
}
async function printBatch() {
    if (!batch.value || printing.value) return;
    printing.value = true; error.value = '';
    try {
        // Refresh status so cards claimed since preview are marked as used.
        await prepareBatch(await api(`/admin/redemption-codes/batches/${batch.value.batch_id}`));
        await printVoucherDocument(printHtml.value);
    } catch (reason) { error.value = reason.message; }
    finally { printing.value = false; }
}
async function create() {
    if (saving.value || rendering.value) return;
    saving.value = true; error.value = ''; notice.value = '';
    try {
        let data = pendingRequest.value;
        if (!data) {
            data = { label: form.value.label, type: form.value.type, batch_id: crypto.randomUUID(),
                page_count: Number(form.value.page_count),
                expires_at: form.value.expires_at ? new Date(form.value.expires_at).toISOString() : null };
            if (data.type === 'ppv') {
                if (!ppvSelection.value) throw new Error('Choose a PPV movie, full season or episode.');
                data.movie_id = String(ppvSelection.value.id); data.content_type = ppvSelection.value.content_type;
                data.rental_days = Number(form.value.rental_days);
            }
            savePending(data);
        }
        const result = await api('/admin/redemption-codes/batches', { method: 'POST', body: data });
        savePending(null);
        notice.value = `${result.codes.length} unique QR cards created across ${result.page_count} A4 ${result.page_count === 1 ? 'sheet' : 'sheets'}.`;
        filter.value = ''; page.value = 1;
        await load(); await prepareBatch(result);
    } catch (reason) {
        error.value = reason.message;
        if (reason.status >= 400 && reason.status < 500 && ![408, 429].includes(reason.status)) savePending(null);
    }
    finally { saving.value = false; }
}
async function cancel(row) {
    if (!window.confirm(`Cancel “${row.label}”? This QR will stop working.`)) return;
    cancelling.value = row.id; error.value = '';
    try {
        await api(`/admin/redemption-codes/${row.id}/cancel`, { method: 'POST' });
        if (selected.value?.id === row.id) selected.value = null;
        if (batch.value?.codes.some(code => code.id === row.id)) batch.value = null;
        notice.value = 'QR cancelled.'; await load();
    } catch (reason) { error.value = reason.message; }
    finally { cancelling.value = null; }
}
watch(() => form.value.type, () => { form.value.target = ''; });
watch(() => form.value.series, () => { form.value.season = ''; form.value.episode = ''; });
watch(() => form.value.season, () => {
    form.value.episode = '';
    form.value.ppv_scope = selectedSeason.value?.is_pay_per_view ? 'season' : 'episode';
});
onMounted(async () => {
    await load();
    try { options.value = await api('/admin/redemption-codes/options'); }
    catch (reason) { error.value = reason.message; }
});
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Audience" title="Activation QR studio" description="Create beautifully arranged A4 sheets of single-use access cards.">
            <button class="admin-secondary" type="button" :disabled="loading" @click="load">Refresh</button>
        </PageHeader>
        <StatusPanel tone="error" :message="error" />
        <StatusPanel tone="success" :message="notice" />
        <section class="redemption-top">
            <form class="admin-table-card redemption-form" @submit.prevent="create">
                <div class="studio-heading"><span class="studio-kicker">PRINT & SHARE</span><h2>A little card. More entertainment.</h2><p>Every card has its own unique, single-use QR.</p></div>
                <div v-if="pendingRequest" class="pending-batch"><b>A batch needs confirmation.</b><p>Retry to recover the same QR cards. A new batch will not be created.</p><button class="admin-primary" type="button" :disabled="saving" @click="create">{{ saving ? 'Recovering…' : 'Retry batch' }}</button></div>
                <fieldset :disabled="saving || rendering || printing || Boolean(pendingRequest)">
                <label>Label<input v-model="form.label" required maxlength="150" placeholder="Campaign or recipient reference" /></label>
                <label>Activation type<select v-model="form.type"><option value="ppv">PPV movie</option><option value="subscription">Subscription</option></select></label>
                <label v-if="form.type === 'ppv'">PPV content<select v-model="form.ppv_kind"><option value="movie">Movie</option><option value="series">Series · Season / Episode</option></select></label>
                <label v-if="form.type === 'ppv' && form.ppv_kind === 'movie'">Movie<select v-model="form.target" required>
                    <option disabled value="">Select a movie</option>
                    <option v-for="target in options.movies" :key="target.id" :value="target.id">{{ target.title }}</option>
                </select></label>
                <template v-if="form.type === 'ppv' && form.ppv_kind === 'series'">
                    <label>Series<select v-model="form.series" required><option disabled value="">Select a series</option><option v-for="series in options.series" :key="series.id" :value="series.id">{{ series.title }}</option></select></label>
                    <label v-if="selectedSeries">Season<select v-model="form.season" required><option disabled value="">Select a season</option><option v-for="season in selectedSeries.seasons" :key="season.id" :value="season.id">Season {{ season.season_number }} · {{ season.title || 'Untitled' }}</option></select></label>
                    <label v-if="selectedSeason">Access includes<select v-model="form.ppv_scope"><option v-if="selectedSeason.is_pay_per_view" value="season">Full season only</option><option v-if="selectedSeason.episodes.length" value="episode">One episode only</option></select></label>
                    <label v-if="selectedSeason && form.ppv_scope === 'episode'">Episode<select v-model="form.episode" required><option disabled value="">Select an episode</option><option v-for="episode in selectedSeason.episodes" :key="episode.id" :value="episode.id">Episode {{ episode.episode_number }} · {{ episode.title || 'Untitled' }}</option></select></label>
                </template>
                <div v-if="form.type === 'ppv' && ppvSelection" class="ppv-selection"><img v-if="ppvSelection.poster" :src="ppvSelection.poster" alt="Selected PPV poster" /><div><strong>{{ ppvSelection.title }}</strong><small>{{ ppvSelection.scope_label }}</small><p v-if="ppvSelection.subtitle">{{ ppvSelection.subtitle }}</p></div></div>
                <label v-if="form.type === 'ppv'">Rental days from activation<input v-model="form.rental_days" type="number" min="1" max="365" required /></label>
                <div v-else class="subscription-note"><strong>30 days · Add or extend</strong><p>No plan selection needed. The customer chooses their device in the app. New access starts for 30 days; an active subscription extends by 30 days.</p></div>
                <label>A4 page count (1–20)<input v-model.number="form.page_count" type="number" min="1" max="20" step="1" required /></label>
                <div class="sheet-summary"><b>{{ form.page_count || 0 }} A4 sheets</b><span>× {{ codesPerPage }} cards = <strong>{{ totalCodes }} unique QR codes</strong></span><small>Portrait · 210 × 297 mm · {{ form.type === 'ppv' ? '2 columns × 4 rows · Poster tickets' : '3 columns × 4 rows' }}</small></div>
                <label>QR expiry (optional, local time)<input v-model="form.expires_at" type="datetime-local" /></label>
                <button class="admin-primary" type="submit">{{ saving ? 'Creating sheets…' : `Generate ${totalCodes} QR cards` }}</button>
                </fieldset>
                <small>Cards are consumed only after successful activation. Keep unused cards private.</small>
            </form>
            <article v-if="!selected" class="studio-cover">
                <span class="studio-kicker">ZO STREAM ACCESS</span><h2>Scan.<br>Select.<br>Stream.</h2><p>One card. One activation.<br>A screen full of possibilities.</p><div class="cover-grid"><span v-for="index in 12" :key="index">▦</span></div><small>Designed for A4 · Ready to cut & distribute</small>
            </article>
            <article v-if="selected" class="admin-table-card redemption-preview">
                <h2>{{ selected.label }}</h2><img :src="selected.image" alt="Single-use activation QR" class="redemption-qr" />
                <p>Single use · {{ selected.type === 'ppv' ? 'PPV' : 'Subscription' }}</p>
                <button class="admin-primary" type="button" @click="download">Download PNG</button>
                <small>Keep this QR private. Anyone with the code can claim it once.</small>
            </article>
        </section>
        <section v-if="rendering" class="admin-table-card render-progress">Preparing your print sheets… {{ renderedCount }} cards rendered.</section>
        <section v-if="batch" class="admin-table-card print-preview">
            <div class="redemption-toolbar"><div><span class="studio-kicker">READY TO PRINT</span><h2>{{ batch.label }}</h2><p>{{ batch.page_count }} A4 sheets · {{ batch.codes.length }} QR cards · {{ batch.target_name }}</p></div><button class="admin-primary" :disabled="rendering || printing" @click="printBatch">{{ printing ? 'Preparing print…' : 'Print / Save PDF' }}</button></div>
            <p class="print-help">Choose A4 portrait, 100% scale, and turn off browser headers and footers. Select “Save as PDF” to download.</p>
            <iframe :srcdoc="printHtml" title="A4 activation QR sheet preview" class="sheet-frame" sandbox="allow-same-origin" />
        </section>
        <button v-else-if="lastBatchId && !rendering" class="admin-secondary" @click="openBatch(lastBatchId)">Reopen last print batch</button>
        <section class="admin-table-card">
            <div class="redemption-toolbar"><h2>QR history</h2><select v-model="filter" aria-label="Filter status" @change="page = 1; load()">
                <option value="">All statuses</option><option value="available">Available</option><option value="redeemed">Redeemed</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option>
            </select></div>
            <div v-if="loading" class="admin-loading">Loading QR codes…</div>
            <div v-else class="admin-table-scroll"><table><thead><tr><th>Label / target</th><th>Status</th><th>Expiry</th><th>Activation</th><th>Actions</th></tr></thead><tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td><b>{{ row.label }}</b><small>{{ row.type }} · {{ row.movie_id || (row.plan_id ? `Plan #${row.plan_id}` : '30-day add / extend') }}</small></td>
                    <td>{{ row.status }}</td><td>{{ formatDate(row.expires_at) }}</td>
                    <td><template v-if="row.redeemed_at">{{ row.redeemed_by }}<small>Device #{{ row.device_id }} · {{ row.result?.action }} · {{ formatDate(row.redeemed_at) }}</small></template><span v-else>—</span></td>
                    <td><template v-if="row.status === 'available'"><button class="admin-secondary" @click="preview(row)">View QR</button> <button class="admin-secondary" :disabled="cancelling === row.id" @click="cancel(row)">Cancel</button></template><button v-if="row.batch_id" class="admin-secondary" :disabled="saving || rendering || printing" @click="openBatch(row.batch_id)">A4 batch</button></td>
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
.redemption-form fieldset { display: grid; gap: 18px; margin: 0; padding: 0; border: 0; min-width: 0; }
.redemption-form fieldset:disabled { opacity: .6; }
.redemption-form label { display: grid; gap: 6px; }
input, select { padding: 10px 12px; border: 1px solid var(--a-line); border-radius: 8px; background: var(--a-bg); color: var(--a-text); min-width: 0; }
.redemption-form p, .redemption-preview small { opacity: .75; line-height: 1.5; }
.redemption-preview { display: flex; flex-direction: column; align-items: center; gap: 16px; }
.redemption-qr { background: white; width: 300px; max-width: 100%; }
.redemption-qr :deep(canvas) { max-width: 100%; height: auto; }
.redemption-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px; }
td small { display: block; opacity: .7; margin-top: 5px; }
.studio-kicker { display: block; font-size: 10px; font-weight: 800; letter-spacing: 2px; color: var(--a-cyan); margin-bottom: 10px; }
.studio-heading h2 { font-size: 25px; line-height: 1.2; margin: 0; }
.studio-heading p { margin-bottom: 0; }
.subscription-note, .sheet-summary, .pending-batch { padding: 16px; border: 1px solid var(--a-line); border-radius: 12px; background: var(--a-bg); }
.subscription-note strong { color: var(--a-cyan); }.subscription-note p { margin-bottom: 0; font-size: 13px; }
.ppv-selection { display: flex; gap: 16px; align-items: center; padding: 12px; border: 1px solid var(--a-line); border-radius: 12px; background: var(--a-bg); }.ppv-selection img { width: 64px; height: 90px; object-fit: cover; border-radius: 7px; }.ppv-selection small { display: block; margin-top: 6px; color: var(--a-cyan); }.ppv-selection p { font-size: 12px; margin-bottom: 0; }
.sheet-summary { display: grid; gap: 6px; }.sheet-summary small, .redemption-form>small { color: var(--a-muted); }
.studio-cover { border-radius: 18px; padding: 36px; background: linear-gradient(145deg, #102f3c, #075e60); color: #fff; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
.studio-cover h2 { font-size: clamp(48px, 5vw, 78px); line-height: 1; letter-spacing: -3px; margin: 20px 0; }.studio-cover p { color: #c3e5df; line-height: 1.7; }.studio-cover .studio-kicker { color: #92efce; }
.cover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; width: 180px; transform: rotate(-8deg); margin: 22px 0; }.cover-grid span { display: grid; place-items: center; background: #ffffff16; border: 1px solid #ffffff2a; border-radius: 7px; aspect-ratio: 1; font-size: 25px; color: #b5e9da; }
.studio-cover>small { color: #c3e5df; font-size: 11px; }.render-progress { padding: 24px; margin-bottom: 20px; }
.print-preview { margin-bottom: 24px; overflow: hidden; }.print-preview h2 { margin: 0; }.print-preview p { font-size: 13px; color: var(--a-muted); }.print-help { padding: 0 16px; }
.sheet-frame { display: block; width: 100%; height: 900px; border: 0; background: #e8edf1; }
@media (max-width: 640px) { .redemption-toolbar { flex-wrap: wrap; }.studio-cover { padding: 24px; }.sheet-frame { height: 650px; } }
</style>
