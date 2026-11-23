<script setup>
import { confirmAction } from '../lib/feedback';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import UserLookup from '../components/UserLookup.vue';
import { api } from '../lib/api';
import { formatAdminDate } from '../lib/adminDate';

const route = useRoute(); const router = useRouter();
const search = ref(''); const user = ref(null); const loading = ref(false);
const busy = ref(false); const error = ref(''); const notice = ref('');
let version = 0;
let mounted = true;
function flag(value) {
    if (value === true || value === 1 || value === '1') return true;
    if (value === false || value === 0 || value === '0') return false;
    return null;
}
const accountStatus = computed(() => flag(user.value?.isACActive) === null ? 'Unknown' : flag(user.value.isACActive) ? 'Active' : 'Suspended');
const sections = computed(() => user.value ? [
    { title: 'Account details', fields: [
        ['User ID', user.value.uid], ['Username', user.value.username ? `@${user.value.username}` : ''],
        ['Account status', accountStatus.value],
        ['Profile complete', flag(user.value.isAccountComplete) === null ? '—' : flag(user.value.isAccountComplete) ? 'Yes' : 'No'],
        ['Phone verified', flag(user.value.is_auth_phone_active) === null ? '—' : flag(user.value.is_auth_phone_active) ? 'Yes' : 'No'],
        ['Date of birth', formatAdminDate(user.value.dob, false)],
    ] },
    { title: 'Contact & location', fields: [
        ['Phone', user.value.auth_phone], ['Alternate phone', user.value.call],
        ['Email', user.value.mail], ['Country code', user.value.country_code],
        ['Town', user.value.khua], ['Locality', user.value.veng],
    ] },
    { title: 'Account activity', fields: [
        ['Created', formatAdminDate(user.value.created_date)], ['Last updated', formatAdminDate(user.value.edit_date)],
        ['Last login', formatAdminDate(user.value.lastLogin)], ['Last device', user.value.device_name],
        ['Device ID', user.value.device_id],
    ] },
] : []);
async function load() {
    const uid = String(route.query.user || '');
    const current = ++version;
    user.value = null; error.value = ''; loading.value = Boolean(uid);
    if (!uid) return;
    try {
        const response = await api(`/admin/users/${encodeURIComponent(uid)}`);
        if (current === version) user.value = response?.data ?? response;
    } catch (reason) { if (current === version) error.value = reason.message; }
    finally { if (current === version) loading.value = false; }
}
function selectUser(value) {
    search.value = ''; notice.value = '';
    if (String(route.query.user) === String(value.uid)) load();
    else router.replace({ query: { user: value.uid } });
}
function clearUser() { search.value = ''; notice.value = ''; router.replace({ query: {} }); }
async function manage(kind) {
    if (busy.value || !user.value) return;
    const uid = user.value.uid;
    const label = user.value.name || user.value.username || uid;
    if (!(await confirmAction(kind === 'delete' ? `Delete ${label}?` : `Suspend ${label}?`))) return;
    busy.value = true; error.value = ''; notice.value = '';
    try {
        await api(`/admin/users/${encodeURIComponent(uid)}`, kind === 'delete'
            ? { method: 'DELETE' }
            : { method: 'PUT', body: { isACActive: false } });
        if (!mounted || String(route.query.user) !== String(uid)) return;
        if (kind === 'delete') {
            await router.replace({ query: {} });
            notice.value = 'User deleted.';
        } else {
            await load();
            notice.value = 'User suspended.';
        }
    } catch (reason) { if (mounted && String(route.query.user) === String(uid)) error.value = reason.message; }
    finally { busy.value = false; }
}
watch(() => route.query.user, () => { notice.value = ''; load(); }, { immediate: true });
onBeforeUnmount(() => { mounted = false; version++; });
</script>

<template>
    <div class="admin-page users-page">
        <PageHeader eyebrow="Audience" title="Users" description="Find a user to review their account details and manage access.">
            <RouterLink to="/manage/users/new" class="admin-primary compact"><AdminIcon name="plus" /> Add user</RouterLink>
        </PageHeader>
        <section class="admin-panel user-search-panel" aria-label="User search">
            <label class="user-search-field"><span>Find a user</span><UserLookup v-model="search" label="Find user" @select="selectUser" /></label>
            <p>Search by username, name, phone, email or UID, then select a user.</p>
        </section>
        <StatusPanel tone="error" :message="error" /><StatusPanel tone="success" :message="notice" />
        <div v-if="loading" class="admin-panel admin-loading" role="status">Loading user details…</div>
        <template v-else-if="user">
            <section class="admin-panel user-identity" aria-label="Selected user details">
                <header><div class="user-heading"><span class="user-avatar">{{ (user.name || user.username || 'U').slice(0, 1).toUpperCase() }}</span><div><p>Selected user</p><h2>{{ user.name || user.username || 'Unnamed user' }}</h2><span v-if="user.username">@{{ user.username }}</span></div></div><span class="admin-pill" :class="{ 'is-active': accountStatus === 'Active' }">{{ accountStatus }}</span><button type="button" class="admin-secondary compact" @click="clearUser">Clear selection</button></header>
                <footer class="user-actions"><RouterLink :to="{ path: `/manage/users/${encodeURIComponent(user.uid)}`, query: { user: user.uid } }" class="admin-primary compact"><AdminIcon name="edit" /> Edit user</RouterLink><RouterLink :to="{ path: '/manage/subscriptions', query: { user: user.uid } }" class="admin-secondary compact">View subscriptions</RouterLink><button type="button" class="admin-secondary compact" :disabled="busy" @click="load">Refresh</button></footer>
            </section>
            <div class="user-detail-grid">
                <section v-for="section in sections" :key="section.title" class="admin-panel user-detail-section"><header><h2>{{ section.title }}</h2></header><dl><div v-for="[label, value] in section.fields" :key="label"><dt>{{ label }}</dt><dd>{{ value || '—' }}</dd></div></dl></section>
            </div>
            <section class="admin-panel user-management" aria-label="Account actions"><div><h2>Account actions</h2><p>Manage this user’s account access.</p></div><button v-if="accountStatus === 'Active'" type="button" class="admin-secondary compact" :disabled="busy" @click="manage('suspend')">Suspend user</button><button type="button" class="admin-danger compact" :disabled="busy" @click="manage('delete')">Delete user</button></section>
        </template>
        <section v-else-if="error" class="user-start"><button type="button" class="admin-secondary compact" @click="load">Retry</button></section>
        <section v-else class="user-start"><AdminIcon name="users" /><h2>Find a user to get started</h2><p>Their account details will appear here.</p></section>
    </div>
</template>

<style scoped>
.user-search-panel{padding:20px 24px;margin-bottom:20px}.user-search-field{display:grid;gap:9px;max-width:680px;font-size:13px;font-weight:600}.user-search-panel>p{margin:10px 0 0;color:var(--a-muted);font-size:12px;line-height:1.6}
.user-identity{margin-bottom:20px}.user-identity>header{align-items:center;flex-wrap:wrap}.user-heading{display:flex;align-items:center;gap:14px;flex:1;min-width:0}.user-avatar{display:grid;place-items:center;flex:none;width:48px;height:48px;border-radius:10px;background:var(--a-soft);color:var(--a-cyan);font-size:22px;font-weight:700}.user-heading p{margin:0 0 6px;color:var(--a-muted);font-size:11px}.user-heading h2{overflow-wrap:anywhere}.user-heading>div>span{display:block;margin-top:5px;color:var(--a-cyan);font-size:13px}.user-identity .is-active{color:var(--a-lime)}
.user-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:18px 24px}.user-actions a{display:inline-flex;align-items:center;gap:8px}.user-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:20px}.user-detail-section{min-width:0}.user-detail-section>header h2{font-size:17px}.user-detail-section dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;margin:0;padding:22px 24px}.user-detail-section dt{color:var(--a-muted);font-size:12px}.user-detail-section dd{margin:6px 0 0;overflow-wrap:anywhere;font-size:14px}
.user-management{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:20px 24px}.user-management>div{flex:1;min-width:180px}.user-management h2{margin:0;font-size:17px}.user-management p{margin:6px 0 0;font-size:12px;color:var(--a-muted)}.user-start{text-align:center;padding:40px 20px}.user-start>svg{width:32px;height:32px;margin:0 auto 14px;color:var(--a-muted)}.user-start h2{margin:0;font-size:18px}.user-start p{color:var(--a-muted);font-size:13px;line-height:1.7}
@media(max-width:700px){.user-search-panel,.user-management{padding:18px}.user-detail-grid{grid-template-columns:1fr}.user-actions{padding:18px}.user-detail-section dl{padding:18px;grid-template-columns:1fr}.user-heading{flex-basis:100%}}
</style>
