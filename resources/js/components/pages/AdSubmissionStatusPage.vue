<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import PublicLoginCard from '../PublicLoginCard.vue';
import { authenticatedFetch, clearPublicSession, getPublicSession, publicHeaders } from '../../lib/publicAuth';

const token = window.location.pathname.split('/').filter(Boolean).at(-1);
const isPaymentPage = window.location.pathname.startsWith('/advertise/payment/');
const session = ref(getPublicSession());
const loading = ref(isPaymentPage || Boolean(session.value)); const saving = ref(false); const paying = ref(false); const error = ref(''); const notice = ref(''); const item = ref(null);
const revision = reactive({ ads_name: '', description: '', media_url: '', destination_url: '', requested_start_date: '', requested_period_days: 30, response_note: '' });
const statusLabels = { pending_review: 'Pending review', changes_requested: 'Changes requested', approved: 'Approved', rejected: 'Rejected' };
const label = computed(() => statusLabels[item.value?.status] || item.value?.status || 'Unknown');
const invoice = computed(() => item.value?.campaign?.invoice || null);
const money = (value, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(value || 0);

function headers(json = false) { return publicHeaders(json); }
function apiError(payload, fallback) { const details = payload?.error?.details; return (details && Object.values(details).flat()[0]) || payload?.error?.message || payload?.message || fallback; }
function fillRevision() { if (!item.value) return; ['ads_name','description','media_url','destination_url','requested_start_date','requested_period_days'].forEach((key) => revision[key] = item.value[key] ?? ''); }
async function load() {
    loading.value = true; error.value = '';
    try { const endpoint = isPaymentPage ? `/api/v4/ad-submissions/status/${encodeURIComponent(token)}/payment` : `/api/v4/ad-submissions/status/${encodeURIComponent(token)}`; const response = await authenticatedFetch(endpoint, { headers: headers() }); const payload = await response.json().catch(() => null); if (response.status === 401) { clearPublicSession(); session.value = null; } if (!response.ok || payload?.success === false) throw new Error(apiError(payload, 'Payment details load theih lo.')); item.value = payload.data; fillRevision(); }
    catch (reason) { error.value = reason.message || 'Submission status load theih lo.'; }
    finally { loading.value = false; }
}
async function resubmit() {
    saving.value = true; error.value = ''; notice.value = '';
    try { const response = await authenticatedFetch(`/api/v4/ad-submissions/status/${encodeURIComponent(token)}/resubmit`, { method: 'POST', headers: headers(true), body: JSON.stringify(revision) }); const payload = await response.json().catch(() => null); if (!response.ok || payload?.success === false) throw new Error(apiError(payload, 'Resubmit theih lo.')); item.value = payload.data; notice.value = 'Revised details submit leh a ni.'; }
    catch (reason) { error.value = reason.message || 'Resubmit theih lo.'; }
    finally { saving.value = false; }
}
function loadRazorpay() {
    if (window.Razorpay) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const script = document.createElement('script'); script.src = 'https://checkout.razorpay.com/v1/checkout.js'; script.onload = resolve; script.onerror = () => reject(new Error('Payment checkout load theih lo.')); document.head.appendChild(script);
    });
}
async function payInvoice() {
    paying.value = true; error.value = ''; notice.value = '';
    let checkoutOpened = false;
    try {
        await loadRazorpay();
        const response = await authenticatedFetch(`/api/v4/ad-submissions/status/${encodeURIComponent(token)}/payments/razorpay/order`, { method: 'POST', headers: headers(true), body: '{}' });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false) throw new Error(apiError(payload, 'Payment order siam theih lo.'));
        const data = payload.data;
        if (data.invoice_status === 'paid') { await load(); return; }
        const checkout = new window.Razorpay({
            key: data.key_id, amount: data.order.amount, currency: data.order.currency, order_id: data.order.id,
            name: 'Zo Stream Ads', description: data.invoice_no,
            prefill: { name: data.contact_name, email: data.contact_email || '', contact: data.contact_phone || '' },
            handler: async (payment) => {
                try {
                    const verify = await authenticatedFetch(`/api/v4/ad-submissions/status/${encodeURIComponent(token)}/payments/razorpay/verify`, { method: 'POST', headers: headers(true), body: JSON.stringify(payment) });
                    const verified = await verify.json().catch(() => null);
                    if (!verify.ok || verified?.success === false) throw new Error(apiError(verified, 'Payment verify theih lo.'));
                    notice.value = 'Payment successful. I campaign activate a ni.'; await load();
                } catch (reason) { error.value = reason.message || 'Payment verify theih lo.'; }
                finally { paying.value = false; }
            },
            theme: { color: '#17bff3' },
            modal: { ondismiss: () => { paying.value = false; } },
        });
        checkout.open();
        checkoutOpened = true;
    } catch (reason) { error.value = reason.message || 'Payment start theih lo.'; }
    finally { if (!checkoutOpened) paying.value = false; }
}
function loggedIn(value) { session.value = value; load(); }
function logout() { clearPublicSession(); session.value = null; item.value = null; error.value = ''; }
onMounted(() => { if (isPaymentPage || session.value) load(); });
</script>

<template>
    <main class="ad-status-page section-pad">
        <section class="ad-status-wrap">
            <a href="/advertise" class="ad-back">← Advertise page</a>
            <PublicLoginCard v-if="!session && !isPaymentPage" @authenticated="loggedIn" />
            <div v-else-if="loading" class="ad-status-state">Loading submission…</div>
            <div v-else-if="error && !item" class="ad-status-state error"><h1>Status load theih lo</h1><p>{{ error }}</p></div>
            <template v-else-if="item">
                <header class="ad-status-header"><div><span>{{ isPaymentPage ? 'Ad payment' : 'Submission status' }}</span><h1>{{ item.reference_no }}</h1><p>{{ item.ads_name }} · {{ item.business_name }}</p></div><div class="ad-status-actions"><b :class="item.status">{{ label }}</b><button v-if="session" type="button" @click="logout">Logout</button></div></header>
                <p v-if="notice" class="ad-notice">{{ notice }}</p><p v-if="error" class="ad-error">{{ error }}</p>
                <section v-if="item.status === 'changes_requested'" class="ad-review-message"><span>Admin requested changes</span><p>{{ item.review_note }}</p></section>
                <section v-if="item.status === 'rejected'" class="ad-review-message rejected"><span>Rejection reason</span><p>{{ item.rejection_reason }}</p></section>
                <section v-if="item.status === 'approved'" class="ad-approved"><span>✓</span><div><b>Your ad is approved</b><p>{{ item.campaign?.status === 'pending_payment' ? 'Campaign activate turin invoice pay rawh.' : 'Campaign active/published a ni.' }}</p></div><a v-if="item.ad_url" :href="item.ad_url" target="_blank">Published ad en rawh ↗</a></section>
                <section v-if="invoice" class="ad-invoice"><header><div><span>Campaign invoice</span><h2>{{ invoice.invoice_no }}</h2></div><b :class="invoice.status">{{ invoice.status }}</b></header><dl><div><dt>Billing model</dt><dd>{{ item.billing_model }}</dd></div><div><dt>Placement</dt><dd>{{ item.placement_code }}</dd></div><div><dt>Rate</dt><dd>{{ money(item.quoted_rate, item.currency) }}</dd></div><div><dt>Total</dt><dd>{{ money(invoice.total, invoice.currency) }}</dd></div></dl><button v-if="invoice.status !== 'paid'" class="primary-button" :disabled="paying" @click="payInvoice">{{ paying ? 'Opening payment…' : `Pay ${money(invoice.total, invoice.currency)}` }}</button><p v-else>✓ Payment complete · Campaign {{ item.campaign.status }}</p></section>
                <section v-if="!isPaymentPage" class="ad-status-details"><h2>Campaign details</h2><dl><div><dt>Ad type</dt><dd>{{ item.type }}</dd></div><div><dt>Period</dt><dd>{{ item.requested_period_days }} days</dd></div><div><dt>Billing</dt><dd>{{ item.billing_model }} · {{ item.target_quantity || 'duration based' }}</dd></div><div><dt>Estimated price</dt><dd>{{ money(item.quoted_amount, item.currency) }}</dd></div><div v-if="item.campaign"><dt>Campaign status</dt><dd>{{ item.campaign.status }}</dd></div><div v-if="item.campaign"><dt>Delivery</dt><dd>{{ item.campaign.consumed_quantity }} / {{ item.campaign.target_quantity || 'duration' }}</dd></div><div v-if="item.campaign"><dt>Accrued usage</dt><dd>{{ money(item.campaign.accrued_amount, item.campaign.currency) }}</dd></div><div><dt>Preferred start</dt><dd>{{ item.requested_start_date || 'Not specified' }}</dd></div><div><dt>Submitted</dt><dd>{{ item.submitted_at ? new Date(item.submitted_at).toLocaleString() : '—' }}</dd></div><div class="wide"><dt>Description</dt><dd>{{ item.description || '—' }}</dd></div><div class="wide"><dt>Media</dt><dd><a v-if="item.media_url" :href="item.media_url" target="_blank">Media open ↗</a><span v-else>Uploaded feature image</span></dd></div></dl></section>
                <form v-if="item.status === 'changes_requested'" class="ad-revision-form" @submit.prevent="resubmit"><header><span>Revise submission</span><h2>Requested details siam tha rawh</h2></header><label>Ad title<input v-model.trim="revision.ads_name" required></label><label>Description<textarea v-model.trim="revision.description" rows="4"></textarea></label><label>Media URL<input v-model.trim="revision.media_url" type="url"></label><label>Destination URL<input v-model.trim="revision.destination_url" type="url"></label><div><label>Preferred start<input v-model="revision.requested_start_date" type="date"></label><label>Period (days)<input v-model.number="revision.requested_period_days" type="number" min="1" max="366" required></label></div><label>Message to reviewer<textarea v-model.trim="revision.response_note" rows="3"></textarea></label><button class="primary-button" :disabled="saving">{{ saving ? 'Submitting…' : 'Resubmit for review' }}</button></form>
                <section v-if="!isPaymentPage" class="ad-timeline"><h2>Timeline</h2><article v-for="event in item.events" :key="event.id"><i></i><div><b>{{ statusLabels[event.to_status] || event.action }}</b><p v-if="event.note">{{ event.note }}</p><time>{{ new Date(event.created_at).toLocaleString() }}</time></div></article></section>
            </template>
        </section>
    </main>
</template>

<style scoped>
.ad-status-page{min-height:850px;padding-top:145px;padding-bottom:110px}.ad-status-wrap{width:min(100%,980px);margin:auto}.ad-back{display:inline-flex;margin-bottom:24px;color:var(--cyan);font-size:12px;font-weight:800}.ad-status-header{display:flex;align-items:flex-end;justify-content:space-between;gap:25px;padding:35px;border:1px solid var(--glass-line);border-radius:23px;background:rgba(16,21,29,.66)}.ad-status-header span,.ad-review-message span,.ad-revision-form header span,.ad-invoice header span{color:var(--cyan);font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase}.ad-status-header h1{margin:10px 0 4px;font:800 clamp(30px,5vw,48px) var(--display);letter-spacing:-2px}.ad-status-header p{margin:0;color:var(--muted)}.ad-status-header>b{padding:10px 14px;border-radius:999px;background:rgba(255,199,74,.12);color:#ffd36f;font-size:11px;text-transform:uppercase}.ad-status-header>b.approved{background:rgba(130,225,130,.12);color:#8deb94}.ad-status-header>b.rejected{background:rgba(255,100,110,.12);color:#ff9da4}.ad-status-header>b.changes_requested{background:rgba(23,191,243,.12);color:var(--cyan)}.ad-review-message,.ad-approved,.ad-status-details,.ad-revision-form,.ad-timeline,.ad-invoice{margin-top:20px;padding:28px;border:1px solid var(--line);border-radius:19px;background:rgba(255,255,255,.035)}.ad-review-message{border-color:rgba(23,191,243,.25)}.ad-review-message.rejected{border-color:rgba(255,100,110,.25)}.ad-review-message p{margin:11px 0 0;line-height:1.7}.ad-approved{display:flex;align-items:center;gap:16px;border-color:rgba(130,225,130,.24)}.ad-approved>span{display:grid;width:44px;height:44px;place-items:center;border-radius:50%;background:#8deb94;color:#08250d;font-weight:900}.ad-approved div{margin-right:auto}.ad-approved b{font:750 17px var(--display)}.ad-approved p{margin:4px 0 0;color:var(--muted);font-size:12px}.ad-approved a{color:#8deb94;font-size:12px;font-weight:800}.ad-invoice{border-color:rgba(23,191,243,.25)}.ad-invoice header{display:flex;align-items:center;justify-content:space-between}.ad-invoice h2{margin:7px 0 0;font:750 23px var(--display)}.ad-invoice header>b{padding:7px 10px;border-radius:999px;background:rgba(255,199,74,.12);color:#ffd36f;font-size:10px;text-transform:uppercase}.ad-invoice header>b.paid{background:rgba(130,225,130,.12);color:#8deb94}.ad-invoice dl{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;margin:22px 0;background:var(--line)}.ad-invoice dl>div{padding:14px;background:#101319}.ad-invoice dt{color:#737d88;font-size:8px;text-transform:uppercase}.ad-invoice dd{margin:7px 0 0;font-size:13px}.ad-invoice>p{color:#8deb94;font-size:13px}.ad-status-details h2,.ad-revision-form h2,.ad-timeline h2{margin:0 0 20px;font:750 21px var(--display)}.ad-status-details dl{display:grid;grid-template-columns:1fr 1fr;gap:1px;margin:0;background:var(--line)}.ad-status-details dl>div{padding:17px;background:#101319}.ad-status-details .wide{grid-column:1/-1}.ad-status-details dt{color:#737d88;font-size:9px;font-weight:800;text-transform:uppercase}.ad-status-details dd{margin:7px 0 0;color:#d8dde2;font-size:13px;overflow-wrap:anywhere}.ad-status-details a{color:var(--cyan)}.ad-revision-form{display:grid;grid-template-columns:1fr 1fr;gap:17px}.ad-revision-form header,.ad-revision-form>label:nth-of-type(2),.ad-revision-form>label:nth-of-type(5),.ad-revision-form>button{grid-column:1/-1}.ad-revision-form header h2{margin:8px 0 4px}.ad-revision-form label{display:grid;gap:7px;color:#adb5be;font-size:11px;font-weight:700}.ad-revision-form input,.ad-revision-form textarea{padding:12px;border:1px solid var(--line);border-radius:10px;outline:0;background:#0a0d11;color:#fff}.ad-revision-form>div{display:grid;grid-template-columns:1fr 1fr;gap:15px}.ad-notice,.ad-error{padding:12px 15px;border-radius:10px;font-size:12px}.ad-notice{background:rgba(130,225,130,.1);color:#a4eeaa}.ad-error{background:rgba(255,100,110,.1);color:#ffadb3}.ad-timeline article{position:relative;display:flex;gap:14px;padding:0 0 21px}.ad-timeline article:last-child{padding-bottom:0}.ad-timeline article>i{width:10px;height:10px;margin-top:4px;border-radius:50%;background:var(--cyan);box-shadow:0 0 0 5px rgba(23,191,243,.09)}.ad-timeline article:not(:last-child)>i:after{content:'';position:absolute;top:17px;bottom:3px;left:4px;width:1px;background:var(--line)}.ad-timeline article div{display:grid;gap:5px}.ad-timeline article b{font-size:12px;text-transform:capitalize}.ad-timeline article p{margin:0;color:#aab1ba;font-size:12px}.ad-timeline time{color:#68727d;font-size:9px}.ad-status-state{padding:60px;text-align:center;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.03);color:var(--muted)}@media(max-width:650px){.ad-status-page{padding-top:105px}.ad-status-header{align-items:flex-start;flex-direction:column;padding:24px}.ad-status-details dl,.ad-invoice dl{grid-template-columns:1fr}.ad-status-details .wide{grid-column:auto}.ad-revision-form{grid-template-columns:1fr}.ad-revision-form>*{grid-column:1!important}.ad-revision-form>div{grid-template-columns:1fr}.ad-approved{align-items:flex-start;flex-wrap:wrap}.ad-approved a{width:100%;margin-left:60px}}
</style>

<style scoped>
.ad-status-actions{display:grid;justify-items:end;gap:9px}
.ad-status-actions>b{padding:10px 14px;border-radius:999px;background:rgba(255,199,74,.12);color:#ffd36f;font-size:11px;text-transform:uppercase}
.ad-status-actions>b.approved{background:rgba(130,225,130,.12);color:#8deb94}
.ad-status-actions>b.rejected{background:rgba(255,100,110,.12);color:#ff9da4}
.ad-status-actions>b.changes_requested{background:rgba(23,191,243,.12);color:var(--cyan)}
.ad-status-actions button{border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:10px;text-decoration:underline}
</style>
