<script setup>
import { confirmAction } from '../lib/feedback';
import UserLookup from '../components/UserLookup.vue';
import AdminIcon from '../components/AdminIcon.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';
import { deviceTypes, desktopPlatforms, platformLabel } from '../lib/platforms';
import { releaseFileError, releaseUploadExtensions } from '../lib/releases';

const route = useRoute(); const tool = computed(() => route.params.tool);
const busy = ref(false); const releaseLoading = ref(false); const error = ref(''); const notice = ref(''); const clients = ref([]);
const device = reactive({ user_id: '', device_type: '', platform: '', device_token: '' });
const otp = reactive({ phone_number: '', country_code: '+91', user_id: '' });
const otpDiagnostic = reactive({ phone_number: '', country_code: '+91', user_id: '' });
const revealedOtp = ref('');
const push = reactive({ title: '', body: '', topic: 'all', token: '', image: '', key: '', data: '{}' });
const release = reactive({ platform: 'update', version: '', enabled: true, force: false, url: '' });
const releaseFile = ref(null); const releaseFileInput = ref(null); const releaseUploading = ref(false);
let releaseUploadAbort = null;
const releaseExtensions = computed(() => releaseUploadExtensions[release.platform] || []);
const releaseAccept = computed(() => releaseExtensions.value.map(value => `.${value}`).join(','));
const wa = reactive({ to: '', type: 'text', message: '', template_name: '', template_params: '' });
const warning = reactive({ txt: '', platform: 'all', isShow: false, isCancelable: true, isShowInLatest: false });
const scroll = reactive({ text: '', show: false });
const client = reactive({ id: '', platform: 'android', name: '', enabled: true, verification_enabled: true, verification_mode: 'manual', app_identifier: '', certificate_sha256: '', team_id: '', key_id: '', build_id: '', min_version: '', latest_version: '', api_base_url: '', api_version: '4', allowed_origins: '', metadata: '{}' });
const titles = { operations: ['Admin tools', 'Run focused user and device operations.'], notifications: ['Notifications', 'Manage push, WhatsApp, warnings and scrolling text.'], 'app-releases': ['App releases', 'Control update availability across supported platforms.'], 'official-clients': ['Official clients', 'Manage trusted application identities and verification policy.'] };
const heading = computed(() => titles[tool.value] || titles.operations);
const activeTool = ref('devices');
const toolSections = computed(() => tool.value === 'notifications' ? [
    { id: 'push', title: 'Push notification', description: 'Send to app devices', icon: 'bell' },
    { id: 'whatsapp', title: 'WhatsApp', description: 'Text or template message', icon: 'message' },
    { id: 'warning', title: 'Warning banner', description: 'Manage the in-app notice', icon: 'monitor' },
    { id: 'ticker', title: 'Scrolling text', description: 'Manage the app ticker', icon: 'file' },
] : [
    { id: 'devices', title: 'Device access', description: 'Clear a user’s devices', icon: 'monitor' },
    { id: 'otp', title: 'Request OTP', description: 'Send a login code', icon: 'message' },
    { id: 'diagnostic', title: 'OTP support', description: 'Send and reveal a code', icon: 'users' },
]);

async function run(action) {
    busy.value = true; error.value = ''; notice.value = '';
    try { notice.value = await action() || 'Operation completed.'; }
    catch (reason) { error.value = reason.message; }
    finally { busy.value = false; }
}
const clearDevices = () => run(async () => { const r = await api('/admin/devices/clear', { method: 'POST', body: device }); return r?.message || r?.data?.message || 'Devices cleared.'; });
const requestOtp = () => run(async () => { const r = await api('/auth/otp/request', { method: 'POST', body: otp }); return r?.message || r?.data?.message || 'OTP requested.'; });
const revealOtp = () => run(async () => { revealedOtp.value = ''; const r = await api('/admin/otp/diagnostic', { method: 'POST', body: otpDiagnostic }); revealedOtp.value = r?.otp || ''; return r?.message || 'OTP sent.'; });
const sendPush = () => run(async () => { const r = await api('/admin/notifications/push', { method: 'POST', body: { ...push, data: JSON.parse(push.data || '{}') } }); return r?.message || r?.data?.message || 'Push sent.'; });
const sendWa = () => run(async () => { const r = await api('/admin/whatsapp/send', { method: 'POST', body: { ...wa, template_params: wa.template_params ? wa.template_params.split('\n').filter(Boolean) : undefined } }); return r?.message || r?.data?.message || 'WhatsApp sent.'; });
const saveWarning = () => run(async () => { await api('/admin/realtime/warning', { method: 'PUT', body: warning }); return 'Warning configuration saved.'; });
const saveScroll = () => run(async () => { await api('/admin/realtime/text-scroll', { method: 'PUT', body: scroll }); return 'Scrolling text saved.'; });
const deleteWarning = () => run(async () => { if (!(await confirmAction('Delete the warning configuration?'))) return ''; await api('/admin/realtime/warning', { method: 'DELETE' }); Object.assign(warning, { txt: '', platform: 'all', isShow: false, isCancelable: true, isShowInLatest: false }); return 'Warning deleted.'; });
const deleteScroll = () => run(async () => { if (!(await confirmAction('Delete the scrolling text?'))) return ''; await api('/admin/realtime/text-scroll', { method: 'DELETE' }); Object.assign(scroll, { text: '', show: false }); return 'Scrolling text deleted.'; });
async function loadNotifications() {
    try { const [w, s] = await Promise.all([api('/admin/realtime/warning'), api('/admin/realtime/text-scroll')]); Object.assign(warning, w || {}); Object.assign(scroll, s || {}); }
    catch (reason) { error.value = reason.message; }
}
async function loadRelease() {
    releaseFile.value = null;
    if (releaseFileInput.value) releaseFileInput.value.value = '';
    releaseLoading.value = true; error.value = '';
    const platform = release.platform;
    Object.assign(release, { version: '', enabled: true, force: false, url: '' });
    try { const r = await api(`/app-releases/${platform}`); if (release.platform === platform) Object.assign(release, r?.data || r || {}, { platform }); }
    catch (reason) { if (reason.status !== 404) error.value = reason.message; }
    finally { releaseLoading.value = false; }
}
const saveRelease = () => {
    if (busy.value || releaseLoading.value || releaseUploading.value) return;
    if (releaseFile.value) { error.value = 'Upload the selected installer before saving the release.'; return; }
    return run(async () => {
    const versionField = { update: 'v_code', ios_update: 'v_code', lg_tv_update: 'version', sam_tv_update: 'version', tv_update: 'v', windows_update: 'version', macos_update: 'version' }[release.platform];
    const numeric = ['update', 'ios_update', 'tv_update'].includes(release.platform);
    const r = await api(`/admin/app-releases/${release.platform}`, { method: 'PUT', body: { enabled: release.enabled, force: release.force, url: release.url, [versionField]: numeric ? Number(release.version || 0) : release.version } });
    const saved = r?.data || r;
    if (saved && typeof saved === 'object') Object.assign(release, saved, { platform: release.platform });
    await loadRelease();
    return r?.message || 'Release saved to Firebase.';
    });
};
function chooseReleaseFile(event) {
    const file = event.target.files?.[0] || null;
    error.value = file ? releaseFileError(release.platform, file) : '';
    releaseFile.value = error.value ? null : file;
    if (error.value) event.target.value = '';
}
async function uploadRelease() {
    if (busy.value || releaseLoading.value || releaseUploading.value) return;
    error.value = releaseFileError(release.platform, releaseFile.value);
    if (error.value) return;
    const platform = release.platform;
    const abort = new AbortController(); releaseUploadAbort = abort;
    const form = new FormData(); form.append('file', releaseFile.value);
    releaseUploading.value = true; notice.value = '';
    try {
        const result = await api(`/admin/app-releases/${platform}/upload`, { method: 'POST', body: form, signal: abort.signal });
        if (release.platform !== platform || tool.value !== 'app-releases' || abort.signal.aborted) return;
        const url = result?.url;
        if (!url || new URL(url).protocol !== 'https:') throw new Error('The upload did not return a download URL. Please try again.');
        release.url = url;
        releaseFile.value = null;
        if (releaseFileInput.value) releaseFileInput.value.value = '';
        notice.value = 'Installer uploaded. Download URL is ready. Save the release to publish it.';
    } catch (reason) {
        if (!abort.signal.aborted) error.value = reason.message;
    } finally {
        if (releaseUploadAbort === abort) { releaseUploading.value = false; releaseUploadAbort = null; }
    }
}
async function copyReleaseUrl() {
    try { await navigator.clipboard.writeText(release.url); notice.value = 'Download URL copied.'; }
    catch { error.value = 'Could not copy automatically. Select and copy the Download URL above.'; }
}
onBeforeUnmount(() => releaseUploadAbort?.abort());
async function loadClients() { try { clients.value = await api('/admin/official-clients') || []; } catch (reason) { error.value = reason.message; } }
function editClient(value) { Object.assign(client, value, { verification_enabled: value.verification_enabled !== false, certificate_sha256: Array.isArray(value.certificate_sha256) ? value.certificate_sha256.join('\n') : value.certificate_sha256 || '', allowed_origins: Array.isArray(value.allowed_origins) ? value.allowed_origins.join('\n') : '', metadata: JSON.stringify(value.metadata || {}, null, 2) }); }
function resetClient() { Object.assign(client, { id: '', platform: 'android', name: '', enabled: true, verification_enabled: true, verification_mode: 'manual', app_identifier: '', certificate_sha256: '', team_id: '', key_id: '', build_id: '', min_version: '', latest_version: '', api_base_url: '', api_version: '4', allowed_origins: '', metadata: '{}' }); }
const saveClient = () => run(async () => {
    const id = client.id; const payload = { ...client, certificate_sha256: client.certificate_sha256.split(/\n|,/).map(v => v.trim()).filter(Boolean), allowed_origins: client.allowed_origins.split('\n').map(v => v.trim()).filter(Boolean), metadata: JSON.parse(client.metadata || '{}') }; delete payload.id;
    await api(`/admin/official-clients${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: payload }); await loadClients(); resetClient(); return 'Official client saved.';
});
const deleteClient = (id) => run(async () => { if (!(await confirmAction('Delete this official client?'))) return ''; await api(`/admin/official-clients/${id}`, { method: 'DELETE' }); await loadClients(); return 'Official client deleted.'; });

watch(() => release.platform, loadRelease);
watch(() => device.device_type, () => { device.platform = ''; });
watch(tool, async () => {
    releaseUploadAbort?.abort();
    error.value = ''; notice.value = '';
    activeTool.value = tool.value === 'notifications' ? 'push' : 'devices';
    if (tool.value === 'app-releases') await loadRelease();
    if (tool.value === 'notifications') await loadNotifications();
    if (tool.value === 'official-clients') await loadClients();
}, { immediate: true });
</script>

<template>
    <div class="admin-page narrow">
        <PageHeader eyebrow="Operations" :title="heading[0]" :description="heading[1]" />
        <StatusPanel tone="success" :message="notice" /><StatusPanel tone="error" :message="error" />
        <div v-if="tool === 'operations' || tool === 'notifications'" class="admin-tools-workspace">
            <nav class="admin-tool-menu" :aria-label="`${heading[0]} sections`">
                <button v-for="section in toolSections" :key="section.id" type="button" :class="{ active: activeTool === section.id }" :aria-pressed="activeTool === section.id" @click="activeTool = section.id">
                    <AdminIcon :name="section.icon" /><span><strong>{{ section.title }}</strong><small>{{ section.description }}</small></span>
                </button>
            </nav>
            <form v-if="activeTool === 'devices'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="clearDevices">
                <header><div><p>DEVICE ACCESS</p><h2>Clear user devices</h2><span>Choose a user and the devices to clear.</span></div><AdminIcon name="monitor" /></header>
                <label>User<UserLookup v-model="device.user_id" required /></label>
                <div class="admin-tool-fields">
                    <label>Device type<select v-model="device.device_type"><option value="">All devices</option><option v-for="item in deviceTypes" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                    <label v-if="device.device_type === 'desktop'">Desktop platform<select v-model="device.platform"><option value="">Mac &amp; Windows</option><option v-for="item in desktopPlatforms" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                </div>
                <label>Device token <small>Optional · Leave empty to clear all matching devices.</small><input v-model="device.device_token" placeholder="Specific device token"></label>
                <footer class="admin-tool-actions"><button class="admin-danger" :disabled="busy"><AdminIcon name="trash" />Clear matching devices</button></footer>
            </form>
            <form v-else-if="activeTool === 'otp'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="requestOtp">
                <header><div><p>USER SUPPORT</p><h2>Request login OTP</h2><span>Send a login code to the customer’s phone.</span></div><AdminIcon name="message" /></header>
                <div class="admin-tool-fields admin-tool-phone"><label>Country code<input v-model="otp.country_code" autocomplete="tel-country-code"></label><label>Phone number<input v-model="otp.phone_number" type="tel" autocomplete="tel-national" required></label></div>
                <label>User <small>Optional · Search by name, username, phone or UID.</small><UserLookup v-model="otp.user_id" /></label>
                <footer class="admin-tool-actions"><button class="admin-primary" :disabled="busy"><AdminIcon name="message" />Request OTP</button></footer>
            </form>
            <form v-else-if="activeTool === 'diagnostic'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="revealOtp">
                <header><div><p>RESTRICTED SUPPORT</p><h2>Send &amp; reveal OTP</h2><span>Help a customer complete their login.</span></div><AdminIcon name="users" /></header>
                <p class="admin-tool-callout">The phone number must exactly match the registered number for the selected user.</p>
                <label>User<UserLookup v-model="otpDiagnostic.user_id" required /></label>
                <div class="admin-tool-fields admin-tool-phone"><label>Country code<input v-model="otpDiagnostic.country_code" autocomplete="tel-country-code" required></label><label>Phone number<input v-model="otpDiagnostic.phone_number" type="tel" autocomplete="tel-national" required></label></div>
                <footer class="admin-tool-actions"><button class="admin-primary" :disabled="busy"><AdminIcon name="message" />Send and reveal OTP</button></footer>
                <p v-if="revealedOtp" class="admin-tool-code" role="status">Login code<strong>{{ revealedOtp }}</strong></p>
            </form>
            <form v-else-if="activeTool === 'push'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="sendPush">
                <header><div><p>PUSH NOTIFICATION</p><h2>Create notification</h2><span>Write a message and choose its recipients.</span></div><AdminIcon name="bell" /></header>
                <label>Title<input v-model="push.title" placeholder="Notification title" required></label>
                <label>Message<textarea v-model="push.body" placeholder="Write your notification…" required /></label>
                <fieldset class="admin-tool-group"><legend>Recipients</legend><label>Topic<input v-model="push.topic" placeholder="all"></label><label>Device token <small>Optional · Target a specific device.</small><input v-model="push.token"></label></fieldset>
                <details class="admin-tool-options"><summary>Image, destination &amp; extra data</summary><div><label>Image URL<input v-model="push.image" type="url" placeholder="https://…"></label><label>Movie public ID / routing key<input v-model="push.key" placeholder="Movie ID opens its details when tapped"></label><label>Data JSON<textarea v-model="push.data" spellcheck="false" /></label></div></details>
                <footer class="admin-tool-actions"><button class="admin-primary" :disabled="busy"><AdminIcon name="bell" />Send push</button></footer>
            </form>
            <form v-else-if="activeTool === 'whatsapp'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="sendWa">
                <header><div><p>WHATSAPP</p><h2>Send message</h2><span>Send a text message or an approved template.</span></div><AdminIcon name="message" /></header>
                <div class="admin-tool-fields"><label>Phone number<input v-model="wa.to" type="tel" placeholder="Country code + phone number" required></label><label>Message type<select v-model="wa.type"><option value="text">Text message</option><option value="template">Template message</option></select></label></div>
                <label v-if="wa.type === 'text'">Message<textarea v-model="wa.message" placeholder="Write your message…" required /></label>
                <template v-else><label>Template name<input v-model="wa.template_name" required></label><label>Parameters <small>Enter one parameter per line.</small><textarea v-model="wa.template_params" /></label></template>
                <footer class="admin-tool-actions"><button class="admin-primary" :disabled="busy"><AdminIcon name="message" />Send WhatsApp</button></footer>
            </form>
            <form v-else-if="activeTool === 'warning'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="saveWarning">
                <header><div><p>IN-APP WARNING</p><h2>Warning banner</h2><span>Control the notice customers see in the app.</span></div><span class="admin-tool-state" :class="{ enabled: warning.isShow }">{{ warning.isShow ? 'Visible' : 'Hidden' }}</span></header>
                <label>HTML / message<textarea v-model="warning.txt" required /></label>
                <label>Platform<select v-model="warning.platform"><option value="all">All platforms</option><option value="android">Android</option><option value="ios">iOS</option><option v-for="item in desktopPlatforms" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                <fieldset class="admin-tool-group"><legend>Display settings</legend><label class="admin-tool-check"><input v-model="warning.isShow" type="checkbox"><span>Show warning<small>Make the banner visible in the app.</small></span></label><label class="admin-tool-check"><input v-model="warning.isCancelable" type="checkbox"><span>User can dismiss<small>Allow customers to close the warning.</small></span></label><label class="admin-tool-check"><input v-model="warning.isShowInLatest" type="checkbox"><span>Show in latest version<small>Include customers using the latest release.</small></span></label></fieldset>
                <footer class="admin-tool-actions admin-tool-actions-split"><button type="button" class="admin-danger" :disabled="busy" @click="deleteWarning"><AdminIcon name="trash" />Delete warning</button><button class="admin-primary" :disabled="busy">Save warning</button></footer>
            </form>
            <form v-else-if="activeTool === 'ticker'" class="admin-panel admin-tool admin-tool-card" @submit.prevent="saveScroll">
                <header><div><p>SCROLLING TEXT</p><h2>App ticker</h2><span>Set the scrolling announcement shown in the app.</span></div><span class="admin-tool-state" :class="{ enabled: scroll.show }">{{ scroll.show ? 'Visible' : 'Hidden' }}</span></header>
                <label>Announcement<textarea v-model="scroll.text" placeholder="Write your announcement…" required /></label>
                <label class="admin-tool-check"><input v-model="scroll.show" type="checkbox"><span>Show scrolling text<small>Make the announcement visible to customers.</small></span></label>
                <footer class="admin-tool-actions admin-tool-actions-split"><button type="button" class="admin-danger" :disabled="busy" @click="deleteScroll"><AdminIcon name="trash" />Delete ticker</button><button class="admin-primary" :disabled="busy">Save scrolling text</button></footer>
            </form>
        </div>
        <form v-else-if="tool === 'app-releases'" class="admin-panel admin-editor" @submit.prevent="saveRelease">
            <div class="admin-form-grid">
                <label><span>Platform</span><select v-model="release.platform" :disabled="busy || releaseLoading || releaseUploading"><option value="update">Android</option><option value="ios_update">iOS</option><option value="lg_tv_update">LG TV</option><option value="sam_tv_update">Samsung TV</option><option value="tv_update">Android TV</option><option value="windows_update">Windows</option><option value="macos_update">Mac (macOS)</option></select></label>
                <label><span>Version</span><input v-model="release.version" :disabled="busy || releaseLoading || releaseUploading" :placeholder="releaseExtensions.length ? 'e.g. 0.1.0' : 'Version'" required></label>
                <div v-if="releaseExtensions.length" class="wide">
                    <label><span>Installer file</span><input ref="releaseFileInput" type="file" :accept="releaseAccept" :disabled="busy || releaseLoading || releaseUploading" @change="chooseReleaseFile"></label>
                    <p class="admin-tool-note">{{ releaseExtensions.map(value => `.${value}`).join(', ') }} · Up to 512 MB</p>
                    <div class="admin-tool-actions">
                        <button type="button" class="admin-secondary" :disabled="!releaseFile || busy || releaseLoading || releaseUploading" @click="uploadRelease">{{ releaseUploading ? 'Uploading…' : 'Upload installer' }}</button>
                        <span v-if="releaseFile" role="status">{{ releaseFile.name }} · {{ (releaseFile.size / 1024 / 1024).toFixed(1) }} MB</span>
                    </div>
                </div>
                <label class="wide"><span>Download URL</span><input v-model="release.url" :disabled="busy || releaseLoading || releaseUploading" type="url"></label>
                <div v-if="releaseExtensions.length && release.url.startsWith('https://')" class="wide admin-tool-actions">
                    <button type="button" class="admin-secondary" :disabled="busy || releaseLoading || releaseUploading" @click="copyReleaseUrl">Copy URL</button>
                    <a :href="release.url" target="_blank" rel="noopener noreferrer">Download installer</a>
                </div>
                <label class="check"><input v-model="release.enabled" type="checkbox" :disabled="busy || releaseLoading || releaseUploading" @change="saveRelease"><span>Enabled</span></label>
                <label class="check"><input v-model="release.force" type="checkbox" :disabled="busy || releaseLoading || releaseUploading"><span>Force update</span></label>
            </div>
            <footer><button class="admin-primary" :disabled="busy || releaseLoading || releaseUploading">{{ releaseLoading ? 'Loading…' : releaseUploading ? 'Uploading…' : busy ? 'Saving…' : 'Save release' }}</button></footer>
        </form>
        <div v-else class="admin-clients-layout">
            <form class="admin-panel admin-editor" @submit.prevent="saveClient"><div class="admin-form-grid"><label><span>Platform</span><input v-model="client.platform" list="official-client-platforms" required><datalist id="official-client-platforms"><option value="android"/><option value="android-tv"/><option value="ios"/><option value="web"/><option value="webos"/><option value="tizen"/><option value="windows"/><option value="macos"/></datalist></label><label><span>Name</span><input v-model="client.name" required></label><label><span>Verification mode</span><input v-model="client.verification_mode" required></label><label><span>App identifier</span><input v-model="client.app_identifier"></label><label><span>Team ID</span><input v-model="client.team_id"></label><label><span>Key ID</span><input v-model="client.key_id"></label><label><span>Build ID</span><input v-model="client.build_id"></label><label><span>Minimum version</span><input v-model="client.min_version"></label><label><span>Latest version</span><input v-model="client.latest_version"></label><label><span>API version</span><input v-model="client.api_version"></label><label class="wide"><span>Certificate SHA-256 (one per line)</span><textarea v-model="client.certificate_sha256" /></label><label class="wide"><span>Allowed origins (one per line)</span><textarea v-model="client.allowed_origins" /></label><label class="wide"><span>API base URL</span><input v-model="client.api_base_url" type="url"></label><label class="wide"><span>Metadata JSON</span><textarea v-model="client.metadata" /></label><label class="check"><input v-model="client.enabled" type="checkbox"><span>Client configuration enabled</span></label><label class="check"><input v-model="client.verification_enabled" type="checkbox"><span>Enforce app verification</span></label></div><footer><button v-if="client.id" type="button" class="admin-secondary" @click="resetClient">Cancel edit</button><button class="admin-primary" :disabled="busy">{{ client.id ? 'Save changes' : 'Add official client' }}</button></footer></form>
            <section class="admin-panel admin-client-list"><article v-for="item in clients" :key="item.id"><div><p>{{ platformLabel(item.platform) }}</p><h3>{{ item.name }}</h3><span>{{ item.app_identifier || item.verification_mode }}</span></div><div><button class="admin-secondary" @click="editClient(item)">Edit</button><button class="admin-danger" @click="deleteClient(item.id)">Delete</button></div></article><p v-if="!clients.length" class="admin-empty">No official clients configured.</p></section>
        </div>
    </div>
</template>
