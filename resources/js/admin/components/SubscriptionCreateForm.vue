<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import AdminIcon from './AdminIcon.vue';
import { api, queryString } from '../lib/api';
import { deviceTypeLabel } from '../lib/platforms';

const props = defineProps({ model: { type: Object, required: true }, plans: { type: Array, default: () => [] }, saving: Boolean });
const emit = defineEmits(['save']);
const query = ref('');
const results = ref([]);
const searching = ref(false);
const searched = ref(false);
const searchError = ref('');
const selectedUser = ref(null);
let timer;
let searchVersion = 0;
const groups = computed(() => {
    const grouped = new Map();
    props.plans.forEach(plan => {
        const device = String(plan.device_type || 'other').toLowerCase();
        if (!grouped.has(device)) grouped.set(device, []);
        grouped.get(device).push(plan);
    });
    return [...grouped];
});
const selectedPlans = computed(() => props.plans.filter(plan => (props.model.plan_id || []).map(String).includes(String(plan.id))));
const total = computed(() => selectedPlans.value.reduce((sum, plan) => sum + Number(props.model.amount === '' || props.model.amount == null ? plan.price || 0 : props.model.amount), 0));
const ready = computed(() => Boolean(String(props.model.user_id || '').trim()) && selectedPlans.value.length > 0);
const paymentFields = [
    ['amount', 'Amount per plan', 'number', 'Use plan price'],
    ['currency', 'Currency', 'text', 'INR'],
    ['payment_method', 'Payment method', 'text', 'e.g. Cash, UPI, bank transfer'],
    ['payment_gateway', 'Payment gateway', 'text', 'e.g. Manual'],
    ['transaction_id', 'Transaction ID', 'text', 'Optional payment reference'],
    ['renewed_by', 'Renewed by', 'text', 'Optional admin reference'],
];
function money(value) {
    const amount = Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    return `${props.model.currency || 'INR'} ${amount}`;
}
function selected(plan) { return selectedPlans.value.some(item => String(item.id) === String(plan.id)); }
function toggle(plan) {
    const ids = (props.model.plan_id || []).map(String);
    props.model.plan_id = selected(plan) ? ids.filter(id => id !== String(plan.id)) : [...ids, String(plan.id)];
}
function queueSearch() {
    clearTimeout(timer);
    const version = ++searchVersion;
    results.value = []; searched.value = false; searchError.value = ''; searching.value = false;
    if (query.value.trim().length < 2) return;
    searching.value = true;
    timer = setTimeout(async () => {
        try {
            const response = await api(`/admin/users-search${queryString({ q: query.value.trim(), per_page: 12 })}`);
            if (version !== searchVersion) return;
            const data = response?.data ?? response;
            results.value = Array.isArray(data) ? data : data?.data || [];
            searched.value = true;
        } catch (reason) { if (version === searchVersion) searchError.value = reason.message; }
        finally { if (version === searchVersion) searching.value = false; }
    }, 300);
}
function chooseUser(user) {
    ++searchVersion; clearTimeout(timer);
    props.model.user_id = user.uid || user.auth_phone;
    selectedUser.value = user;
    query.value = ''; results.value = []; searching.value = false; searched.value = false; searchError.value = '';
}
onBeforeUnmount(() => { clearTimeout(timer); ++searchVersion; });
</script>

<template>
    <form class="subscription-create" :aria-busy="saving" @submit.prevent="emit('save')">
        <fieldset class="subscription-fields" :disabled="saving">
            <section class="subscription-card admin-panel">
                <header class="section-heading"><span class="step-number">01</span><div><h2>Choose a subscriber</h2><p>Find an existing account or enter their user ID directly.</p></div><AdminIcon name="users" /></header>
                <div class="section-body">
                    <label class="field"><span>Search subscribers</span><div class="subscriber-search"><AdminIcon name="search" /><input v-model="query" type="search" placeholder="Name, phone, email or UID…" autocomplete="off" @input="queueSearch"></div></label>
                    <p v-if="searching || searchError || (searched && !results.length)" class="field-help" role="status">{{ searching ? 'Searching subscribers…' : searchError || 'No subscribers found. Try another name, phone or email.' }}</p>
                    <div v-if="results.length" class="subscriber-results" aria-label="Search results"><button v-for="user in results" :key="user.uid || user.id || user.auth_phone" type="button" @click="chooseUser(user)"><span class="user-avatar">{{ (user.name || 'U').slice(0, 1).toUpperCase() }}</span><span><strong>{{ user.name || user.uid || user.auth_phone }}</strong><small>{{ user.auth_phone || user.mail || user.uid }}</small></span><span class="choose-label">Select →</span></button></div>
                    <div v-if="selectedUser && String(model.user_id) === String(selectedUser.uid || selectedUser.auth_phone)" class="selected-subscriber"><span class="user-avatar">{{ (selectedUser.name || 'U').slice(0, 1).toUpperCase() }}</span><div><strong>{{ selectedUser.name || 'Subscriber selected' }}</strong><small>{{ selectedUser.auth_phone || selectedUser.mail || model.user_id }}</small></div><span class="selected-tag">Selected</span></div>
                    <label class="field manual-user"><span>User ID or phone <b>*</b></span><input v-model="model.user_id" required placeholder="Enter user ID or phone number"></label>
                </div>
            </section>

            <section class="subscription-card admin-panel">
                <header class="section-heading"><span class="step-number">02</span><div><h2>Select plans</h2><p>Choose one or more plans across devices.</p></div><span class="count-tag">{{ selectedPlans.length }} selected</span></header>
                <div class="section-body plan-groups">
                    <div v-for="[device, devicePlans] in groups" :key="device" class="device-group">
                        <h3><AdminIcon :name="device === 'mobile' ? 'play' : 'monitor'" />{{ deviceTypeLabel(device) }}<span>{{ devicePlans.length }} plans</span></h3>
                        <div class="plan-grid"><label v-for="plan in devicePlans" :key="plan.id" class="plan-card" :class="{ 'is-selected': selected(plan) }"><div class="plan-top"><strong>{{ plan.name }}</strong><input type="checkbox" :checked="selected(plan)" @change="toggle(plan)"></div><div class="plan-price">₹{{ Number(plan.price || 0).toLocaleString('en-IN') }}<small>/ {{ plan.duration_days }} days</small></div><div class="plan-facts"><span>{{ plan.quality || 'Standard' }}</span><span v-if="plan.device_limit">{{ plan.device_limit }} {{ Number(plan.device_limit) === 1 ? 'device' : 'devices' }}</span></div></label></div>
                    </div>
                    <p v-if="!plans.length" class="empty-plans">No plans available. <RouterLink to="/manage/plans/new">Create a plan</RouterLink> to get started.</p>
                </div>
            </section>

            <section class="subscription-card admin-panel">
                <header class="section-heading"><span class="step-number">03</span><div><h2>Dates & payment</h2><p>Leave dates and amount blank to use each plan’s defaults.</p></div><AdminIcon name="credit-card" /></header>
                <div class="section-body">
                    <div class="field-grid"><label class="field"><span>Starts at</span><input v-model="model.start_at" type="date"></label><label class="field"><span>Ends at</span><input v-model="model.end_at" type="date" :min="model.start_at || undefined"></label></div>
                    <div class="payment-divider">Payment details <span>Optional</span></div>
                    <div class="field-grid"><label v-for="[name, label, type, placeholder] in paymentFields" :key="name" class="field"><span>{{ label }}</span><input v-model="model[name]" :type="type" :placeholder="placeholder" :min="type === 'number' ? 0 : undefined" :step="type === 'number' ? 'any' : undefined"></label></div>
                    <p v-if="selectedPlans.length > 1" class="field-help">An entered amount applies to each plan. Transaction references receive a plan ID suffix.</p>
                    <label class="access-toggle"><input v-model="model.is_active" type="checkbox"><span><strong>Active subscription</strong><small>Subscription access status</small></span></label>
                </div>
            </section>
        </fieldset>

        <aside class="subscription-summary admin-panel">
            <div class="summary-eyebrow"><AdminIcon name="badge" />Subscription summary</div>
            <h2>Ready for access.</h2><p class="summary-intro">Review the details before creating the subscription.</p>
            <div class="summary-user"><span class="summary-label">Subscriber</span><strong>{{ model.user_id || 'Choose a subscriber' }}</strong></div>
            <div v-if="!selectedPlans.length" class="summary-empty"><AdminIcon name="layers" /><p>Your selected plans will appear here.</p></div>
            <ul v-else class="summary-plans"><li v-for="plan in selectedPlans" :key="plan.id"><div><strong>{{ plan.name }}</strong><small>{{ deviceTypeLabel(plan.device_type) }} · {{ plan.duration_days }} days</small></div><b>{{ money(model.amount === '' || model.amount == null ? plan.price : model.amount) }}</b></li></ul>
            <dl class="summary-dates"><div><dt>Starts</dt><dd>{{ model.start_at || 'Default start date' }}</dd></div><div><dt>Ends</dt><dd>{{ model.end_at || 'Based on each plan' }}</dd></div></dl>
            <div class="summary-total"><span>Total payment<small>{{ selectedPlans.length }} {{ selectedPlans.length === 1 ? 'plan' : 'plans' }}</small></span><strong>{{ money(total) }}</strong></div>
            <p class="summary-note"><AdminIcon name="file" />Payment history is recorded for each selected plan.</p>
            <button type="submit" class="admin-primary create-button" :disabled="saving || !ready"><AdminIcon name="plus" />{{ saving ? 'Creating…' : selectedPlans.length > 1 ? `Create ${selectedPlans.length} subscriptions` : 'Create subscription' }}</button>
            <RouterLink class="cancel-link" to="/manage/subscriptions">Cancel</RouterLink>
            <p v-if="!ready" class="ready-hint">Choose a subscriber and at least one plan to continue.</p>
        </aside>
    </form>
</template>

<style scoped>
.subscription-create{display:grid;grid-template-columns:minmax(0,1fr) 320px;align-items:start;gap:24px;color:var(--a-text)}
.subscription-fields{display:grid;gap:22px;min-width:0;margin:0;padding:0;border:0}.subscription-fields:disabled{opacity:.7}
.subscription-card{overflow:hidden}.subscription-card .section-heading{display:flex;align-items:center;gap:14px;padding:22px 24px;background:var(--glass-hover);border-bottom:1px solid var(--a-line)}
.step-number{display:grid;place-items:center;flex:none;width:36px;height:36px;border:1px solid var(--a-line);border-radius:12px;color:var(--a-cyan);font-size:12px;font-weight:800}
.section-heading>div{flex:1;min-width:0}.subscription-card .section-heading h2{margin:0;font-size:18px;letter-spacing:-.4px}.section-heading p{margin:5px 0 0;color:var(--a-muted);font-size:12px;line-height:1.6}.section-heading>svg{color:var(--a-muted);flex:none}.section-body{padding:24px}
.field{display:grid;gap:9px;min-width:0;font-size:12px;font-weight:600}.field b{color:var(--a-cyan)}.field input{width:100%;min-width:0;height:46px;border:1px solid var(--a-line);border-radius:11px;padding:0 13px;background:var(--glass-field);color:var(--a-text);font-size:13px;outline:none}.field input:focus{border-color:var(--a-cyan);box-shadow:0 0 0 3px var(--a-soft)}
.subscriber-search{position:relative}.subscriber-search>svg{position:absolute;left:13px;top:13px;color:var(--a-muted)}.subscriber-search input{padding-left:42px}.manual-user{margin-top:18px}.field-help{margin:12px 0 0;color:var(--a-muted);font-size:12px;line-height:1.6}
.subscriber-results{display:grid;max-height:280px;overflow:auto;margin-top:10px;padding:5px;border:1px solid var(--a-line);border-radius:12px;background:var(--glass-field)}.subscriber-results button{display:flex;align-items:center;gap:12px;padding:12px;border:0;border-radius:8px;background:transparent;color:var(--a-text);text-align:left}.subscriber-results button:hover{background:var(--glass-hover)}.subscriber-results button>span:nth-child(2){display:grid;gap:4px;min-width:0;overflow-wrap:anywhere}.subscriber-results strong{font-size:13px}.subscriber-results small{color:var(--a-muted);font-size:11px}.choose-label{margin-left:auto;color:var(--a-cyan);font-size:11px;white-space:nowrap}
.user-avatar{display:grid;place-items:center;width:38px;height:38px;flex:none;border-radius:12px;background:var(--a-soft);color:var(--a-cyan);font-weight:800}.selected-subscriber{display:flex;align-items:center;gap:12px;margin-top:16px;padding:14px;border:1px solid var(--a-line);border-radius:12px;background:var(--glass-hover)}.selected-subscriber>div{display:grid;gap:4px;min-width:0;overflow-wrap:anywhere}.selected-subscriber strong{font-size:13px}.selected-subscriber small{color:var(--a-muted);font-size:11px}.selected-tag{margin-left:auto;color:var(--a-lime);font-size:11px}
.count-tag{flex:none;border:1px solid var(--a-line);border-radius:20px;padding:6px 10px;color:var(--a-cyan);font-size:11px}.plan-groups{display:grid;gap:24px}.device-group h3{display:flex;align-items:center;gap:8px;margin:0 0 12px;font-size:13px}.device-group h3>svg{width:16px;height:16px;color:var(--a-muted)}.device-group h3>span{margin-left:auto;color:var(--a-muted);font-size:11px;font-weight:400}.plan-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.plan-card{min-width:0;padding:17px;border:1px solid var(--a-line);border-radius:14px;background:var(--glass-field);cursor:pointer;transition:border-color .16s,background .16s}.plan-card:hover,.plan-card:focus-within{border-color:var(--a-cyan)}.plan-card.is-selected{border-color:var(--a-cyan);background:var(--glass-hover);box-shadow:inset 0 0 0 1px var(--a-cyan)}.plan-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.plan-top strong{font-size:13px;overflow-wrap:anywhere}.plan-top input{width:17px;height:17px;flex:none;accent-color:var(--a-cyan);cursor:pointer}.plan-price{margin-top:18px;font-size:24px;font-weight:750;letter-spacing:-.7px}.plan-price small{margin-left:5px;color:var(--a-muted);font-size:11px;font-weight:400;letter-spacing:0}.plan-facts{display:flex;flex-wrap:wrap;gap:7px;margin-top:13px}.plan-facts span{padding:4px 7px;border-radius:6px;background:var(--glass-hover);color:var(--a-muted);font-size:10px}.empty-plans{margin:0;color:var(--a-muted);font-size:13px}.empty-plans a{color:var(--a-cyan);text-decoration:underline}
.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.payment-divider{display:flex;justify-content:space-between;margin:24px 0 18px;padding-top:22px;border-top:1px solid var(--a-line);font-size:13px;font-weight:700}.payment-divider span{color:var(--a-muted);font-size:11px;font-weight:400}.access-toggle{display:flex;align-items:center;gap:12px;margin-top:22px;padding:15px;border:1px solid var(--a-line);border-radius:12px}.access-toggle input{width:18px;height:18px;accent-color:var(--a-cyan)}.access-toggle>span{display:grid;gap:4px}.access-toggle strong{font-size:12px}.access-toggle small{color:var(--a-muted);font-size:11px}
.subscription-summary{position:sticky;top:24px;padding:24px;background:var(--glass-sheen),var(--glass-table)}.summary-eyebrow{display:flex;align-items:center;gap:9px;color:var(--a-cyan);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px}.summary-eyebrow svg{width:17px;height:17px}.subscription-summary h2{margin:20px 0 8px;font-size:25px;letter-spacing:-1px}.summary-intro{margin:0;color:var(--a-muted);font-size:12px;line-height:1.7}.summary-user{display:grid;gap:8px;margin-top:24px;padding-bottom:20px;border-bottom:1px solid var(--a-line);overflow-wrap:anywhere}.summary-label{color:var(--a-muted);font-size:11px}.summary-user strong{font-size:13px}.summary-empty{display:grid;justify-items:center;gap:10px;padding:25px 15px;margin-top:20px;border:1px dashed var(--a-line);border-radius:12px;color:var(--a-muted)}.summary-empty p{margin:0;text-align:center;font-size:12px;line-height:1.6}.summary-plans{display:grid;gap:16px;margin:22px 0;padding:0;list-style:none}.summary-plans li{display:flex;justify-content:space-between;gap:12px}.summary-plans li>div{display:grid;gap:5px;min-width:0}.summary-plans strong{font-size:12px;overflow-wrap:anywhere}.summary-plans small{color:var(--a-muted);font-size:10px}.summary-plans b{flex:none;font-size:12px}.summary-dates{display:grid;gap:12px;margin:22px 0;color:var(--a-muted);font-size:11px}.summary-dates>div{display:flex;justify-content:space-between;gap:12px}.summary-dates dd{margin:0;color:var(--a-text);text-align:right}.summary-total{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:22px 0;border-top:1px solid var(--a-line)}.summary-total>span{font-size:12px}.summary-total small{display:block;margin-top:5px;color:var(--a-muted);font-size:10px}.summary-total>strong{font-size:25px;letter-spacing:-1px;overflow-wrap:anywhere}.summary-note{display:flex;align-items:flex-start;gap:8px;margin:0 0 22px;color:var(--a-muted);font-size:11px;line-height:1.6}.summary-note svg{flex:none;width:15px;height:15px;margin-top:2px}.subscription-summary .create-button{display:flex;width:100%;min-height:46px;align-items:center;justify-content:center;gap:7px;padding:10px;font-size:12px}.create-button:disabled{opacity:.45;cursor:not-allowed}.cancel-link{display:block;margin-top:16px;text-align:center;color:var(--a-muted);font-size:12px}.cancel-link:hover{color:var(--a-text)}.ready-hint{margin:16px 0 0;text-align:center;color:var(--a-muted);font-size:11px;line-height:1.6}.subscription-create :is(button,a,input):focus-visible{outline:2px solid var(--a-cyan);outline-offset:3px}
@media(min-width:1500px){.subscription-create{grid-template-columns:minmax(0,1fr) 350px;gap:28px}}
@media(max-width:1150px){.subscription-create{grid-template-columns:minmax(0,1fr)}.subscription-summary{position:static}.summary-plans{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.subscription-card .section-heading{padding:18px 16px;gap:10px}.subscription-card .section-heading h2{font-size:16px}.section-heading>svg{display:none}.section-heading p{font-size:11px}.section-body,.subscription-summary{padding:18px}.plan-grid,.field-grid,.summary-plans{grid-template-columns:1fr}.count-tag{font-size:10px;padding:5px 8px}.subscription-fields{gap:16px}.selected-tag{display:none}.plan-price{font-size:23px}}
</style>
