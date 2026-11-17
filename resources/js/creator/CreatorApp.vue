<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import CreatorForm from './components/CreatorForm.vue';
import CreatorOverview from './components/CreatorOverview.vue';
import CreatorDialog from './components/CreatorDialog.vue';
import CreatorUpload from './components/CreatorUpload.vue';
import CreatorIcon from './components/CreatorIcon.vue';
import CreatorPagination from './components/CreatorPagination.vue';
import { money, formatBytes } from '../admin/lib/channels';
import { api, authData, clearSession, deviceId, getSession, saveSession } from './api';

const session = ref(getSession()); const channels = ref([]); const selected = ref(''); const detail = ref(null);
const tab = ref('overview'); const rows = ref([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const sourceVideo = ref(null); const sources = ref([]); const sourcesLoading = ref(false); const sourcesError = ref('');
const sourcePage = ref(1); const sourceLastPage = ref(1); const sourceTotal = ref(0); const contentStatus = ref('');
const formUploading = ref(false); const locked = computed(() => busy.value || formUploading.value);
let sourceGeneration = 0;
const loading = ref(false); const busy = ref(false); const error = ref(''); const notice = ref(''); const editor = ref(null);
const phone = ref(''); const country = ref('+91'); const otp = ref(''); const loginUid = ref('');
const listPage = ref(1); const listLastPage = ref(1);
let generation = 0;
const profileFields = [
    { name: 'name', label: 'Channel name', type: 'text', required: true, max: 255 },
    { name: 'slug', label: 'Channel handle', type: 'text', max: 255 },
    { name: 'description', label: 'Description', type: 'textarea', max: 10000 },
    { name: 'logo', label: 'Channel logo', type: 'url', max: 500 },
    { name: 'banner', label: 'Channel banner', type: 'url', max: 500 },
];
const videoFields = [
    { name: 'title', label: 'Title', type: 'text', required: true, max: 255 },
    { name: 'description', label: 'Description', type: 'textarea', max: 10000 },
    { name: 'thumbnail', label: 'Cover image', type: 'url', max: 500 },
];
const writable = computed(() => ['active', 'pending'].includes(detail.value?.channel?.status));
const heading = computed(() => ({ overview: 'Your channel, at a glance.', contents: 'Your stories start here.', profile: 'Make yourself at home.', plans: 'Your community, growing.' })[tab.value]);
const failedArtwork = ref({});
function dateLabel(value) { if (!value) return 'Not recorded'; const date = new Date(value); return Number.isNaN(date.getTime()) ? 'Not recorded' : new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }).format(date); }
async function act(action) {
    if (busy.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); } catch (reason) { error.value = reason.message; }
    finally { busy.value = false; }
}
async function requestOtp() {
    await act(async () => {
        const value = authData(await api('/auth/otp/request', { method: 'POST', body: { country_code: country.value, phone_number: phone.value.replace(/\D/g, '') } }));
        if (!value.user_id) throw new Error(value.message || 'Unable to request a login code.');
        loginUid.value = value.user_id; notice.value = 'Enter the code sent to your WhatsApp number.';
    });
}
async function verifyOtp() {
    await act(async () => {
        const value = authData(await api('/auth/otp/verify', { method: 'POST', body: {
            user_id: loginUid.value, otp: otp.value, device_id: deviceId(), device_name: 'Zo Stream Creator Studio', device_type: 'browser', platform: 'web',
        } }));
        if (!value.access_token || !value.refresh_token) throw new Error(value.message || 'Login session was not returned.');
        saveSession(value); session.value = value; otp.value = ''; loginUid.value = '';
        await loadChannels();
    });
}
async function loadChannels(nextPage = 1) {
    const current = ++generation; loading.value = true; error.value = ''; detail.value = null; editor.value = null;
    try {
        const result = await api(`/creator/channels?page=${nextPage}&per_page=20`);
        if (current !== generation) return;
        channels.value = result.data || []; listPage.value = result.current_page; listLastPage.value = result.last_page;
        selected.value = channels.value.some(channel => String(channel.id) === selected.value) ? selected.value : channels.value[0] ? String(channels.value[0].id) : '';
        if (selected.value) await loadChannel();
    } catch (reason) { if (current === generation) error.value = reason.message; }
    finally { if (current === generation) loading.value = false; }
}
async function loadChannel(nextPage = 1, { background = false } = {}) {
    if (!selected.value) return;
    const current = ++generation; const id = encodeURIComponent(selected.value);
    if (!background) {
        sourceGeneration++; sources.value = []; sourcesLoading.value = false;
        loading.value = true; error.value = ''; rows.value = []; editor.value = null; sourceVideo.value = null;
    }
    try {
        const result = await api(`/creator/channels/${id}`);
        if (current !== generation) return;
        detail.value = result;
        if (['overview', 'contents', 'plans'].includes(tab.value)) {
            const collection = tab.value === 'overview' ? 'contents' : tab.value;
            const data = await api(`/creator/channels/${id}/${collection}?page=${nextPage}&per_page=${tab.value === 'overview' ? 5 : 20}${tab.value === 'contents' && contentStatus.value ? `&status=${contentStatus.value}` : ''}`);
            if (current !== generation) return;
            rows.value = data.data || []; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total;
        }
    } catch (reason) { if (current === generation) { error.value = reason.message; if (!background) detail.value = null; } }
    finally { if (current === generation && !background) loading.value = false; }
}
async function saveProfile(body) {
    await act(async () => {
        await api(`/creator/channels/${selected.value}`, { method: 'PUT', body });
        await loadChannel(); notice.value = 'Channel profile saved.';
    });
}
async function saveVideo(body) {
    const video = editor.value;
    await act(async () => {
        const saved = await api(`/creator/channels/${selected.value}/contents${video.id ? `/${video.id}` : ''}`, { method: video.id ? 'PUT' : 'POST', body });
        await loadChannel(video.id ? page.value : 1, { background: true });
        editor.value = null;
        if (!video.id && saved?.id) { sourceVideo.value = saved; await loadSources(); }
        notice.value = video.id ? 'Video details saved.' : 'Draft saved. Add your MP4 to send a source for staff review.';
    });
}
async function withdraw(row) {
    if (!confirm('Withdraw this video from public discovery?')) return;
    await act(async () => {
        await api(`/creator/channels/${selected.value}/contents/${row.id}`, { method: 'DELETE' });
        await loadChannel(page.value); notice.value = 'Video withdrawn.';
    });
}
async function openSources(row) {
    if (locked.value) return;
    editor.value = null; sourceVideo.value = row; sources.value = []; sourcePage.value = 1; sourceLastPage.value = 1; sourceTotal.value = 0; await loadSources();
}
async function loadSources(nextPage = 1) {
    if (!sourceVideo.value) return;
    const current = ++sourceGeneration; sourcesLoading.value = true; sourcesError.value = '';
    try {
        const result = await api(`/creator/channels/${encodeURIComponent(selected.value)}/uploads?content_id=${sourceVideo.value.id}&page=${nextPage}`);
        if (current !== sourceGeneration) return;
        sources.value = result.data || []; sourcePage.value = result.current_page || 1; sourceLastPage.value = result.last_page || 1; sourceTotal.value = result.total || 0;
    } catch (reason) { if (current === sourceGeneration) sourcesError.value = reason.message; }
    finally { if (current === sourceGeneration) sourcesLoading.value = false; }
}
async function sourceUploaded() {
    const video = sourceVideo.value; const channelId = selected.value;
    await loadChannel(page.value, { background: true });
    if (!video || selected.value !== channelId || sourceVideo.value?.id !== video.id) return;
    await loadSources();
    notice.value = 'MP4 uploaded privately for Zo Stream review.';
}
function changeTab(value, status = '') { if (locked.value || loading.value) return; tab.value = value; if (value === 'contents') contentStatus.value = status; loadChannel(); }
async function startVideo() {
    if (locked.value || !writable.value) return;
    const channelId = selected.value; tab.value = 'contents'; await loadChannel();
    if (selected.value === channelId && tab.value === 'contents' && detail.value && writable.value) newVideo();
}
function newVideo() { if (locked.value || !writable.value) return; error.value = ''; notice.value = ''; sourceVideo.value = null; sourceGeneration++; editor.value = {}; }
function editVideo(row) { if (locked.value || !writable.value) return; error.value = ''; notice.value = ''; sourceVideo.value = null; sourceGeneration++; editor.value = { ...row }; }
function closeEditor() { if (!locked.value) { editor.value = null; error.value = ''; } }
function closeSources() { if (locked.value) return; sourceGeneration++; sourceVideo.value = null; sourcesLoading.value = false; }
function expired() { generation++; sourceGeneration++; sourceVideo.value = null; formUploading.value = false; session.value = null; channels.value = []; selected.value = ''; detail.value = null; rows.value = []; editor.value = null; loading.value = false; }
async function logout() { await act(async () => { try { await api('/auth/logout', { method: 'POST' }); } finally { clearSession(); expired(); } }); }
window.addEventListener('zostream:creator-expired', expired);
onBeforeUnmount(() => { generation++; sourceGeneration++; window.removeEventListener('zostream:creator-expired', expired); });
if (session.value) loadChannels();

</script>
<template>
    <main class="creator-studio" :class="{ 'is-signed-in': session }">
        <a class="cs-skip" href="#studio-content">Skip to content</a>
        <template v-if="!session">
            <header class="cs-login-header"><a class="cs-brand" href="/creator"><span class="cs-brand-mark">z<span>o</span></span><span>Zo Stream <b>for creators</b></span></a><span>A space for your next story.</span></header>
            <div class="cs-login-layout">
                <section class="cs-login-story"><span class="cs-eyebrow">CREATE. SHARE. CONNECT.</span><h1>Big ideas.<br>Your own<br><em>little studio.</em></h1><p>Your videos, your channel, your community. Bring your next story to Zo Stream.</p><div class="cs-story-art" aria-hidden="true"><span class="cs-art-orbit"></span><div class="cs-art-frame"><CreatorIcon name="video" /><span>YOUR NEXT<br>GREAT STORY</span></div><span class="cs-art-sticker">Made by you.</span></div></section>
                <section id="studio-content" class="cs-login-card"><span class="cs-step-label">{{ loginUid ? 'STEP 02 / VERIFY' : 'STEP 01 / SIGN IN' }}</span><h2>{{ loginUid ? 'Check your WhatsApp.' : 'Welcome, creator.' }}</h2><p>{{ loginUid ? 'Enter the six-digit code sent to your number.' : 'Sign in with the WhatsApp number linked to your channel owner account.' }}</p>
                    <p v-if="error && !editor && !sourceVideo" class="cs-message cs-message-error" role="alert">{{ error }}</p><p v-if="notice && !editor && !sourceVideo" class="cs-message cs-message-success" role="status">{{ notice }}</p>
                    <form v-if="!loginUid" class="cs-login-form" @submit.prevent="requestOtp"><div class="cs-phone-fields"><label>Country code<input v-model="country" required maxlength="10" autocomplete="tel-country-code" :disabled="busy"></label><label>WhatsApp number<input v-model="phone" required inputmode="tel" autocomplete="tel-national" placeholder="Your phone number" :disabled="busy"></label></div><button class="cs-button cs-button-primary" :disabled="busy">{{ busy ? 'Sending…' : 'Send login code' }}<CreatorIcon name="arrow" /></button></form>
                    <form v-else class="cs-login-form" @submit.prevent="verifyOtp"><label>Verification code<input v-model="otp" class="cs-otp" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" :disabled="busy"></label><button class="cs-button cs-button-primary" :disabled="busy">{{ busy ? 'Verifying…' : 'Sign in' }}<CreatorIcon name="arrow" /></button><button type="button" class="cs-button cs-button-quiet" :disabled="busy" @click="loginUid = ''; otp = ''">Change number / resend</button></form>
                    <small class="cs-login-footnote">Use your existing Zo Stream account. Your channel assignment stays with your account.</small>
                </section>
            </div>
        </template>
        <template v-else>
            <aside class="cs-sidebar"><a class="cs-brand" href="/creator"><span class="cs-brand-mark">z<span>o</span></span><span>Zo Stream <b>CREATOR STUDIO</b></span></a>
                <div class="cs-channel-switcher"><label>My channel<select v-model="selected" :disabled="locked || loading || !channels.length" @change="loadChannel()"><option v-if="!channels.length" value="">No channel assigned</option><option v-for="channel in channels" :key="channel.id" :value="String(channel.id)">{{ channel.name }}</option></select></label><div v-if="listLastPage > 1" class="cs-channel-pages"><button :disabled="locked || loading || listPage <= 1" @click="loadChannels(listPage - 1)">Previous channels</button><button :disabled="locked || loading || listPage >= listLastPage" @click="loadChannels(listPage + 1)">More channels</button></div></div>
                <nav class="cs-navigation" aria-label="Studio sections"><button :class="{ active: tab === 'overview' }" :aria-current="tab === 'overview' ? 'page' : undefined" :disabled="locked || loading" @click="changeTab('overview')"><CreatorIcon name="overview" /><span>Overview</span></button><button :class="{ active: tab === 'contents' }" :aria-current="tab === 'contents' ? 'page' : undefined" :disabled="locked || loading" @click="changeTab('contents')"><CreatorIcon name="video" /><span>Videos</span></button><button :class="{ active: tab === 'profile' }" :aria-current="tab === 'profile' ? 'page' : undefined" :disabled="locked || loading" @click="changeTab('profile')"><CreatorIcon name="channel" /><span>Channel settings</span></button><button :class="{ active: tab === 'plans' }" :aria-current="tab === 'plans' ? 'page' : undefined" :disabled="locked || loading" @click="changeTab('plans')"><CreatorIcon name="members" /><span>Membership</span></button></nav>
                <div class="cs-sidebar-note"><span class="cs-note-dot"></span><strong>A little help along the way</strong><p>Save a draft, upload your video, and Zo Stream staff will handle review and publication.</p></div>
                <button class="cs-signout" :disabled="locked" @click="logout"><CreatorIcon name="logout" />Sign out</button>
            </aside>
            <div id="studio-content" class="cs-workspace">
                <header class="cs-topbar"><div><span>CREATOR STUDIO</span><strong>{{ { overview: 'Overview', contents: 'Videos', profile: 'Channel settings', plans: 'Membership' }[tab] }}</strong></div><button class="cs-button cs-button-quiet" :disabled="locked || loading" @click="loadChannels(listPage)">Refresh</button></header>
                <p v-if="error && !editor && !sourceVideo" class="cs-message cs-message-error" role="alert">{{ error }}</p><p v-if="notice && !editor && !sourceVideo" class="cs-message cs-message-success" role="status">{{ notice }}</p>
                <div v-if="loading" class="cs-loading" role="status"><span class="cs-loader"></span>Getting your studio ready…</div>
                <section v-else-if="!channels.length" class="cs-empty cs-empty-large"><div class="cs-empty-icon"><CreatorIcon name="channel" /></div><span class="cs-eyebrow">LET’S GET YOU SET UP</span><h1>No channel assigned</h1><p>Ask Zo Stream Admin to link your account to your channel.</p><div class="cs-account-uid">Your UID: <strong>{{ session.uid || 'Check your Zo Stream account' }}</strong></div><p>Already have a channel? Sign in with the number linked to its owner account.</p></section>
                <template v-else-if="detail">
                    <header class="cs-page-heading"><div><p class="cs-eyebrow">{{ detail.channel.name }} <span class="cs-status" :data-status="detail.channel.status">{{ detail.channel.status }}</span></p><h1>{{ heading }}</h1><p>{{ tab === 'overview' ? 'Check in on your channel and choose what to work on next.' : tab === 'contents' ? 'A home for everything you’re making.' : tab === 'profile' ? 'Give your channel a personality that’s all yours.' : 'See the plans that bring your audience closer.' }}</p></div><button v-if="tab !== 'contents'" class="cs-button cs-button-primary" :disabled="locked || !writable" @click="startVideo"><CreatorIcon name="plus" />Create a video</button></header>
                    <p v-if="!writable" class="cs-message cs-message-info" role="status">This channel is suspended. You can view your records; contact Zo Stream support to restore editing.</p>
                    <CreatorOverview v-if="tab === 'overview'" :detail="detail" :recent="rows" :busy="locked || loading" :writable="writable" @create="startVideo" @edit="editVideo" @navigate="changeTab" />
                    <template v-else-if="tab === 'contents'">
                        <div class="cs-library-bar"><div><h2>Your video library <span>{{ total }}</span></h2><p>Make something your audience will love.</p></div><label>Visibility<select v-model="contentStatus" :disabled="locked" @change="loadChannel()"><option value="">All videos</option><option value="draft">Drafts</option><option value="published">Published</option><option value="private">Withdrawn / private</option></select></label><button class="cs-button cs-button-primary" :disabled="locked || !writable" @click="newVideo"><CreatorIcon name="plus" />New video draft</button></div>
                        <div v-if="rows.length" class="cs-video-grid"><article v-for="video in rows" :key="video.id" class="cs-video-card"><div class="cs-video-art"><img v-if="video.thumbnail && !failedArtwork[video.id]" :src="video.thumbnail" :alt="video.title || 'Video thumbnail'" loading="lazy" referrerpolicy="no-referrer" @error="failedArtwork[video.id] = true"><div v-else class="cs-video-placeholder"><CreatorIcon name="video" /><span>{{ video.title || 'Your next story' }}</span></div><span class="cs-status" :data-status="video.status">{{ video.status === 'private' ? 'Private' : video.status }}</span></div><div class="cs-video-body"><h3 :title="video.title">{{ video.title || 'Untitled video' }}</h3><p>{{ video.status === 'published' ? 'Published · available from its release date' : video.status === 'draft' ? 'Draft · prepare details and a source for review' : 'Hidden from your public channel' }}</p><small>Updated {{ dateLabel(video.updated_at) }}</small><div class="cs-video-actions"><button class="cs-button cs-button-quiet" :disabled="locked || !writable" @click="editVideo(video)">Edit details</button><button class="cs-button cs-button-outline" :disabled="locked" @click="openSources(video)">{{ writable ? 'Upload MP4' : 'Source files' }}</button><button class="cs-text-danger" :disabled="locked || !writable || video.status === 'private'" @click="withdraw(video)">Withdraw</button></div></div></article></div>
                        <section v-else class="cs-empty"><div class="cs-empty-icon"><CreatorIcon name="video" /></div><h2>{{ contentStatus ? 'No videos with this visibility.' : 'Your first story belongs here.' }}</h2><p>{{ contentStatus ? 'Try another visibility filter to find your videos.' : 'Start with a title and thumbnail. Then upload an MP4 for staff review.' }}</p><button v-if="!contentStatus" class="cs-button cs-button-outline" :disabled="locked || !writable" @click="newVideo">Create your first video<CreatorIcon name="arrow" /></button></section>
                        <CreatorPagination :page="page" :last-page="lastPage" :total="total" :disabled="locked" @page="loadChannel" />
                        <details class="cs-workflow"><summary>How does publishing work?</summary><ol><li><span>1</span><div><strong>Make a draft</strong><p>Title, description, thumbnail.</p></div></li><li><span>2</span><div><strong>Upload your MP4</strong><p>Your original, kept private.</p></div></li><li><span>3</span><div><strong>We’ll handle review</strong><p>Staff process and publish.</p></div></li></ol></details>
                    </template>
                    <template v-else-if="tab === 'profile'"><div class="cs-profile-preview"><img v-if="detail.channel.banner" class="cs-profile-banner" :src="detail.channel.banner" alt="Channel banner" referrerpolicy="no-referrer"><div class="cs-profile-summary"><img v-if="detail.channel.logo" :src="detail.channel.logo" alt="Channel logo" referrerpolicy="no-referrer"><span v-else class="cs-profile-initial">{{ detail.channel.name.slice(0, 1) }}</span><div><h2>{{ detail.channel.name }}</h2><p>/{{ detail.channel.slug }}</p></div><span v-if="detail.channel.is_verified" class="cs-status" data-status="ready">Verified</span></div></div><CreatorForm :fields="profileFields" :record="detail.channel" :busy="busy || !writable" :api="api" :channel-id="selected" title="Channel profile" @busy="formUploading = $event" @save="saveProfile" @cancel="loadChannel()" /></template>
                    <template v-else><div class="cs-membership-note"><CreatorIcon name="members" /><div><h2>A closer connection with your audience.</h2><p>Zo Stream Admin manages your plan pricing. New membership purchases are currently unavailable.</p></div></div><div v-if="rows.length" class="cs-plan-grid"><article v-for="plan in rows" :key="plan.id" class="cs-plan-card"><span class="cs-status" :data-status="plan.is_active ? 'active' : 'private'">{{ plan.is_active ? 'Active plan' : 'Inactive' }}</span><h2>{{ plan.name || 'Channel membership' }}</h2><strong>{{ money(plan.final_price ?? plan.price) }}</strong><p>for {{ plan.duration_days }} days</p><small v-if="Number(plan.discount_percent) > 0">{{ plan.discount_percent }}% discount · Regular price {{ money(plan.price) }}</small></article></div><section v-else class="cs-empty"><div class="cs-empty-icon"><CreatorIcon name="members" /></div><h2>No membership plans yet</h2><p>Contact Zo Stream Admin to set up plans for your channel.</p></section><CreatorPagination :page="page" :last-page="lastPage" :total="total" :disabled="locked" @page="loadChannel" /></template>
                </template>
            </div>

            <CreatorDialog v-if="editor" title="Video details" close-label="Close video details" :busy="locked" @close="closeEditor">
                <p v-if="error" class="cs-message cs-message-error" role="alert">{{ error }}</p>
                <CreatorForm :key="editor.id || 'new'" :fields="videoFields" :record="editor" :busy="busy || !writable" :api="api" :channel-id="selected" :content-id="editor.id" title="Video details" @busy="formUploading = $event" @save="saveVideo" @cancel="closeEditor" />
            </CreatorDialog>
            <CreatorDialog v-else-if="sourceVideo" :title="sourceVideo.title || 'Video source files'" close-label="Close uploads" :busy="locked" @close="closeSources">
                <p v-if="notice" class="cs-message cs-message-success" role="status">{{ notice }}</p>
                <p v-if="error" class="cs-message cs-message-error" role="alert">{{ error }}</p>
                <section class="cs-source-panel"><header><div><p class="cs-eyebrow">STEP 02 / YOUR VIDEO FILE</p><p>Add the original MP4. Zo Stream staff will take it from here.</p></div></header><p v-if="sourcesError" class="cs-message cs-message-error" role="alert">{{ sourcesError }}</p>
                            <CreatorUpload :key="sourceVideo.id" :api="api" :channel-id="selected" :content-id="sourceVideo.id" kind="source" label="Upload MP4" :disabled="locked || !writable" @busy="busy = $event" @uploaded="sourceUploaded" />
                            <h3>Previously uploaded</h3><p v-if="sourcesLoading" class="cs-muted" role="status">Loading source files…</p><ul v-else-if="sources.length" class="cs-source-list"><li v-for="source in sources" :key="source.id"><CreatorIcon name="video" /><div><strong>{{ source.filename }}</strong><small>{{ formatBytes(source.size) }} · {{ dateLabel(source.created_at) }}</small></div><span class="cs-status" data-status="ready">Uploaded</span></li></ul><p v-else class="cs-muted">No source uploaded yet. Choose an MP4 above to get started.</p><CreatorPagination :page="sourcePage" :last-page="sourceLastPage" :total="sourceTotal" :disabled="locked || sourcesLoading" @page="loadSources" />
                        </section>
            </CreatorDialog>
        </template>
    </main>
</template>
