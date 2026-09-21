<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import PublicLoginCard from '../PublicLoginCard.vue';
import { authenticatedFetch, clearPublicSession, getPublicSession, publicHeaders } from '../../lib/publicAuth';

const session = ref(getPublicSession());
const submitting = ref(false);
const error = ref('');
const result = ref(null);
const form = reactive({
    business_name: '', contact_name: '', contact_phone: '', contact_email: '',
    ads_name: '', description: '', type: 'image', media_url: '', destination_url: '',
    requested_start_date: '', requested_period_days: 30, placement_code: 'home_top',
    billing_model: 'FLAT', target_quantity: 10000, daily_budget: '', terms_accepted: false, website: '',
});
const mediaFile = ref(null);
const featureImage = ref(null);
const galleryImages = ref([]);
const pricing = ref([]);
const pricingLoading = ref(true);
const previewDevice = ref('desktop');
const mediaObjectUrl = ref('');
const featureObjectUrl = ref('');
const today = new Date().toISOString().slice(0, 10);

const mediaType = computed(() => form.type === 'video' ? 'video' : 'image');
const placements = computed(() => pricing.value.filter((slot) => slot.media_type === mediaType.value));
const selectedPlacement = computed(() => placements.value.find((slot) => slot.code === form.placement_code));
const rateOptions = computed(() => selectedPlacement.value?.rates || []);
const selectedRate = computed(() => rateOptions.value.find((rate) => rate.billing_model === form.billing_model));
const estimate = computed(() => {
    if (!selectedRate.value) return 0;
    const quantity = form.billing_model === 'FLAT' ? Number(form.requested_period_days || 0) : Number(form.target_quantity || 0);
    const units = form.billing_model === 'CPM' ? quantity / 1000 : quantity;
    return Math.max(Number(selectedRate.value.minimum_charge || 0), Math.round(units * Number(selectedRate.value.rate) * 100) / 100);
});
const targetLabel = computed(() => ({ CPM: 'Target impressions', CPC: 'Target clicks', CPV: 'Target valid views' }[form.billing_model] || 'Target'));
const money = (value) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: selectedRate.value?.currency || 'INR', maximumFractionDigits: 2 }).format(value || 0);
const previewSource = computed(() => mediaObjectUrl.value || form.media_url || featureObjectUrl.value);
const previewIsVideo = computed(() => form.type === 'video' || mediaFile.value?.type?.startsWith('video/'));
const previewIsWebsite = computed(() => form.type === 'website');
const previewTitle = computed(() => form.ads_name || form.business_name || 'Your campaign title');
const previewDescription = computed(() => form.description || 'Your campaign message will appear here as your audience browses Zo Stream.');
const previewPlacement = computed(() => selectedPlacement.value?.label || 'Home placement');
const previewAtTop = computed(() => String(form.placement_code).includes('top'));

function syncPricingSelection() {
    if (!placements.value.some((slot) => slot.code === form.placement_code)) form.placement_code = placements.value[0]?.code || '';
    const rates = selectedPlacement.value?.rates || [];
    if (!rates.some((rate) => rate.billing_model === form.billing_model)) form.billing_model = rates[0]?.billing_model || '';
}

async function loadPricing() {
    try {
        const response = await fetch('/api/v4/ad-pricing', { headers: { Accept: 'application/json', 'X-Client-Platform': 'web', 'X-Client-Version': '1.0' } });
        const payload = await response.json();
        pricing.value = payload?.data?.placements || [];
        syncPricingSelection();
    } catch { error.value = 'Pricing load theih lo. Page refresh leh rawh.'; }
    finally { pricingLoading.value = false; }
}

watch(() => form.type, syncPricingSelection);
watch(() => form.placement_code, syncPricingSelection);
watch(mediaFile, (file) => {
    if (mediaObjectUrl.value) URL.revokeObjectURL(mediaObjectUrl.value);
    mediaObjectUrl.value = file ? URL.createObjectURL(file) : '';
});
watch(featureImage, (file) => {
    if (featureObjectUrl.value) URL.revokeObjectURL(featureObjectUrl.value);
    featureObjectUrl.value = file ? URL.createObjectURL(file) : '';
});
onMounted(loadPricing);
onBeforeUnmount(() => {
    if (mediaObjectUrl.value) URL.revokeObjectURL(mediaObjectUrl.value);
    if (featureObjectUrl.value) URL.revokeObjectURL(featureObjectUrl.value);
});

function files(event, target) {
    if (target === 'gallery') galleryImages.value = [...event.target.files].slice(0, 4);
    else if (target === 'media') mediaFile.value = event.target.files[0] || null;
    else featureImage.value = event.target.files[0] || null;
}

function apiError(payload) {
    const details = payload?.error?.details;
    const first = details && typeof details === 'object' ? Object.values(details).flat()[0] : null;
    return first || payload?.error?.message || payload?.message || 'Ad submit theih lo. Field te check leh rawh.';
}

async function submit() {
    if (!session.value?.access_token) { error.value = 'Ad submit tur chuan login rawh.'; return; }
    submitting.value = true; error.value = '';
    try {
        const body = new FormData();
        Object.entries(form).forEach(([key, value]) => {
            if (value !== '' && value != null) body.append(key, value === true ? '1' : value === false ? '0' : String(value));
        });
        if (mediaFile.value) body.append('media_file', mediaFile.value);
        if (featureImage.value) body.append('feature_image', featureImage.value);
        galleryImages.value.forEach((file) => body.append('gallery_images[]', file));
        const response = await authenticatedFetch('/api/v4/ad-submissions', {
            method: 'POST',
            headers: publicHeaders(false),
            body,
        });
        const payload = await response.json().catch(() => null);
        if (response.status === 401) { clearPublicSession(); session.value = null; }
        if (!response.ok || payload?.success === false) throw new Error(apiError(payload));
        result.value = payload.data;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (reason) {
        if (reason?.status === 401) session.value = null;
        error.value = reason.message || 'Ad submit theih lo.';
    } finally { submitting.value = false; }
}

function loggedIn(value) { session.value = value; }
function logout() { clearPublicSession(); session.value = null; result.value = null; }
</script>

<template>
    <main class="ad-public-page">
        <section class="ad-public-hero section-pad">
            <div>
                <span>Advertise with Zo Stream</span>
                <h1>I business chu<br><b>audience hnenah thlen rawh.</b></h1>
                <p>Banner, website leh video ad i submit thei. Kan admin team-in review hnuah approval status kan pe ang.</p>
            </div>
        </section>

        <PublicLoginCard v-if="!session" @authenticated="loggedIn" />

        <section v-else-if="result" class="ad-success section-pad">
            <div class="ad-success-card">
                <i>✓</i><span>Submission received</span>
                <h2>{{ result.submission.reference_no }}</h2>
                <p>I ad chu review pending a ni. Status link hi vawng tha rawh; private link a ni.</p>
                <a class="primary-button" :href="result.status_url">Submission status en rawh</a>
                <button type="button" class="ad-copy" @click="navigator.clipboard?.writeText(result.status_url)">Status link copy</button>
            </div>
        </section>

        <section v-else class="ad-form-layout section-pad">
            <form class="ad-submit-form" @submit.prevent="submit">
                <header><span>Campaign submission</span><h2>Ad details</h2><p><b>*</b> mark field te fill vek tur.</p></header>
                <p v-if="error" class="ad-form-error">{{ error }}</p>
                <input v-model="form.website" class="ad-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

                <fieldset><legend>Advertiser</legend><div class="ad-form-grid">
                    <label>Business name *<input v-model.trim="form.business_name" required maxlength="255"></label>
                    <label>Contact person *<input v-model.trim="form.contact_name" required maxlength="255"></label>
                    <label>Phone number *<input v-model.trim="form.contact_phone" required maxlength="40" inputmode="tel"></label>
                    <label>Email address<input v-model.trim="form.contact_email" type="email" maxlength="255"></label>
                </div></fieldset>

                <fieldset><legend>Campaign</legend><div class="ad-form-grid">
                    <label class="wide">Ad title *<input v-model.trim="form.ads_name" required maxlength="255"></label>
                    <label class="wide">Description<textarea v-model.trim="form.description" rows="5" maxlength="5000"></textarea></label>
                    <label>Ad type *<select v-model="form.type"><option value="image">Image/Banner</option><option value="video">Video</option><option value="website">Website</option></select></label>
                    <label>Period (days) *<input v-model.number="form.requested_period_days" type="number" min="1" max="366" required></label>
                    <label>Placement *<select v-model="form.placement_code" :disabled="pricingLoading"><option v-for="slot in placements" :key="slot.code" :value="slot.code">{{ slot.label }}</option></select></label>
                    <label>Billing model *<select v-model="form.billing_model"><option v-for="rate in rateOptions" :key="rate.billing_model" :value="rate.billing_model">{{ rate.billing_model }} — {{ rate.unit_label }}</option></select></label>
                    <label v-if="form.billing_model !== 'FLAT'">{{ targetLabel }} *<input v-model.number="form.target_quantity" type="number" min="1" max="1000000000" required></label>
                    <label>Daily budget (optional)<input v-model.number="form.daily_budget" type="number" min="1" step="0.01"></label>
                    <label>Preferred start date<input v-model="form.requested_start_date" type="date" :min="today"></label>
                    <label>Destination URL<input v-model.trim="form.destination_url" type="url" placeholder="https://example.com"></label>
                </div></fieldset>

                <section class="ad-price-quote"><div><span>Estimated campaign price</span><strong>{{ money(estimate) }}</strong><small v-if="selectedRate">{{ money(selectedRate.rate) }} {{ selectedRate.unit_label }} · minimum {{ money(selectedRate.minimum_charge) }}</small></div><p>Final rate server-in verify leh ang. Admin approval hnuah invoice siam a ni ang a, prepaid campaign chu payment success hnuah activate a ni ang.</p></section>

                <fieldset><legend>Creative media</legend><div class="ad-form-grid">
                    <label class="wide">Media URL<input v-model.trim="form.media_url" type="url" placeholder="https://... image or video URL"><small>Media URL emaw media file pakhat tal required.</small></label>
                    <label class="ad-file">Media file<input type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" @change="files($event, 'media')"><small>Image/video, maximum 50 MB</small></label>
                    <label class="ad-file">Feature image<input type="file" accept="image/jpeg,image/png,image/webp" @change="files($event, 'feature')"><small>Maximum 10 MB</small></label>
                    <label class="ad-file wide">Gallery images<input type="file" multiple accept="image/jpeg,image/png,image/webp" @change="files($event, 'gallery')"><small>Maximum 4 images, file tin 10 MB</small></label>
                </div></fieldset>

                <label class="ad-terms"><input v-model="form.terms_accepted" type="checkbox" required><span>Ka submit content hman phalna ka nei tih ka confirm a, <a href="/advertising-terms" target="_blank" rel="noopener noreferrer">Advertising Terms</a> leh <a href="/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a> ka chhiar a, ka pawm.</span></label>
                <button class="primary-button ad-submit-button" :disabled="submitting">{{ submitting ? 'Submitting…' : 'Submit for review' }}</button>
            </form>

            <aside class="ad-preview-panel">
                <div class="ad-preview-heading">
                    <div><span>Live preview</span><h2>{{ previewDevice === 'mobile' ? 'Mobile' : 'Desktop' }} home</h2></div>
                    <div class="ad-device-toggle" aria-label="Preview device">
                        <button type="button" :class="{ active: previewDevice === 'desktop' }" @click="previewDevice = 'desktop'">Desktop</button>
                        <button type="button" :class="{ active: previewDevice === 'mobile' }" @click="previewDevice = 'mobile'">Mobile</button>
                    </div>
                </div>

                <div class="ad-device-wrap" :class="previewDevice">
                    <div class="ad-device-frame">
                        <div class="ad-device-camera" />
                        <div class="ad-preview-app">
                            <header><b>ZO</b><span>HOME</span><i>⌕</i></header>
                            <div class="ad-preview-hero"><small>Featured in Mizo</small><strong>Stories worth watching.</strong></div>
                            <div v-if="!previewAtTop" class="ad-preview-rail"><b /><b /><b /></div>
                            <article class="ad-live-creative">
                                <video v-if="previewSource && previewIsVideo" :src="previewSource" muted autoplay loop playsinline />
                                <img v-else-if="previewSource && !previewIsWebsite" :src="previewSource" alt="Ad creative preview">
                                <div v-else-if="previewIsWebsite" class="ad-website-preview"><i>↗</i><small>WEBSITE CAMPAIGN</small><b>{{ form.destination_url || 'https://your-website.com' }}</b></div>
                                <div v-else class="ad-preview-placeholder"><i>＋</i><span>Upload creative media</span></div>
                                <em>Sponsored</em>
                                <div class="ad-preview-copy"><small>{{ form.business_name || 'YOUR BRAND' }}</small><strong>{{ previewTitle }}</strong><p>{{ previewDescription }}</p><button type="button">{{ form.destination_url ? 'Visit now' : 'Learn more' }} ↗</button></div>
                            </article>
                            <div class="ad-preview-rail after"><b /><b /><b /></div>
                        </div>
                    </div>
                </div>

                <div class="ad-preview-meta"><span>{{ previewPlacement }}</span><b>{{ form.type }} · {{ form.billing_model || 'Billing' }}</b></div>
                <div class="ad-signed-in"><small>Logged in</small><b>{{ session.uid }}</b><button type="button" @click="logout">Logout</button></div>
                <details class="ad-how" open><summary>How it works</summary><ol><li><b>01</b>Campaign details fill up</li><li><b>02</b>Admin review</li><li><b>03</b>WhatsApp payment link</li><li><b>04</b>Payment hnuah publish</li></ol><p>Payment complete hnuah chauh ad chu publish a ni ang.</p></details>
            </aside>
        </section>
    </main>
</template>

<style scoped>
.ad-public-page{padding-top:90px}.ad-public-hero{display:flex;min-height:430px;align-items:center;border-bottom:1px solid var(--line);background:radial-gradient(circle at 80% 20%,rgba(23,191,243,.14),transparent 31%)}.ad-public-hero>div{width:min(100%,1050px);margin:auto}.ad-public-hero span,.ad-submit-form header span,.ad-form-layout aside>span,.ad-success-card>span{color:var(--cyan);font-size:10px;font-weight:900;letter-spacing:2.3px;text-transform:uppercase}.ad-public-hero h1{margin:20px 0;font:800 clamp(45px,6vw,82px)/.98 var(--display);letter-spacing:-4px}.ad-public-hero h1 b{color:var(--cyan)}.ad-public-hero p{max-width:650px;color:var(--muted);font-size:16px;line-height:1.75}.ad-form-layout{display:grid;width:min(100%,1280px);grid-template-columns:310px minmax(0,1fr);gap:32px;margin:auto;padding-top:65px;padding-bottom:110px}.ad-form-layout aside{position:sticky;top:100px;height:max-content;padding:28px;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.035)}.ad-form-layout ol{display:grid;gap:19px;margin:26px 0;padding:0;list-style:none}.ad-form-layout li{display:flex;align-items:center;gap:13px;color:#c7cdd4;font-size:13px}.ad-form-layout li b{display:grid;width:38px;height:38px;place-items:center;border-radius:11px;background:rgba(23,191,243,.1);color:var(--cyan);font-size:10px}.ad-form-layout aside p{margin:25px 0 0;padding-top:20px;border-top:1px solid var(--line);color:var(--muted);font-size:12px;line-height:1.65}.ad-submit-form{padding:clamp(24px,4vw,48px);border:1px solid var(--glass-line);border-radius:25px;background:rgba(16,21,29,.65);box-shadow:var(--glass-shadow);backdrop-filter:blur(20px)}.ad-submit-form header h2{margin:10px 0 3px;font:750 34px var(--display)}.ad-submit-form header p{margin:0;color:var(--muted);font-size:12px}.ad-submit-form header p b{color:#ff7780}.ad-submit-form fieldset{margin:32px 0 0;padding:26px 0 0;border:0;border-top:1px solid var(--line)}.ad-submit-form legend{padding:0;color:#e7edf1;font:750 17px var(--display)}.ad-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:19px;margin-top:20px}.ad-form-grid label{display:grid;gap:8px;color:#afb6bf;font-size:12px;font-weight:700}.ad-form-grid .wide{grid-column:1/-1}.ad-form-grid input,.ad-form-grid select,.ad-form-grid textarea{width:100%;min-height:48px;padding:12px 14px;border:1px solid var(--line);border-radius:11px;outline:0;background:#0b0e13;color:#fff;font:inherit}.ad-form-grid textarea{resize:vertical}.ad-form-grid input:focus,.ad-form-grid select:focus,.ad-form-grid textarea:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(23,191,243,.08)}.ad-form-grid small{color:#68717c;font-size:10px;font-weight:500}.ad-file input{padding:10px}.ad-price-quote{display:grid;grid-template-columns:1fr 1.25fr;gap:25px;margin-top:25px;padding:22px;border:1px solid rgba(23,191,243,.25);border-radius:15px;background:rgba(23,191,243,.07)}.ad-price-quote div{display:grid;gap:6px}.ad-price-quote span{color:var(--cyan);font-size:9px;font-weight:900;letter-spacing:1.5px;text-transform:uppercase}.ad-price-quote strong{font:800 32px var(--display)}.ad-price-quote small,.ad-price-quote p{color:#89949f;font-size:10px;line-height:1.6}.ad-price-quote p{margin:0}.ad-terms{display:flex;align-items:flex-start;gap:11px;margin:28px 0;color:#9fa7b1;font-size:12px;line-height:1.6}.ad-terms input{margin-top:3px;accent-color:var(--cyan)}.ad-terms a{color:var(--cyan);font-weight:800;text-decoration:underline;text-underline-offset:3px}.ad-terms a:hover{color:var(--cyan-soft)}.ad-submit-button{width:100%}.ad-submit-button:disabled{cursor:wait;opacity:.55}.ad-form-error{margin:22px 0 0;padding:13px 15px;border:1px solid rgba(255,100,110,.28);border-radius:10px;background:rgba(255,100,110,.09);color:#ffb5ba;font-size:12px}.ad-honeypot{position:absolute!important;left:-10000px!important}.ad-success{display:grid;min-height:650px;place-items:center;padding-top:70px;padding-bottom:100px}.ad-success-card{width:min(100%,660px);padding:55px;text-align:center;border:1px solid rgba(23,191,243,.25);border-radius:27px;background:rgba(16,21,29,.7);box-shadow:var(--glass-shadow)}.ad-success-card i{display:grid;width:66px;height:66px;margin:0 auto 22px;place-items:center;border-radius:50%;background:var(--cyan);color:#00141b;font-size:30px;font-style:normal;font-weight:900}.ad-success-card h2{margin:14px 0;font:800 clamp(28px,5vw,44px) var(--display);letter-spacing:-1px}.ad-success-card p{margin:0 auto 28px;color:var(--muted);line-height:1.7}.ad-copy{display:block;margin:17px auto 0;background:transparent;color:var(--cyan);cursor:pointer;font-size:12px;font-weight:800}@media(max-width:800px){.ad-form-layout{grid-template-columns:1fr}.ad-form-layout aside{position:static}.ad-public-hero{min-height:380px}.ad-form-grid{grid-template-columns:1fr}.ad-form-grid .wide{grid-column:auto}}@media(max-width:650px){.ad-price-quote{grid-template-columns:1fr}}@media(max-width:550px){.ad-public-page{padding-top:68px}.ad-public-hero h1{letter-spacing:-2.7px}.ad-form-layout{padding-top:30px}.ad-submit-form{padding:23px;border-radius:19px}.ad-success-card{padding:35px 22px}}
</style>

<style scoped>
.ad-signed-in{display:grid;gap:5px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--line)}
.ad-signed-in small{color:var(--cyan);font-size:9px;font-weight:900;text-transform:uppercase}
.ad-signed-in b{overflow:hidden;color:#dce5e9;font-size:11px;text-overflow:ellipsis}
.ad-signed-in button{width:max-content;padding:0;border:0;background:transparent;color:#87929c;cursor:pointer;font-size:10px;text-decoration:underline}
</style>

<style scoped>
.ad-form-layout{width:min(100%,1360px);grid-template-columns:minmax(0,1fr) 390px;align-items:start;gap:28px}
.ad-preview-panel{position:sticky!important;top:100px!important;min-width:0;height:max-content!important;padding:18px!important;border:1px solid var(--glass-line)!important;border-radius:24px!important;background:rgba(11,16,22,.86)!important;box-shadow:var(--glass-shadow);backdrop-filter:blur(22px)}
.ad-preview-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:17px}
.ad-preview-heading span{color:var(--cyan);font-size:9px;font-weight:900;letter-spacing:1.8px;text-transform:uppercase}
.ad-preview-heading h2{margin:5px 0 0;font:750 19px var(--display)}
.ad-device-toggle{display:flex;padding:3px;border:1px solid var(--line);border-radius:10px;background:#080c11}
.ad-device-toggle button{padding:7px 8px;border:0;border-radius:7px;background:transparent;color:#77818b;cursor:pointer;font-size:9px;font-weight:800}
.ad-device-toggle button.active{background:var(--cyan);color:#021217}
.ad-device-wrap{display:grid;min-height:310px;place-items:center;padding:12px;border-radius:18px;background:radial-gradient(circle at 50% 0,rgba(23,191,243,.16),transparent 48%),#070a0e}
.ad-device-frame{position:relative;width:100%;overflow:hidden;border:5px solid #20262e;border-radius:15px;background:#090d12;box-shadow:0 25px 45px rgba(0,0,0,.42)}
.ad-device-wrap.desktop .ad-device-frame{aspect-ratio:16/10}
.ad-device-wrap.mobile .ad-device-frame{width:185px;aspect-ratio:9/16;border-width:6px;border-radius:25px}
.ad-device-camera{position:absolute;z-index:3;top:4px;left:50%;width:35px;height:4px;transform:translateX(-50%);border-radius:9px;background:#333a42}
.ad-preview-app{height:100%;overflow:hidden;background:#080c11;color:#fff}
.ad-preview-app>header{display:flex;height:13%;align-items:center;gap:6px;padding:6px 9px;border-bottom:1px solid rgba(255,255,255,.07);font-size:7px}
.ad-preview-app>header b{color:var(--cyan);font-size:11px}.ad-preview-app>header span{color:#65717d;letter-spacing:1px}.ad-preview-app>header i{margin-left:auto;font-style:normal}
.ad-preview-hero{display:grid;height:25%;align-content:end;padding:10px;background:linear-gradient(0deg,#080c11,transparent),radial-gradient(circle at 75% 35%,rgba(23,191,243,.4),transparent 35%),#15202a}
.ad-preview-hero small{color:var(--cyan);font-size:5px;text-transform:uppercase}.ad-preview-hero strong{margin-top:3px;font:700 12px var(--display)}
.ad-preview-rail{display:flex;height:18%;gap:5px;padding:8px}.ad-preview-rail b{flex:1;border-radius:4px;background:linear-gradient(135deg,#1b2630,#10161c)}.ad-preview-rail.after{height:16%}
.ad-live-creative{position:relative;min-height:31%;overflow:hidden;margin:0 8px;border:1px solid rgba(255,255,255,.13);border-radius:7px;background:linear-gradient(135deg,#142c37,#0c1117)}
.ad-live-creative>img,.ad-live-creative>video{position:absolute;width:100%;height:100%;object-fit:cover}
.ad-live-creative:after{position:absolute;inset:0;background:linear-gradient(90deg,rgba(2,6,9,.92),rgba(2,6,9,.2) 75%);content:''}
.ad-live-creative>em{position:absolute;z-index:2;top:5px;right:5px;padding:2px 4px;border-radius:3px;background:rgba(0,0,0,.68);color:#c9d0d5;font-size:4px;font-style:normal;text-transform:uppercase}
.ad-preview-copy{position:relative;z-index:2;display:grid;width:68%;gap:2px;padding:10px}.ad-preview-copy small{color:var(--cyan);font-size:4px;font-weight:900}.ad-preview-copy strong{overflow:hidden;font:750 10px var(--display);text-overflow:ellipsis;white-space:nowrap}
.ad-preview-copy p{display:-webkit-box;overflow:hidden;margin:0!important;padding:0!important;border:0!important;color:#abb5be!important;font-size:5px!important;line-height:1.35!important;-webkit-box-orient:vertical;-webkit-line-clamp:2}.ad-preview-copy button{width:max-content;margin-top:3px;padding:3px 5px;border:0;border-radius:3px;background:var(--cyan);color:#021217;font-size:4px;font-weight:900}
.ad-preview-placeholder,.ad-website-preview{position:absolute;inset:0;display:grid;place-content:center;gap:4px;text-align:center}.ad-preview-placeholder i,.ad-website-preview i{color:var(--cyan);font-size:18px;font-style:normal}.ad-preview-placeholder span,.ad-website-preview small{color:#81909a;font-size:6px}.ad-website-preview b{max-width:230px;overflow:hidden;color:#d9e2e7;font-size:7px;text-overflow:ellipsis;white-space:nowrap}
.ad-device-wrap.mobile .ad-preview-hero{height:22%}.ad-device-wrap.mobile .ad-preview-rail{height:15%}.ad-device-wrap.mobile .ad-live-creative{min-height:29%}.ad-device-wrap.mobile .ad-preview-copy{width:84%;padding:9px}
.ad-preview-meta{display:flex;justify-content:space-between;gap:8px;padding:12px 3px 16px;color:#7c8790;font-size:9px}.ad-preview-meta span{color:var(--cyan);font-weight:800}.ad-preview-meta b{text-transform:capitalize}
.ad-how{margin-top:16px;padding-top:16px;border-top:1px solid var(--line)}.ad-how summary{cursor:pointer;color:#dce5e9;font-size:11px;font-weight:800}.ad-how ol{display:grid;gap:9px;margin:13px 0;padding:0;list-style:none}.ad-how li{display:flex;align-items:center;gap:9px;color:#aab4bc;font-size:10px}.ad-how li b{display:grid;width:26px;height:26px;place-items:center;border-radius:7px;background:rgba(23,191,243,.1);color:var(--cyan);font-size:8px}.ad-how p{margin:10px 0 0!important;padding:0!important;border:0!important;color:#75818a!important;font-size:9px!important;line-height:1.5!important}
@media(max-width:1050px){.ad-form-layout{grid-template-columns:minmax(0,1fr) 340px}}
@media(max-width:850px){.ad-form-layout{grid-template-columns:1fr}.ad-preview-panel{position:static!important;grid-row:1}.ad-form-grid{grid-template-columns:1fr}.ad-form-grid .wide{grid-column:auto}}
@media(max-width:650px){.ad-device-wrap.desktop{padding:7px}.ad-preview-panel{padding:14px!important;border-radius:18px!important}}
</style>
