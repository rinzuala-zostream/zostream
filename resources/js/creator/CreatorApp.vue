<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import StatusPanel from '../admin/components/StatusPanel.vue';
import FileDropzone from '../admin/components/channels/FileDropzone.vue';
import ChannelForm from '../admin/components/channels/ChannelForm.vue';
import ChannelTable from '../admin/components/channels/ChannelTable.vue';
import { channelFields, contentFields } from '../admin/lib/channels';
import { api, authData, clearSession, deviceId, getSession, saveSession } from './api';

const session = ref(getSession()); const channels = ref([]); const selected = ref(''); const detail = ref(null);
const tab = ref('profile'); const rows = ref([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const sourceVideo = ref(null);
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
    loading.value = true; error.value = ''; rows.value = []; editor.value = null; sourceVideo.value = null;
    try {
        const result = await api(`/creator/channels/${id}`);
        if (current !== generation) return;
        detail.value = result;
        if (tab.value !== 'profile') {
            const data = await api(`/creator/channels/${id}/${tab.value}?page=${nextPage}&per_page=20`);
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
function changeTab(value) { tab.value = value; loadChannel(); }
function expired() { generation++; session.value = null; channels.value = []; selected.value = ''; detail.value = null; rows.value = []; editor.value = null; loading.value = false; }
async function logout() { await act(async () => { try { await api('/auth/logout', { method: 'POST' }); } finally { clearSession(); expired(); } }); }
window.addEventListener('zostream:creator-expired', expired);
onBeforeUnmount(() => { generation++; window.removeEventListener('zostream:creator-expired', expired); });
if (session.value) loadChannels();
</script>
<template>
    <main class="admin-root creator-root">
        <div class="creator-workspace admin-page">
            <header class="creator-header"><a href="/creator" class="creator-brand"><img :src="'/images/zostream-logo.jpg'" alt="Zo Stream"><span>ZO STREAM<small>CREATOR STUDIO</small></span></a><button v-if="session" class="admin-secondary" :disabled="busy" @click="logout">Sign out</button></header>
            <StatusPanel tone="error" :message="error" /><StatusPanel tone="success" :message="notice" />
            <section v-if="!session" class="admin-panel creator-login">
                <h1>Welcome to Creator Studio</h1><p>Sign in with the WhatsApp number linked to your Zo Stream channel owner account.</p>
                <form v-if="!loginUid" class="admin-form-grid" @submit.prevent="requestOtp"><label>Country code<input v-model="country" required maxlength="10" autocomplete="tel-country-code"></label><label>WhatsApp number<input v-model="phone" required inputmode="tel" autocomplete="tel-national"></label><button class="admin-primary" :disabled="busy">{{ busy ? 'Sending…' : 'Send login code' }}</button></form>
                <form v-else class="admin-form-grid" @submit.prevent="verifyOtp"><label>Verification code<input v-model="otp" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code"></label><button class="admin-primary" :disabled="busy">{{ busy ? 'Verifying…' : 'Sign in' }}</button><button type="button" class="admin-secondary" :disabled="busy" @click="loginUid = ''; otp = ''">Change number / resend</button></form>
            </section>
            <template v-else>
                <div class="creator-channel-picker"><label>My channel<select v-model="selected" :disabled="busy || loading" @change="loadChannel()"><option v-for="item in channels" :key="item.id" :value="String(item.id)">{{ item.name }} · {{ item.status }}</option></select></label><button class="admin-secondary" :disabled="busy || loading || listPage <= 1" @click="loadChannels(listPage - 1)">Previous channels</button><button class="admin-secondary" :disabled="busy || loading || listPage >= listLastPage" @click="loadChannels(listPage + 1)">More channels</button><button class="admin-secondary" :disabled="busy || loading" @click="loadChannels(listPage)">Refresh</button></div>
                <div v-if="loading" class="admin-loading">Loading your studio…</div>
                <section v-else-if="!channels.length" class="admin-panel creator-login"><h2>No channel assigned</h2><p>Ask Zo Stream Admin to assign your account UID as the channel owner.</p><p>Your UID: <strong>{{ session.uid || 'Check your Zo Stream account' }}</strong></p><p>Existing owners should sign in with the number linked to their assigned account.</p></section>
                <template v-else-if="detail">
                    <h1>{{ detail.channel.name }}</h1>
                    <p class="creator-muted">Channel status: {{ detail.channel.status }}</p>
                    <div class="creator-stats"><article class="admin-panel"><strong>{{ detail.summary.contents }}</strong><span>Videos / content</span></article><article class="admin-panel"><strong>{{ detail.summary.published }}</strong><span>Published</span></article><article class="admin-panel"><strong>{{ detail.summary.active_members }}</strong><span>Active members</span></article></div>
                    <nav class="creator-tabs" aria-label="Studio sections"><button v-for="item in ['profile', 'contents', 'plans']" :key="item" :aria-pressed="tab === item" :class="{ active: tab === item }" :disabled="busy" @click="changeTab(item)">{{ item === 'contents' ? 'Video drafts' : item }}</button></nav>
                    <StatusPanel v-if="!writable" message="This channel is suspended. You can view your records; contact Zo Stream support to restore editing." />
                    <ChannelForm v-if="tab === 'profile'" :fields="profileFields" :record="detail.channel" :busy="busy || !writable" :upload-api="api" audience="creator" :channel-id="selected" title="Channel profile" @save="saveProfile" @cancel="loadChannel()" />
                    <template v-else>
                        <template v-if="tab === 'contents'">
                            <p class="creator-muted">Save video details, then choose Upload MP4 to add your private source. Staff review, media processing and publication are handled by Zo Stream. Editing published metadata returns it to draft.</p>
                            <button class="admin-primary" :disabled="busy || !writable" @click="editor = {}">New video draft</button>
                            <ChannelForm v-if="editor" :key="editor.id || 'new'" :fields="videoFields" :record="editor" :busy="busy || !writable" :upload-api="api" audience="creator" :channel-id="selected" :content-id="editor.id" title="Video details" @save="saveVideo" @cancel="editor = null" />
                        </template>
                        <p v-else class="creator-muted">Your channel membership plans. Contact Zo Stream Admin for pricing changes. New membership purchases are currently unavailable.</p>
                        <FileDropzone v-if="sourceVideo && tab === 'contents'" :key="sourceVideo.id" :api="api" audience="creator" :channel-id="selected" :content-id="sourceVideo.id" kind="source" :label="`Upload MP4 · ${sourceVideo.title}`" :disabled="busy || !writable" @busy="busy = $event" @uploaded="loadChannel(page); notice = 'MP4 uploaded privately for Zo Stream review.'" />
                        <ChannelTable :rows="rows" :columns="columns" :page="page" :last-page="lastPage" :total="total" @page="loadChannel">
                            <template v-if="tab === 'contents'" #actions="{ row }"><button :disabled="busy || !writable" @click="editor = { ...row }">Edit details</button><button :disabled="busy || !writable" @click="sourceVideo = row">Upload MP4</button><button :disabled="busy || !writable" @click="withdraw(row)">Withdraw</button></template>
                        </ChannelTable>
                    </template>
                </template>
            </template>
        </div>
    </main>
</template>
<style scoped>
.creator-root{min-height:100vh;padding:28px 20px;display:block}.creator-workspace{max-width:1100px;margin:auto}.creator-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;gap:14px}.creator-brand{display:flex;align-items:center;gap:12px;color:var(--a-text);text-decoration:none;font-weight:800}.creator-brand img{width:46px;height:46px;border-radius:12px}.creator-brand small{display:block;color:var(--a-cyan);font-size:10px;letter-spacing:2px;margin-top:4px}.creator-login{max-width:650px;margin:30px auto;padding:30px}.creator-login p,.creator-muted{color:var(--a-muted);line-height:1.7;font-size:13px}.creator-login h1{font-size:24px}.creator-channel-picker{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:20px 0}.creator-channel-picker label{display:grid;gap:7px;color:var(--a-muted);font-size:12px;flex:1}.creator-channel-picker select{padding:12px;background:var(--a-panel);color:var(--a-text);border:1px solid var(--a-line);border-radius:9px}.creator-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:20px 0}.creator-stats article{padding:20px}.creator-stats strong,.creator-stats span{display:block}.creator-stats strong{font-size:27px}.creator-stats span{font-size:12px;color:var(--a-muted);margin-top:5px}.creator-tabs{display:flex;gap:10px;margin:20px 0}.creator-tabs button{padding:12px 18px;border:1px solid var(--a-line);border-radius:9px;background:transparent;color:var(--a-muted);text-transform:capitalize}.creator-tabs button.active{color:var(--a-cyan);border-color:var(--a-cyan)}.creator-workspace :deep(.channel-form){margin:18px 0}button:disabled{opacity:.5}@media(max-width:600px){.creator-stats{grid-template-columns:1fr}.creator-root{padding:18px 10px}.creator-login{padding:20px}.creator-tabs{flex-wrap:wrap}}
</style>
