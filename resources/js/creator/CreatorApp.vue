<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import StatusPanel from '../admin/components/StatusPanel.vue';
import FileDropzone from '../admin/components/channels/FileDropzone.vue';
import ChannelForm from '../admin/components/channels/ChannelForm.vue';
import ChannelTable from '../admin/components/channels/ChannelTable.vue';
import ChannelIdentity from '../admin/components/channels/ChannelIdentity.vue';
import AdminIcon from '../admin/components/AdminIcon.vue';
import { channelFields, contentFields } from '../admin/lib/channels';
import { api, authData, clearSession, deviceId, getSession, saveSession } from './api';

const session = ref(getSession()); const channels = ref([]); const selected = ref(''); const detail = ref(null);
const tab = ref('profile'); const rows = ref([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const sourceVideo = ref(null); const sources = ref([]); const sourcesLoading = ref(false); const sourcesError = ref('');
const sourcePage = ref(1); const sourceLastPage = ref(1); const sourceTotal = ref(0); const contentStatus = ref('');
const formUploading = ref(false); const locked = computed(() => busy.value || formUploading.value);
let sourceGeneration = 0;
const loading = ref(false); const busy = ref(false); const error = ref(''); const notice = ref(''); const editor = ref(null);
const phone = ref(''); const country = ref('+91'); const otp = ref(''); const loginUid = ref('');
const listPage = ref(1); const listLastPage = ref(1);
let generation = 0;
const profileFields = channelFields.filter(field => ['name', 'slug', 'description', 'logo', 'banner'].includes(field.name));
const videoFields = contentFields.filter(field => ['title', 'description', 'thumbnail'].includes(field.name)).map(field => ({ ...field, required: field.name === 'title' }));
const writable = computed(() => ['active', 'pending'].includes(detail.value?.channel?.status));
const columns = computed(() => tab.value === 'contents'
    ? [{ key: 'title', label: 'Video' }, { key: 'status', label: 'Visibility' }, { key: 'updated_at', label: 'Updated', date: true }]
    : [{ key: 'name', label: 'Plan' }, { key: 'price', label: 'Price', money: true }, { key: 'duration_days', label: 'Days' }, { key: 'is_active', label: 'Active' }]);
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
        selected.value = channels.value[0] ? String(channels.value[0].id) : '';
        if (selected.value) await loadChannel();
    } catch (reason) { if (current === generation) error.value = reason.message; }
    finally { if (current === generation) loading.value = false; }
}
async function loadChannel(nextPage = 1) {
    if (!selected.value) return;
    const current = ++generation; const id = encodeURIComponent(selected.value);
    sourceGeneration++; sources.value = []; sourcesLoading.value = false;
    loading.value = true; error.value = ''; rows.value = []; editor.value = null; sourceVideo.value = null;
    try {
        const result = await api(`/creator/channels/${id}`);
        if (current !== generation) return;
        detail.value = result;
        if (tab.value !== 'profile') {
            const data = await api(`/creator/channels/${id}/${tab.value}?page=${nextPage}&per_page=20${tab.value === 'contents' && contentStatus.value ? `&status=${contentStatus.value}` : ''}`);
            if (current !== generation) return;
            rows.value = data.data || []; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total;
        }
    } catch (reason) { if (current === generation) { error.value = reason.message; detail.value = null; } }
    finally { if (current === generation) loading.value = false; }
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
        await api(`/creator/channels/${selected.value}/contents${video.id ? `/${video.id}` : ''}`, { method: video.id ? 'PUT' : 'POST', body });
        await loadChannel(page.value); notice.value = 'Video draft saved. Zo Stream staff control publication.';
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
    sourceVideo.value = row; sources.value = []; sourcePage.value = 1; sourceLastPage.value = 1; sourceTotal.value = 0; await loadSources();
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
    const video = sourceVideo.value;
    await loadChannel(page.value);
    if (video) await openSources(video);
    notice.value = 'MP4 uploaded privately for Zo Stream review.';
}
function changeTab(value) { if (locked.value) return; tab.value = value; loadChannel(); }
async function startVideo() {
    if (locked.value || !writable.value) return;
    const channelId = selected.value; tab.value = 'contents'; await loadChannel();
    if (selected.value === channelId && tab.value === 'contents' && detail.value && writable.value) newVideo();
}
function newVideo() { if (locked.value) return; sourceVideo.value = null; sourceGeneration++; editor.value = {}; }
function editVideo(row) { if (locked.value) return; sourceVideo.value = null; sourceGeneration++; editor.value = { ...row }; }
function closeSources() { sourceGeneration++; sourceVideo.value = null; sourcesLoading.value = false; }
function expired() { generation++; sourceGeneration++; sourceVideo.value = null; formUploading.value = false; session.value = null; channels.value = []; selected.value = ''; detail.value = null; rows.value = []; editor.value = null; loading.value = false; }
async function logout() { await act(async () => { try { await api('/auth/logout', { method: 'POST' }); } finally { clearSession(); expired(); } }); }
window.addEventListener('zostream:creator-expired', expired);
onBeforeUnmount(() => { generation++; sourceGeneration++; window.removeEventListener('zostream:creator-expired', expired); });
if (session.value) loadChannels();
</script>
<template>
    <main class="admin-root creator-root">
        <div class="creator-workspace admin-page">
            <header class="creator-header"><a href="/creator" class="creator-brand"><img :src="'/images/zostream-logo.jpg'" alt="Zo Stream"><span>ZO STREAM<small>CREATOR STUDIO</small></span></a><button v-if="session" class="admin-secondary" :disabled="locked" @click="logout">Sign out</button></header>
            <StatusPanel tone="error" :message="error" /><StatusPanel tone="success" :message="notice" />
            <section v-if="!session" class="admin-panel creator-login">
                <h1>Welcome to Creator Studio</h1><p>Sign in with the WhatsApp number linked to your Zo Stream channel owner account.</p>
                <form v-if="!loginUid" class="admin-form-grid" @submit.prevent="requestOtp"><label>Country code<input v-model="country" required maxlength="10" autocomplete="tel-country-code"></label><label>WhatsApp number<input v-model="phone" required inputmode="tel" autocomplete="tel-national"></label><button class="admin-primary" :disabled="locked">{{ busy ? 'Sending…' : 'Send login code' }}</button></form>
                <form v-else class="admin-form-grid" @submit.prevent="verifyOtp"><label>Verification code<input v-model="otp" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code"></label><button class="admin-primary" :disabled="locked">{{ busy ? 'Verifying…' : 'Sign in' }}</button><button type="button" class="admin-secondary" :disabled="locked" @click="loginUid = ''; otp = ''">Change number / resend</button></form>
            </section>
            <template v-else>
                <div class="creator-channel-picker"><label>My channel<select v-model="selected" :disabled="locked || loading" @change="loadChannel()"><option v-for="item in channels" :key="item.id" :value="String(item.id)">{{ item.name }} · {{ item.status }}</option></select></label><button class="admin-secondary" :disabled="locked || loading || listPage <= 1" @click="loadChannels(listPage - 1)">Previous channels</button><button class="admin-secondary" :disabled="locked || loading || listPage >= listLastPage" @click="loadChannels(listPage + 1)">More channels</button><button class="admin-secondary" :disabled="locked || loading" @click="loadChannels(listPage)">Refresh</button></div>
                <div v-if="loading" class="admin-loading">Loading your studio…</div>
                <section v-else-if="!channels.length" class="admin-panel creator-login"><h2>No channel assigned</h2><p>Ask Zo Stream Admin to assign your account UID as the channel owner.</p><p>Your UID: <strong>{{ session.uid || 'Check your Zo Stream account' }}</strong></p><p>Existing owners should sign in with the number linked to their assigned account.</p></section>
                <template v-else-if="detail">
                    <ChannelIdentity :channel="detail.channel" />
                    <div class="creator-stats"><article class="admin-panel"><strong>{{ detail.summary.contents }}</strong><span>Videos / content</span></article><article class="admin-panel"><strong>{{ detail.summary.published }}</strong><span>Published</span></article><article class="admin-panel"><strong>{{ detail.summary.active_members }}</strong><span>Active members</span></article></div>
                    <section v-if="tab === 'profile'" class="studio-guide admin-panel" aria-label="Publishing workflow">
                        <div class="studio-guide-heading"><div><span class="studio-eyebrow">YOUR NEXT RELEASE</span><h2>From first draft to your audience</h2><p>Prepare your video here. Zo Stream staff handle review and publication.</p></div><button class="admin-primary" :disabled="locked || !writable" @click="startVideo">Create a video</button></div>
                        <ol class="studio-steps"><li><span>01</span><strong>Add video details</strong><p>Start with a title, description and thumbnail.</p></li><li><span>02</span><strong>Upload your MP4</strong><p>Save the draft, then upload its private source file.</p></li><li><span>03</span><strong>Staff review</strong><p>Zo Stream processes the video and manages its release.</p></li></ol>
                    </section>
                    <nav class="creator-tabs" aria-label="Studio sections"><button v-for="item in ['profile', 'contents', 'plans']" :key="item" :aria-pressed="tab === item" :class="{ active: tab === item }" :disabled="locked" @click="changeTab(item)">{{ item === 'contents' ? 'Video drafts' : item }}</button></nav>
                    <StatusPanel v-if="!writable" message="This channel is suspended. You can view your records; contact Zo Stream support to restore editing." />
                    <ChannelForm @busy="formUploading = $event" v-if="tab === 'profile'" :fields="profileFields" :record="detail.channel" :busy="busy || !writable" :upload-api="api" audience="creator" :channel-id="selected" title="Channel profile" @save="saveProfile" @cancel="loadChannel()" />
                    <template v-else>
                        <template v-if="tab === 'contents'">
                            <p class="creator-muted">Save video details, then choose Upload MP4 to add your private source. Staff review, media processing and publication are handled by Zo Stream. Editing published metadata returns it to draft.</p>
                            <div class="studio-library-toolbar"><div><h2>Your video library</h2><p class="creator-muted">Manage drafts, review files and published videos.</p></div><label>Visibility<select v-model="contentStatus" :disabled="locked || loading" @change="loadChannel()"><option value="">All videos</option><option value="draft">Drafts</option><option value="published">Published</option><option value="private">Withdrawn / private</option></select></label><button class="admin-primary" :disabled="locked || !writable" @click="newVideo">New video draft</button></div>
                            <ChannelForm @busy="formUploading = $event" v-if="editor" :key="editor.id || 'new'" :fields="videoFields" :record="editor" :busy="busy || !writable" :upload-api="api" audience="creator" :channel-id="selected" :content-id="editor.id" title="Video details" @save="saveVideo" @cancel="editor = null" />
                        </template>
                        <p v-else class="creator-muted">Your channel membership plans. Contact Zo Stream Admin for pricing changes. New membership purchases are currently unavailable.</p>
                        <section v-if="sourceVideo && tab === 'contents'" class="studio-source-panel admin-panel"><header><div><span class="studio-eyebrow">PRIVATE SOURCE</span><h2>{{ sourceVideo.title || 'Untitled video' }}</h2><p class="creator-muted">Upload the original MP4 for staff review. Completed files are listed below.</p></div><button class="admin-secondary" :disabled="locked" @click="closeSources">Close uploads</button></header><StatusPanel tone="error" :message="sourcesError" />
                        <FileDropzone :key="sourceVideo.id" :api="api" audience="creator" :channel-id="selected" :content-id="sourceVideo.id" kind="source" :label="`Upload MP4 · ${sourceVideo.title}`" :disabled="locked || !writable" @busy="busy = $event" @uploaded="sourceUploaded" />
                        <ChannelTable :disabled="locked" :rows="sources" :loading="sourcesLoading" :page="sourcePage" :last-page="sourceLastPage" :total="sourceTotal" :columns="[{ key: 'filename', label: 'Uploaded file' }, { key: 'size', label: 'Size', bytes: true }, { key: 'created_at', label: 'Uploaded', date: true }]" empty-title="No source uploaded yet" empty-description="Choose your MP4 above and upload it to submit a source for staff review." @page="loadSources" /></section>
                        <ChannelTable :disabled="locked" :empty-title="tab === 'contents' ? 'Your video library starts here' : 'No membership plans yet'" :empty-description="tab === 'contents' ? 'Create a video draft, add its details and upload an MP4 for review. You can also change the visibility filter.' : 'Zo Stream Admin manages plan availability and pricing for your channel.'" :rows="rows" :columns="columns" :page="page" :last-page="lastPage" :total="total" @page="loadChannel">
                            <template #cell-title="{ row }"><div class="studio-video-title"><AdminIcon name="film" /><div><strong>{{ row.title || 'Untitled video' }}</strong><small>{{ row.status === 'draft' ? 'Prepare details and upload a source' : row.status === 'published' ? 'Available when its release date is reached' : 'Hidden from the public catalog' }}</small></div></div></template>
                            <template v-if="tab === 'contents'" #actions="{ row }"><button :disabled="locked || !writable" @click="editVideo(row)">Edit details</button><button :disabled="locked" @click="openSources(row)">{{ writable ? 'Upload MP4' : 'Source files' }}</button><button :disabled="locked || !writable" @click="withdraw(row)">Withdraw</button></template>
                        </ChannelTable>
                    </template>
                </template>
            </template>
        </div>
    </main>
</template>
<style scoped>
.studio-eyebrow{font-size:10px;letter-spacing:2px;color:var(--a-cyan);font-weight:600}.studio-guide{padding:24px;margin:24px 0}.studio-guide-heading,.studio-library-toolbar,.studio-source-panel header{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}.studio-guide h2,.studio-source-panel h2,.studio-library-toolbar h2{font-size:19px;margin:8px 0}.studio-guide-heading p{color:var(--a-muted);font-size:12px;line-height:1.7}.studio-steps{list-style:none;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;margin:24px 0 0;padding:24px 0 0;border-top:1px solid var(--a-line)}.studio-steps li{display:grid;align-content:start;gap:10px}.studio-steps li>span{color:var(--a-cyan);font-size:12px;font-weight:700}.studio-steps strong{font-size:13px}.studio-steps p{color:var(--a-muted);font-size:12px;line-height:1.7;margin:0}.studio-library-toolbar{margin:24px 0}.studio-library-toolbar>div{flex:1}.studio-library-toolbar label{display:grid;gap:6px;font-size:11px;color:var(--a-muted)}.studio-library-toolbar select{padding:11px;border:1px solid var(--a-line);border-radius:9px;background:var(--a-panel);color:var(--a-text)}.studio-source-panel{padding:22px;margin:20px 0}.studio-video-title{display:flex;align-items:center;gap:12px}.studio-video-title>svg{width:34px;height:34px;flex-shrink:0;padding:7px;background:rgba(53,213,208,.08);color:var(--a-cyan);border-radius:8px}.studio-video-title strong{font-size:13px}.studio-video-title small{display:block;font-size:11px;margin-top:6px;color:var(--a-muted);line-height:1.6}.creator-stats article{border-top:2px solid var(--a-cyan)}@media(max-width:650px){.studio-steps{grid-template-columns:1fr;gap:20px}.studio-guide,.studio-source-panel{padding:18px}.studio-library-toolbar>div{flex-basis:100%}}

.creator-root{min-height:100vh;padding:28px 20px;display:block}.creator-workspace{max-width:1100px;margin:auto}.creator-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;gap:14px}.creator-brand{display:flex;align-items:center;gap:12px;color:var(--a-text);text-decoration:none;font-weight:800}.creator-brand img{width:46px;height:46px;border-radius:12px}.creator-brand small{display:block;color:var(--a-cyan);font-size:10px;letter-spacing:2px;margin-top:4px}.creator-login{max-width:650px;margin:30px auto;padding:30px}.creator-login p,.creator-muted{color:var(--a-muted);line-height:1.7;font-size:13px}.creator-login h1{font-size:24px}.creator-channel-picker{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:20px 0}.creator-channel-picker label{display:grid;gap:7px;color:var(--a-muted);font-size:12px;flex:1}.creator-channel-picker select{padding:12px;background:var(--a-panel);color:var(--a-text);border:1px solid var(--a-line);border-radius:9px}.creator-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:20px 0}.creator-stats article{padding:20px}.creator-stats strong,.creator-stats span{display:block}.creator-stats strong{font-size:27px}.creator-stats span{font-size:12px;color:var(--a-muted);margin-top:5px}.creator-tabs{display:flex;gap:10px;margin:20px 0}.creator-tabs button{padding:12px 18px;border:1px solid var(--a-line);border-radius:9px;background:transparent;color:var(--a-muted);text-transform:capitalize}.creator-tabs button.active{color:var(--a-cyan);border-color:var(--a-cyan)}.creator-workspace :deep(.channel-form){margin:18px 0}button:disabled{opacity:.5}@media(max-width:600px){.creator-stats{grid-template-columns:1fr}.creator-root{padding:18px 10px}.creator-login{padding:20px}.creator-tabs{flex-wrap:wrap}}
</style>
