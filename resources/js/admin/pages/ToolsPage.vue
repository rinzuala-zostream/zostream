<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const route = useRoute(); const tool = computed(() => route.params.tool);
const busy = ref(false); const error = ref(''); const notice = ref(''); const clients = ref([]);
const device = reactive({ user_id: '', device_type: '', device_token: '' });
const otp = reactive({ phone_number: '', country_code: '+91', user_id: '' });
const push = reactive({ title: '', body: '', topic: 'all', token: '', image: '', key: '', data: '{}' });
const release = reactive({ platform: 'update', version: '', enabled: true, force: false, url: '' });
const wa = reactive({ to: '', type: 'text', message: '', template_name: '', template_params: '' });
const warning = reactive({ txt: '', platform: 'all', isShow: false, isCancelable: true, isShowInLatest: false });
const scroll = reactive({ text: '', show: false });
const client = reactive({ id: '', platform: 'android', name: '', enabled: true, verification_mode: 'manual', app_identifier: '', certificate_sha256: '', team_id: '', key_id: '', build_id: '', min_version: '', latest_version: '', api_base_url: '', api_version: '4', allowed_origins: '', metadata: '{}' });
const titles = { operations: ['Admin tools', 'Run focused user and device operations.'], notifications: ['Notifications', 'Manage push, WhatsApp, warnings and scrolling text.'], 'app-releases': ['App releases', 'Control update availability across supported platforms.'], 'official-clients': ['Official clients', 'Manage trusted application identities and verification policy.'] };
const heading = computed(() => titles[tool.value] || titles.operations);

async function run(action) {
    busy.value = true; error.value = ''; notice.value = '';
    try { notice.value = await action() || 'Operation completed.'; }
    catch (reason) { error.value = reason.message; }
    finally { busy.value = false; }
}
const clearDevices = () => run(async () => { const r = await api('/admin/devices/clear', { method: 'POST', body: device }); return r?.message || r?.data?.message || 'Devices cleared.'; });
const requestOtp = () => run(async () => { const r = await api('/auth/otp/request', { method: 'POST', body: otp }); return r?.message || r?.data?.message || 'OTP requested.'; });
const sendPush = () => run(async () => { const r = await api('/admin/notifications/push', { method: 'POST', body: { ...push, data: JSON.parse(push.data || '{}') } }); return r?.message || r?.data?.message || 'Push sent.'; });
const sendWa = () => run(async () => { const r = await api('/admin/whatsapp/send', { method: 'POST', body: { ...wa, template_params: wa.template_params ? wa.template_params.split('\n').filter(Boolean) : undefined } }); return r?.message || r?.data?.message || 'WhatsApp sent.'; });
const saveWarning = () => run(async () => { await api('/admin/realtime/warning', { method: 'PUT', body: warning }); return 'Warning configuration saved.'; });
const saveScroll = () => run(async () => { await api('/admin/realtime/text-scroll', { method: 'PUT', body: scroll }); return 'Scrolling text saved.'; });
const deleteWarning = () => run(async () => { if (!confirm('Delete the warning configuration?')) return ''; await api('/admin/realtime/warning', { method: 'DELETE' }); Object.assign(warning, { txt: '', platform: 'all', isShow: false, isCancelable: true, isShowInLatest: false }); return 'Warning deleted.'; });
const deleteScroll = () => run(async () => { if (!confirm('Delete the scrolling text?')) return ''; await api('/admin/realtime/text-scroll', { method: 'DELETE' }); Object.assign(scroll, { text: '', show: false }); return 'Scrolling text deleted.'; });
async function loadNotifications() {
    try { const [w, s] = await Promise.all([api('/admin/realtime/warning'), api('/admin/realtime/text-scroll')]); Object.assign(warning, w || {}); Object.assign(scroll, s || {}); }
    catch (reason) { error.value = reason.message; }
}
async function loadRelease() {
    error.value = '';
    try { const r = await api(`/app-releases/${release.platform}`); Object.assign(release, r?.data || r || {}, { platform: release.platform }); }
    catch (reason) { error.value = reason.message; }
}
const saveRelease = () => run(async () => {
    const versionField = { update: 'v_code', ios_update: 'v_code', lg_tv_update: 'version', sam_tv_update: 'version', tv_update: 'v' }[release.platform];
    const numeric = ['update', 'ios_update', 'tv_update'].includes(release.platform);
    const r = await api(`/admin/app-releases/${release.platform}`, { method: 'PUT', body: { enabled: release.enabled, force: release.force, url: release.url, [versionField]: numeric ? Number(release.version || 0) : release.version } });
    return r?.message || r?.data?.message || 'Release saved.';
});
async function loadClients() { try { clients.value = await api('/admin/official-clients') || []; } catch (reason) { error.value = reason.message; } }
function editClient(value) { Object.assign(client, value, { certificate_sha256: Array.isArray(value.certificate_sha256) ? value.certificate_sha256.join('\n') : value.certificate_sha256 || '', allowed_origins: Array.isArray(value.allowed_origins) ? value.allowed_origins.join('\n') : '', metadata: JSON.stringify(value.metadata || {}, null, 2) }); }
function resetClient() { Object.assign(client, { id: '', platform: 'android', name: '', enabled: true, verification_mode: 'manual', app_identifier: '', certificate_sha256: '', team_id: '', key_id: '', build_id: '', min_version: '', latest_version: '', api_base_url: '', api_version: '4', allowed_origins: '', metadata: '{}' }); }
const saveClient = () => run(async () => {
    const id = client.id; const payload = { ...client, certificate_sha256: client.certificate_sha256.split(/\n|,/).map(v => v.trim()).filter(Boolean), allowed_origins: client.allowed_origins.split('\n').map(v => v.trim()).filter(Boolean), metadata: JSON.parse(client.metadata || '{}') }; delete payload.id;
    await api(`/admin/official-clients${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: payload }); await loadClients(); resetClient(); return 'Official client saved.';
});
const deleteClient = (id) => run(async () => { if (!confirm('Delete this official client?')) return ''; await api(`/admin/official-clients/${id}`, { method: 'DELETE' }); await loadClients(); return 'Official client deleted.'; });

watch(() => release.platform, loadRelease);
watch(tool, async () => {
    error.value = ''; notice.value = '';
    if (tool.value === 'app-releases') await loadRelease();
    if (tool.value === 'notifications') await loadNotifications();
    if (tool.value === 'official-clients') await loadClients();
}, { immediate: true });
</script>

<template>
    <div class="admin-page narrow">
        <PageHeader eyebrow="Operations" :title="heading[0]" :description="heading[1]" />
        <StatusPanel tone="success" :message="notice" /><StatusPanel tone="error" :message="error" />
        <div v-if="tool === 'operations'" class="admin-tool-grid">
            <form class="admin-panel admin-tool" @submit.prevent="clearDevices"><header><div><p>DEVICE ACCESS</p><h2>Clear user devices</h2></div></header><label>User ID<input v-model="device.user_id" required></label><label>Device type<select v-model="device.device_type"><option value="">All</option><option>mobile</option><option>browser</option><option>tv</option></select></label><label>Device token<input v-model="device.device_token"></label><button class="admin-danger" :disabled="busy">Clear matching devices</button></form>
            <form class="admin-panel admin-tool" @submit.prevent="requestOtp"><header><div><p>USER SUPPORT</p><h2>Request login OTP</h2></div></header><label>Country code<input v-model="otp.country_code"></label><label>Phone number<input v-model="otp.phone_number" required></label><label>User ID<input v-model="otp.user_id"></label><button class="admin-primary" :disabled="busy">Request OTP</button></form>
        </div>
        <div v-else-if="tool === 'notifications'" class="admin-tool-grid">
            <form class="admin-panel admin-tool" @submit.prevent="sendPush"><header><div><p>PUSH</p><h2>Create notification</h2></div></header><label>Title<input v-model="push.title" required></label><label>Message<textarea v-model="push.body" required /></label><label>Topic<input v-model="push.topic"></label><label>Device token<input v-model="push.token"></label><label>Image URL<input v-model="push.image" type="url"></label><label>Routing key<input v-model="push.key"></label><label>Data JSON<textarea v-model="push.data" /></label><button class="admin-primary" :disabled="busy">Send push</button></form>
            <form class="admin-panel admin-tool" @submit.prevent="sendWa"><header><div><p>WHATSAPP</p><h2>Send message</h2></div></header><label>Phone number<input v-model="wa.to" required></label><label>Type<select v-model="wa.type"><option value="text">Text</option><option value="template">Template</option></select></label><label v-if="wa.type === 'text'">Message<textarea v-model="wa.message" required /></label><template v-else><label>Template<input v-model="wa.template_name" required></label><label>Parameters<textarea v-model="wa.template_params" /></label></template><button class="admin-primary" :disabled="busy">Send WhatsApp</button></form>
            <form class="admin-panel admin-tool" @submit.prevent="saveWarning"><header><div><p>IN-APP WARNING</p><h2>Warning banner</h2></div></header><label>HTML / message<textarea v-model="warning.txt" required /></label><label>Platform<select v-model="warning.platform"><option>all</option><option>android</option><option>ios</option></select></label><label><input v-model="warning.isShow" type="checkbox"> Show warning</label><label><input v-model="warning.isCancelable" type="checkbox"> User can dismiss</label><label><input v-model="warning.isShowInLatest" type="checkbox"> Show in latest version</label><div class="admin-tool-actions"><button type="button" class="admin-danger" :disabled="busy" @click="deleteWarning">Delete</button><button class="admin-primary" :disabled="busy">Save warning</button></div></form>
            <form class="admin-panel admin-tool" @submit.prevent="saveScroll"><header><div><p>SCROLLING TEXT</p><h2>App ticker</h2></div></header><label>Text<textarea v-model="scroll.text" required /></label><label><input v-model="scroll.show" type="checkbox"> Show scrolling text</label><div class="admin-tool-actions"><button type="button" class="admin-danger" :disabled="busy" @click="deleteScroll">Delete</button><button class="admin-primary" :disabled="busy">Save scrolling text</button></div></form>
        </div>
        <form v-else-if="tool === 'app-releases'" class="admin-panel admin-editor" @submit.prevent="saveRelease"><div class="admin-form-grid"><label><span>Platform</span><select v-model="release.platform"><option value="update">Android</option><option value="ios_update">iOS</option><option value="lg_tv_update">LG TV</option><option value="sam_tv_update">Samsung TV</option><option value="tv_update">Android TV</option></select></label><label><span>Version</span><input v-model="release.version" required></label><label class="wide"><span>Download URL</span><input v-model="release.url" type="url"></label><label class="check"><input v-model="release.enabled" type="checkbox"><span>Enabled</span></label><label class="check"><input v-model="release.force" type="checkbox"><span>Force update</span></label></div><footer><button class="admin-primary" :disabled="busy">Save release</button></footer></form>
        <div v-else class="admin-clients-layout">
            <form class="admin-panel admin-editor" @submit.prevent="saveClient"><div class="admin-form-grid"><label><span>Platform</span><input v-model="client.platform" required></label><label><span>Name</span><input v-model="client.name" required></label><label><span>Verification mode</span><input v-model="client.verification_mode" required></label><label><span>App identifier</span><input v-model="client.app_identifier"></label><label><span>Team ID</span><input v-model="client.team_id"></label><label><span>Key ID</span><input v-model="client.key_id"></label><label><span>Build ID</span><input v-model="client.build_id"></label><label><span>Minimum version</span><input v-model="client.min_version"></label><label><span>Latest version</span><input v-model="client.latest_version"></label><label><span>API version</span><input v-model="client.api_version"></label><label class="wide"><span>Certificate SHA-256 (one per line)</span><textarea v-model="client.certificate_sha256" /></label><label class="wide"><span>Allowed origins (one per line)</span><textarea v-model="client.allowed_origins" /></label><label class="wide"><span>API base URL</span><input v-model="client.api_base_url" type="url"></label><label class="wide"><span>Metadata JSON</span><textarea v-model="client.metadata" /></label><label class="check"><input v-model="client.enabled" type="checkbox"><span>Enabled</span></label></div><footer><button v-if="client.id" type="button" class="admin-secondary" @click="resetClient">Cancel edit</button><button class="admin-primary" :disabled="busy">{{ client.id ? 'Save changes' : 'Add official client' }}</button></footer></form>
            <section class="admin-panel admin-client-list"><article v-for="item in clients" :key="item.id"><div><p>{{ item.platform }}</p><h3>{{ item.name }}</h3><span>{{ item.app_identifier || item.verification_mode }}</span></div><div><button class="admin-secondary" @click="editClient(item)">Edit</button><button class="admin-danger" @click="deleteClient(item.id)">Delete</button></div></article><p v-if="!clients.length" class="admin-empty">No official clients configured.</p></section>
        </div>
    </div>
</template>
