<script setup>
import { confirmAction } from '../lib/feedback';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import UserLookup from '../components/UserLookup.vue';
import { api, queryString } from '../lib/api';
import { formatAdminDate } from '../lib/adminDate';
import { deviceTypeLabel } from '../lib/platforms';

const route = useRoute(); const router = useRouter();
const search = ref(''); const user = ref(null); const subscriptions = ref([]);
const loading = ref(false); const error = ref(''); const notice = ref(''); const deleting = ref(null); const device = ref('');
const page = ref(1); const lastPage = ref(1); const total = ref(0);
let version = 0;
const devices = ['mobile', 'tv', 'desktop', 'browser'];
const orderedSubscriptions = computed(() => [...subscriptions.value].sort((left, right) => {
    const rank = item => { const index = devices.indexOf(item.plan?.device_type); return index < 0 ? devices.length : index; };
    return rank(left) - rank(right);
}));
function dataOf(response) { return response?.data ?? response; }
function status(subscription) {
    if (!subscription.is_active) return 'Inactive';
    if (!subscription.end_at) return 'No expiry date';
    const end = new Date(subscription.end_at);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return Number.isNaN(end.getTime()) ? 'No expiry date' : end < today ? 'Expired' : 'Active';
}
async function load(nextPage = 1) {
    const uid = String(route.query.user || '');
    const current = ++version;
    error.value = ''; subscriptions.value = []; loading.value = Boolean(uid);
    if (!uid) { user.value = null; return; }
    try {
        if (String(user.value?.uid) !== uid) {
            user.value = null;
            const record = dataOf(await api(`/admin/users/${encodeURIComponent(uid)}`));
            if (current !== version) return;
            user.value = record;
        }
        const result = dataOf(await api(`/admin/subscriptions${queryString({ user_id: uid, device_type: device.value, page: nextPage, per_page: 20 })}`));
        if (current !== version) return;
        subscriptions.value = Array.isArray(result) ? result : result?.data || [];
        page.value = result?.current_page || nextPage; lastPage.value = result?.last_page || 1;
        total.value = result?.total ?? subscriptions.value.length;
    } catch (reason) { if (current === version) error.value = reason.message; }
    finally { if (current === version) loading.value = false; }
}
function selectUser(value) {
    user.value = value; search.value = ''; device.value = ''; notice.value = '';
    if (String(route.query.user) === String(value.uid)) load();
    else router.replace({ query: { user: value.uid } });
}
async function remove(subscription) {
    if (!(await confirmAction(`Delete subscription #${subscription.id}?`))) return;
    const uid = user.value?.uid;
    deleting.value = subscription.id; error.value = ''; notice.value = '';
    try {
        await api(`/admin/subscriptions/${encodeURIComponent(subscription.id)}`, { method: 'DELETE' });
        if (String(route.query.user) === String(uid)) {
            notice.value = 'Subscription deleted.';
            await load(subscriptions.value.length === 1 && page.value > 1 ? page.value - 1 : page.value);
        }
    } catch (reason) { if (String(route.query.user) === String(uid)) error.value = reason.message; }
    finally { deleting.value = null; }
}
function clearUser() { notice.value = ''; search.value = ''; user.value = null; device.value = ''; router.replace({ query: {} }); }
watch(() => route.query.user, () => { device.value = ''; load(); }, { immediate: true });
onBeforeUnmount(() => version++);
</script>

<template>
    <div class="admin-page subscribers-page">
        <PageHeader eyebrow="Audience" title="Subscribers" description="Find a user to review their details and subscription access.">
            <RouterLink to="/manage/subscriptions/new" class="admin-primary compact"><AdminIcon name="plus" /> Add subscriber</RouterLink>
        </PageHeader>
        <section class="admin-panel subscriber-search-panel" aria-label="Subscriber search">
            <label class="subscriber-search-field"><span>Find a subscriber</span><UserLookup v-model="search" label="Find subscriber" @select="selectUser" /></label>
            <p>Search by username, name, phone, email or UID, then select a user.</p>
        </section>
        <StatusPanel tone="error" :message="error" /><StatusPanel tone="success" :message="notice" />
        <section v-if="user" class="admin-panel subscriber-details" aria-label="Selected subscriber details">
            <header><div class="subscriber-heading"><span class="subscriber-avatar">{{ (user.name || user.username || 'U').slice(0, 1).toUpperCase() }}</span><div><p>Selected subscriber</p><h2>{{ user.name || user.username || 'Unnamed user' }}</h2><span v-if="user.username">@{{ user.username }}</span></div></div><button type="button" class="admin-secondary compact" @click="clearUser">Clear selection</button></header>
            <dl class="subscriber-facts"><div><dt>Username</dt><dd>{{ user.username ? `@${user.username}` : '—' }}</dd></div><div><dt>Phone</dt><dd>{{ user.auth_phone || user.call || '—' }}</dd></div><div><dt>Email</dt><dd>{{ user.mail || '—' }}</dd></div><div><dt>User ID</dt><dd>{{ user.uid }}</dd></div></dl>
        </section>
        <section v-if="user" class="subscriber-access" aria-label="Subscription details">
            <header class="subscriber-access-heading"><div><h2>Subscription access</h2><p>{{ loading ? 'Loading subscriptions…' : `${total} ${total === 1 ? 'subscription' : 'subscriptions'}` }}</p></div><label>Device<select v-model="device" :disabled="loading" @change="load()"><option value="">All devices</option><option v-for="value in devices" :key="value" :value="value">{{ deviceTypeLabel(value) }}</option></select></label><button type="button" class="admin-secondary compact" :disabled="loading" @click="load(page)">Refresh</button></header>
            <div v-if="loading" class="admin-panel admin-loading" role="status">Loading subscription details…</div>
            <div v-else-if="error && !subscriptions.length" class="admin-panel subscriber-empty"><p>Subscription details could not be loaded. Try refreshing.</p></div>
            <div v-else-if="!subscriptions.length" class="admin-panel subscriber-empty"><AdminIcon name="badge" /><h3>No subscriptions found</h3><p>{{ device ? 'This user has no subscriptions for the selected device.' : 'This user does not have any subscriptions yet.' }}</p><RouterLink to="/manage/subscriptions/new" class="admin-primary compact">Add subscription</RouterLink></div>
            <div v-else class="subscriber-plan-grid">
                <article v-for="subscription in orderedSubscriptions" :key="subscription.id" class="admin-panel subscriber-plan">
                    <header><div><p>{{ deviceTypeLabel(subscription.plan?.device_type) }}</p><h3>{{ subscription.plan?.name || `Plan ${subscription.plan_id}` }}</h3></div><span class="admin-pill" :class="{ 'is-active': status(subscription) === 'Active' }">{{ status(subscription) }}</span></header>
                    <dl><div><dt>Starts</dt><dd>{{ formatAdminDate(subscription.start_at) }}</dd></div><div><dt>Expires</dt><dd>{{ formatAdminDate(subscription.end_at) }}</dd></div><div><dt>Devices</dt><dd>{{ subscription.devices?.length || 0 }}<template v-if="subscription.plan?.device_limit"> / {{ subscription.plan.device_limit }}</template></dd></div><div><dt>Subscription ID</dt><dd>#{{ subscription.id }}</dd></div></dl>
                    <footer><RouterLink :to="{ path: `/manage/subscriptions/${subscription.id}`, query: { user: user.uid } }" class="admin-secondary compact"><AdminIcon name="edit" /> Edit subscription</RouterLink><button type="button" class="admin-danger compact" :disabled="deleting !== null" @click="remove(subscription)">{{ deleting === subscription.id ? 'Deleting…' : 'Delete' }}</button></footer>
                </article>
            </div>
            <footer v-if="lastPage > 1 && !loading" class="admin-pagination"><button :disabled="page <= 1" @click="load(page - 1)">Previous</button><span>Page {{ page }} / {{ lastPage }}</span><button :disabled="page >= lastPage" @click="load(page + 1)">Next</button></footer>
        </section>
        <section v-else-if="!loading && !error" class="subscriber-start"><AdminIcon name="users" /><h2>Find a subscriber to get started</h2><p>Their account and subscription details will appear here.</p></section>
        <div v-else-if="loading" class="admin-loading" role="status">Loading subscriber…</div>
    </div>
</template>

<style scoped>
.subscriber-search-panel{padding:20px 24px;margin-bottom:20px}.subscriber-search-field{display:grid;gap:9px;max-width:680px;font-size:13px;font-weight:600}.subscriber-search-panel>p{margin:10px 0 0;color:var(--a-muted);font-size:12px;line-height:1.6}
.subscriber-details{margin-bottom:24px}.subscriber-details>header{align-items:center;flex-wrap:wrap}.subscriber-heading{display:flex;align-items:center;gap:14px;min-width:0}.subscriber-avatar{display:grid;place-items:center;flex:none;width:48px;height:48px;border-radius:10px;background:var(--a-soft);color:var(--a-cyan);font-size:22px;font-weight:700}.subscriber-heading p,.subscriber-plan header p{margin:0 0 6px;color:var(--a-muted);font-size:11px}.subscriber-heading h2{overflow-wrap:anywhere}.subscriber-heading>div>span{display:block;margin-top:5px;color:var(--a-cyan);font-size:13px}
.subscriber-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin:0;padding:22px 24px}.subscriber-facts dt,.subscriber-plan dt{color:var(--a-muted);font-size:12px}.subscriber-facts dd,.subscriber-plan dd{margin:6px 0 0;overflow-wrap:anywhere;font-size:14px}
.subscriber-access-heading{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}.subscriber-access-heading>div{flex:1;min-width:180px}.subscriber-access-heading h2{margin:0;font-size:20px}.subscriber-access-heading p{margin:6px 0 0;font-size:12px;color:var(--a-muted)}.subscriber-access-heading label{display:grid;gap:5px;color:var(--a-muted);font-size:12px}.subscriber-access-heading select{min-height:38px;padding:0 12px;border:1px solid var(--a-line);border-radius:8px;background:var(--surface-field);color:var(--a-text);font:inherit}
.subscriber-plan-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.subscriber-plan{min-width:0;overflow:hidden}.subscriber-plan>header{padding:20px;align-items:start}.subscriber-plan h3{margin:0;font-size:17px;overflow-wrap:anywhere}.subscriber-plan .admin-pill{flex:none}.subscriber-plan .is-active{color:var(--a-lime)}.subscriber-plan dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin:0;padding:20px}.subscriber-plan>footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:0 20px 20px}.subscriber-plan footer a{display:inline-flex;gap:8px;align-items:center}
.subscriber-empty,.subscriber-start{text-align:center;padding:40px 20px}.subscriber-empty>svg,.subscriber-start>svg{width:32px;height:32px;margin:0 auto 14px;color:var(--a-muted)}.subscriber-empty h3,.subscriber-start h2{margin:0;font-size:18px}.subscriber-empty p,.subscriber-start p{color:var(--a-muted);font-size:13px;line-height:1.7}.subscriber-empty a{display:inline-flex;margin-top:10px}
@media(max-width:700px){.subscriber-search-panel{padding:18px}.subscriber-facts{grid-template-columns:1fr;padding:18px}.subscriber-plan-grid{grid-template-columns:1fr}.subscriber-access-heading>div{flex-basis:100%}}
</style>
