<script setup>
import QRCodeStyling from 'qr-code-styling';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, getSession, queryString } from '../lib/api';
import { QR_CODES_PER_PAGE, PPV_CODES_PER_PAGE, printVoucherDocument, voucherDocument } from '../lib/redemptionPrint';
import mobileIcon from '../../../images/zostream-mobile-icon.png?inline';

const options = ref({ movies: [], series: [] });
const rows = ref([]); const page = ref(1); const lastPage = ref(1); const filter = ref('');
const expandedLabel = ref(null); const detailRows = ref([]); const detailLoading = ref(false); const detailError = ref('');
const detailPage = ref(1); const detailLastPage = ref(1); const detailTotal = ref(0); const detailFilter = ref('');
let detailRequest = 0;
const loading = ref(false); const saving = ref(false); const cancelling = ref(null);
const error = ref(''); const notice = ref(''); const selected = ref(null);
const batch = ref(null); const rendering = ref(false); const renderedCount = ref(0); const printing = ref(false);
const lastBatchId = ref(''); const pendingRequest = ref(null);
const previewDialog = ref(null); const previewMode = ref(null); const previewLoading = ref(false); const previewError = ref('');
const previewTarget = ref(null);
let previewRequest = 0; let renderingRequest = 0; let previewOpener; let previousBodyOverflow = null;
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

async function showPreview(mode) {
    const request = ++previewRequest;
    previewMode.value = mode; previewError.value = ''; previewLoading.value = true;
    await nextTick();
    if (request !== previewRequest) return request;
    if (!previewDialog.value.open) {
        previewOpener = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        previewDialog.value.showModal();
    }
    return request;
}
function restorePreviewScroll() {
    if (previousBodyOverflow !== null) {
        document.body.style.overflow = previousBodyOverflow;
        previousBodyOverflow = null;
    }
}
function onPreviewClosed() {
    // A native close event can arrive after another preview has already opened.
    if (previewDialog.value?.open || previewMode.value === null) return;
    previewRequest++; renderingRequest++;
    previewMode.value = null; previewLoading.value = false; previewError.value = '';
    selected.value = null; batch.value = null; rendering.value = false;
    restorePreviewScroll();
    if (previewOpener?.isConnected && !previewOpener.disabled) previewOpener.focus();
}
function closePreview() {
    if (printing.value) return;
    previewDialog.value?.close();
    onPreviewClosed();
}
function onPreviewBackdrop(event) {
    if (event.target !== previewDialog.value) return;
    const rect = previewDialog.value.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closePreview();
}

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
        const result = await api(`/admin/redemption-codes/history${queryString({ page: page.value, status: filter.value })}`);
        rows.value = result.data; lastPage.value = result.last_page;
        if (expandedLabel.value !== null && !rows.value.some(row => row.label === expandedLabel.value)) closeDetails();
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
function closeDetails() {
    expandedLabel.value = null; detailRows.value = []; detailError.value = ''; detailLoading.value = false;
    detailPage.value = 1; detailLastPage.value = 1; detailTotal.value = 0;
    detailRequest++;
}
async function loadDetails(targetPage = 1) {
    if (expandedLabel.value === null) return;
    const request = ++detailRequest;
    detailLoading.value = true; detailError.value = '';
    try {
        const result = await api(`/admin/redemption-codes/history/codes${queryString({ label: expandedLabel.value, page: targetPage, status: detailFilter.value })}`);
        if (request !== detailRequest) return;
        if (!result.data.length && targetPage > 1) return loadDetails(targetPage - 1);
        detailRows.value = result.data; detailPage.value = result.current_page;
        detailLastPage.value = result.last_page; detailTotal.value = result.total;
    } catch (reason) { if (request === detailRequest) detailError.value = reason.message; }
    finally { if (request === detailRequest) detailLoading.value = false; }
}
async function toggleDetails(label) {
    if (expandedLabel.value === label) { closeDetails(); return; }
    closeDetails(); expandedLabel.value = label; detailPage.value = 1; detailFilter.value = '';
    await loadDetails();
}
async function refreshHistory() {
    await load();
    if (expandedLabel.value !== null) await loadDetails(detailPage.value);
}
function changeGroupPage(targetPage) {
    closeDetails(); page.value = targetPage; load();
}
async function preview(row) {
    previewTarget.value = row; selected.value = null;
    const request = await showPreview('qr');
    if (request !== previewRequest) return;
    try {
        const image = await qrImage(row.qr_payload);
        if (request === previewRequest) selected.value = { ...row, image };
    } catch (reason) { if (request === previewRequest) previewError.value = reason.message; }
    finally { if (request === previewRequest) previewLoading.value = false; }
}
function download() {
    if (!selected.value) return;
    const link = document.createElement('a');
    link.href = selected.value.image; link.download = `zostream-qr-${selected.value.id}.png`; link.click();
}
async function prepareBatch(value, request = previewRequest) {
    if (request !== previewRequest) return false;
    const renderRequest = ++renderingRequest;
    lastBatchId.value = value.batch_id;
    rendering.value = true; renderedCount.value = 0;
    try {
        const codes = [];
        for (let offset = 0; offset < value.codes.length; offset += QR_CODES_PER_PAGE) {
            if (request !== previewRequest || renderRequest !== renderingRequest) return false;
            const chunk = await Promise.all(value.codes.slice(offset, offset + QR_CODES_PER_PAGE)
                .map(async code => ({ ...code, image: await qrImage(code.qr_payload) })));
            codes.push(...chunk); renderedCount.value = codes.length;
        }
        if (request !== previewRequest || renderRequest !== renderingRequest) return false;
        batch.value = { ...value, codes };
        return true;
    } finally { if (renderRequest === renderingRequest) rendering.value = false; }
}
async function openBatch(id, provided = null) {
    if (printing.value || (!provided && saving.value)) return;
    lastBatchId.value = id; batch.value = null;
    const request = await showPreview('pdf');
    if (request !== previewRequest) return;
    try { await prepareBatch(provided || await api(`/admin/redemption-codes/batches/${id}`), request); }
    catch (reason) { if (request === previewRequest) previewError.value = reason.message; }
    finally { if (request === previewRequest) previewLoading.value = false; }
}
async function printBatch() {
    if (!batch.value || printing.value) return;
    printing.value = true; previewError.value = '';
    const request = previewRequest;
    try {
        // Refresh status so cards claimed since preview are marked as used.
        const ready = await prepareBatch(await api(`/admin/redemption-codes/batches/${batch.value.batch_id}`), request);
        if (!ready) return;
        await printVoucherDocument(printHtml.value);
    } catch (reason) { if (request === previewRequest) previewError.value = reason.message; }
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
        await load(); await openBatch(result.batch_id, result);
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
        notice.value = 'QR cancelled.'; await refreshHistory();
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
onBeforeUnmount(() => {
    previewRequest++; renderingRequest++;
    previewDialog.value?.close();
    restorePreviewScroll();
});
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Audience" title="Activation QR studio" description="Create beautifully arranged A4 sheets of single-use access cards.">
            <button class="admin-secondary" type="button" :disabled="loading" @click="refreshHistory">Refresh</button>
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
            <article class="studio-cover">
                <span class="studio-kicker">ZO STREAM ACCESS</span><h2>Scan.<br>Select.<br>Stream.</h2><p>One card. One activation.<br>A screen full of possibilities.</p><div class="cover-grid"><span v-for="index in 12" :key="index">▦</span></div><small>Designed for A4 · Ready to cut & distribute</small>
            </article>
        </section>
        <button v-if="lastBatchId && previewMode !== 'pdf' && !rendering" class="admin-secondary reopen-batch" @click="openBatch(lastBatchId)">Reopen last print batch</button>
        <section class="admin-table-card">
            <div class="redemption-toolbar"><div><h2>QR history</h2><small>Open a label to see its cards by the reference number printed on the PDF.</small></div><select v-model="filter" aria-label="Filter labels by card status" @change="changeGroupPage(1)">
                <option value="">All labels</option><option value="available">With available cards</option><option value="redeemed">With used cards</option><option value="expired">With expired cards</option><option value="cancelled">With cancelled cards</option>
            </select></div>
            <div v-if="loading" class="admin-loading">Loading labels…</div>
            <div v-else class="admin-table-scroll"><table class="history-labels"><thead><tr><th>Label</th><th>Cards</th><th>Status overview</th><th>Latest creation</th><th>Actions</th></tr></thead><tbody>
                <template v-for="row in rows" :key="row.label">
                    <tr class="history-label-row" :class="{ 'is-expanded': expandedLabel === row.label }">
                        <td><button class="history-label-button" :aria-expanded="expandedLabel === row.label" :aria-controls="`qr-label-${row.latest_id}`" @click="toggleDetails(row.label)"><span>{{ expandedLabel === row.label ? '▾' : '▸' }}</span><b>{{ row.label }}</b></button><small>{{ row.type_count > 1 ? 'Mixed access' : row.type === 'ppv' ? 'PPV' : 'Subscription' }} · {{ row.batch_count }} print batches</small></td>
                        <td><b>{{ row.code_count }}</b></td>
                        <td><div class="history-counts"><span v-if="row.available_count" class="is-available">{{ row.available_count }} available</span><span v-if="row.redeemed_count" class="is-redeemed">{{ row.redeemed_count }} used</span><span v-if="row.expired_count">{{ row.expired_count }} expired</span><span v-if="row.cancelled_count">{{ row.cancelled_count }} cancelled</span></div></td>
                        <td>{{ formatDate(row.latest_at) }}</td>
                        <td><button class="admin-secondary" @click="toggleDetails(row.label)">{{ expandedLabel === row.label ? 'Hide list' : 'View list' }}</button> <button v-if="row.batch_count === 1 && row.single_count === 0" class="admin-secondary" :disabled="saving || rendering || printing" @click="openBatch(row.batch_id)">A4 PDF</button></td>
                    </tr>
                    <tr v-if="expandedLabel === row.label" class="history-detail-row"><td colspan="5">
                        <section :id="`qr-label-${row.latest_id}`" class="history-detail">
                            <div class="redemption-toolbar"><div><h3>{{ row.label }}</h3><small>{{ detailTotal }} cards · Listed in PDF reference order</small></div><select v-model="detailFilter" aria-label="Filter cards under this label" @change="loadDetails(1)"><option value="">All cards</option><option value="available">Available</option><option value="redeemed">Used</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select></div>
                            <StatusPanel tone="error" :message="detailError" />
                            <div v-if="detailLoading" class="admin-loading">Loading card list…</div>
                            <div v-else class="admin-table-scroll"><table class="history-codes"><thead><tr><th>PDF No.</th><th>Access</th><th>Status</th><th>Expiry</th><th>Activation</th><th>Actions</th></tr></thead><tbody>
                                <tr v-for="code in detailRows" :key="code.id"><td><b class="qr-reference">{{ code.qr_number || `#${String(code.id).padStart(4, '0')}` }}</b></td><td>{{ code.type === 'ppv' ? `PPV · ${code.content_type || 'movie'}` : 'Subscription' }}</td><td>{{ code.status }}</td><td>{{ formatDate(code.expires_at) }}</td><td><template v-if="code.redeemed_at">{{ code.redeemed_by }}<small>{{ code.result?.category_grant ? `Category · ${code.result.device_type}` : `Device #${code.device_id}` }} · {{ code.result?.action }} · {{ formatDate(code.redeemed_at) }}</small></template><span v-else>—</span></td><td><template v-if="code.status === 'available'"><button class="admin-secondary" @click="preview(code)">View QR</button> <button class="admin-secondary" :disabled="cancelling === code.id" @click="cancel(code)">Cancel</button> </template><button v-if="code.batch_id" class="admin-secondary" :disabled="saving || rendering || printing" @click="openBatch(code.batch_id)">PDF</button></td></tr>
                                <tr v-if="!detailRows.length"><td colspan="6">No cards match this filter.</td></tr>
                            </tbody></table></div>
                            <div class="redemption-toolbar"><button class="admin-secondary" :disabled="detailPage <= 1 || detailLoading" @click="loadDetails(detailPage - 1)">Previous cards</button><span>{{ detailPage }} / {{ detailLastPage }}</span><button class="admin-secondary" :disabled="detailPage >= detailLastPage || detailLoading" @click="loadDetails(detailPage + 1)">Next cards</button></div>
                        </section>
                    </td></tr>
                </template>
                <tr v-if="!rows.length"><td colspan="5">No labels found.</td></tr>
            </tbody></table></div>
            <div class="redemption-toolbar"><button class="admin-secondary" :disabled="page <= 1 || loading" @click="changeGroupPage(page - 1)">Previous labels</button><span>{{ page }} / {{ lastPage }}</span><button class="admin-secondary" :disabled="page >= lastPage || loading" @click="changeGroupPage(page + 1)">Next labels</button></div>
        </section>
        <dialog ref="previewDialog" class="preview-dialog" :class="{ 'is-pdf': previewMode === 'pdf' }" aria-labelledby="qr-preview-title" :aria-busy="previewLoading || rendering" @cancel.prevent="closePreview" @close="onPreviewClosed" @click="onPreviewBackdrop">
            <header class="preview-header">
                <div><span class="studio-kicker">{{ previewMode === 'qr' ? 'ACTIVATION QR' : 'A4 PDF PREVIEW' }}</span><h2 id="qr-preview-title">{{ previewMode === 'qr' ? (selected?.label || previewTarget?.label || 'Activation QR') : (batch?.label || 'A4 print preview') }}</h2><p v-if="previewMode === 'pdf' && batch">{{ batch.page_count }} A4 sheets · {{ batch.codes.length }} QR cards · {{ batch.target_name }}</p></div>
                <div class="preview-actions"><button v-if="previewMode === 'pdf' && batch" class="admin-primary" :disabled="previewLoading || rendering || printing" @click="printBatch">{{ printing ? 'Preparing print…' : 'Print / Save PDF' }}</button><button class="admin-secondary preview-close" type="button" autofocus :disabled="printing" aria-label="Close preview" @click="closePreview">✕</button></div>
            </header>
            <div class="preview-content" :class="{ 'qr-content': previewMode === 'qr' }">
                <StatusPanel tone="error" :message="previewError" />
                <div v-if="previewLoading || rendering" class="preview-loading" role="status" aria-live="polite">{{ previewMode === 'qr' ? 'Preparing QR…' : rendering ? `Preparing print sheets… ${renderedCount} cards rendered.` : 'Loading print batch…' }}</div>
                <template v-else-if="previewMode === 'qr' && selected"><img :src="selected.image" alt="Single-use activation QR" class="redemption-qr" /><b class="qr-reference">{{ selected.qr_number || `#${String(selected.id).padStart(4, '0')}` }}</b><p>Single use · {{ selected.type === 'ppv' ? 'PPV' : 'Subscription' }}</p><button class="admin-primary" type="button" @click="download">Download PNG</button><small>Keep this QR private. Anyone with the code can claim it once.</small></template>
                <template v-else-if="previewMode === 'pdf' && batch"><p class="print-help">Choose A4 portrait, 100% scale, and turn off browser headers and footers. Select “Save as PDF” to download.</p><iframe :srcdoc="printHtml" title="A4 activation QR sheet preview" class="sheet-frame" sandbox="allow-same-origin" /></template>
                <button v-else-if="previewError" class="admin-secondary preview-retry" @click="previewMode === 'qr' ? preview(previewTarget) : openBatch(lastBatchId)">Try again</button>
            </div>
        </dialog>
    </div>
</template>

<style scoped>
.redemption-top { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 20px; margin-bottom: 20px; }
.redemption-form { padding: 24px; }
.redemption-form { display: grid; gap: 16px; }
.redemption-form fieldset { display: grid; gap: 18px; margin: 0; padding: 0; border: 0; min-width: 0; }
.redemption-form fieldset:disabled { opacity: .6; }
.redemption-form label { display: grid; gap: 6px; }
input, select { padding: 10px 12px; border: 1px solid var(--a-line); border-radius: 8px; background: var(--a-bg); color: var(--a-text); min-width: 0; }
.redemption-form p { opacity: .75; line-height: 1.5; }
.redemption-qr { background: white; width: 288px; max-width: 100%; height: auto; border-radius: 12px; }
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
.studio-cover>small { color: #c3e5df; font-size: 11px; }.reopen-batch { margin-bottom: 20px; }
.preview-dialog { width: min(440px, calc(100vw - 32px)); max-width: none; max-height: calc(100dvh - 32px); padding: 0; border: 1px solid var(--a-line); border-radius: 18px; background: var(--a-bg); color: var(--a-text); box-shadow: 0 30px 100px #0008; }
.preview-dialog[open] { display: flex; flex-direction: column; }.preview-dialog.is-pdf { width: min(1120px, calc(100vw - 32px)); height: min(1100px, calc(100dvh - 32px)); }
.preview-dialog::backdrop { background: #020713b8; backdrop-filter: blur(5px); }
.preview-header { display: flex; flex: none; justify-content: space-between; gap: 20px; align-items: flex-start; padding: 20px 24px; border-bottom: 1px solid var(--a-line); }.preview-header>div:first-child { min-width: 0; }.preview-header h2 { margin: 0; font-size: 21px; overflow-wrap: anywhere; }.preview-header p { margin: 8px 0 0; color: var(--a-muted); font-size: 12px; }.preview-header .studio-kicker { margin-bottom: 7px; }
.preview-actions { display: flex; gap: 10px; align-items: center; flex: none; }.preview-close { min-width: 38px; }.preview-content { min-height: 0; overflow: auto; }.preview-dialog.is-pdf .preview-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }.preview-content>.admin-status { flex: none; margin: 12px 16px; }
.qr-content { display: flex; flex-direction: column; gap: 16px; align-items: center; padding: 24px; }.qr-content p { margin: 0; }.qr-content>small { color: var(--a-muted); text-align: center; font-size: 12px; line-height: 1.5; }
.preview-loading { display: grid; place-items: center; min-height: 180px; padding: 28px; text-align: center; color: var(--a-muted); }.preview-dialog.is-pdf .preview-loading { flex: 1; }.preview-retry { margin: 20px; align-self: center; }
.print-help { flex: none; padding: 0 20px; font-size: 12px; color: var(--a-muted); }.sheet-frame { display: block; width: 100%; flex: 1; min-height: 0; border: 0; background: #e8edf1; }
.history-label-button { display: inline-flex; align-items: center; gap: 10px; border: 0; background: none; color: var(--a-text); cursor: pointer; padding: 4px 0; text-align: left; }.history-label-button>span { color: var(--a-cyan); }
.history-counts { display: flex; flex-wrap: wrap; gap: 6px; }.history-counts span { border: 1px solid var(--a-line); border-radius: 20px; padding: 4px 8px; font-size: 10px; color: var(--a-muted); }.history-counts .is-available { color: var(--a-cyan); }.history-counts .is-redeemed { color: var(--a-lime); }
.history-detail-row>td { padding: 0 !important; }.history-detail { padding: 12px 18px; background: var(--a-bg); border-block: 1px solid var(--a-line); }.history-detail h3 { margin: 0 0 6px; }.history-detail small, .redemption-toolbar>div>small { color: var(--a-muted); font-size: 11px; }
.history-label-row.is-expanded { background: var(--a-bg); }.qr-reference { font-family: ui-monospace, monospace; color: var(--a-cyan); white-space: nowrap; }
@media (max-width: 640px) { .redemption-toolbar { flex-wrap: wrap; }.studio-cover { padding: 24px; }.preview-header { padding: 16px; flex-wrap: wrap; gap: 12px; }.preview-actions { margin-left: auto; }.qr-content { padding: 20px; } }
</style>
