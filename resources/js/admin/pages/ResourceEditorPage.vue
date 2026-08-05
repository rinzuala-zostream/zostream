<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';
import { normalizeForSubmit, resources } from '../lib/resources';

const route = useRoute(); const router = useRouter();
const key = computed(() => route.params.resource); const resource = computed(() => resources[key.value]);
const editing = computed(() => route.params.id && route.params.id !== 'new');
const model = reactive({}); const loading = ref(false); const saving = ref(false); const error = ref(''); const notice = ref('');
const relationQuery = ref(''); const relationBusy = ref(false); const relationResults = ref([]); const plans = ref([]);
const existingFeatures = ref([]); const existingOptions = ref([]); let relationTimer;
const legalSections = ref([{ heading: '', body: '' }]);
const files = reactive({});
const seasonEpisodes = ref([]); const episodeIncrease = ref(0);
const currentRecord = ref(null);
const episodePpvOpen = ref(false);

function valueData(response) {
    const value = response?.data ?? response;
    return value?.data ?? value ?? {};
}
function storedDateInputValue(value, includeTime = false) {
    if (value == null || value === '') return '';
    const text = String(value).trim();
    const iso = text.match(/^(\d{4}-\d{2}-\d{2})(?:[T\s](\d{2}:\d{2}(?::\d{2})?))?/);
    if (iso) return includeTime && iso[2] ? `${iso[1]}T${iso[2].slice(0, 5)}` : iso[1];
    const parsed = Date.parse(text);
    if (Number.isNaN(parsed)) return text;
    const date = new Date(parsed);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return includeTime ? `${year}-${month}-${day}T${hours}:${minutes}` : `${year}-${month}-${day}`;
}
function init() {
    Object.keys(model).forEach(name => delete model[name]); Object.keys(files).forEach(name => delete files[name]);
    resource.value?.fields.forEach(field => { model[field.name] = field.default ?? (field.type === 'checkbox' ? false : ''); });
    if (key.value === 'subscriptions' && !editing.value) model.plan_id = [];
    currentRecord.value = null; existingFeatures.value = []; existingOptions.value = []; seasonEpisodes.value = []; episodeIncrease.value = 0; episodePpvOpen.value = false; legalSections.value = [{ heading: '', body: '' }]; relationResults.value = []; relationQuery.value = ''; error.value = ''; notice.value = '';
}
function fill(record) {
    resource.value.fields.forEach(field => {
        const value = record[field.name];
        if (field.type === 'lines') {
            const rows = key.value === 'polls' ? (record.options || []) : (record.features || []);
            model[field.name] = rows.map(row => typeof row === 'string' ? row : row.option_text || row.feature || '').filter(Boolean).join('\n');
        } else if (field.type === 'json') model[field.name] = JSON.stringify(value || [], null, 2);
        else if (field.type === 'date' && value) model[field.name] = storedDateInputValue(value);
        else if (field.type === 'datetime-local' && value) model[field.name] = storedDateInputValue(value, true);
        else if (value != null) model[field.name] = value;
    });
}
async function loadPlans() {
    if (!['subscriptions'].includes(key.value)) return;
    const response = valueData(await api('/admin/plans?per_page=100'));
    plans.value = Array.isArray(response) ? response : response.data || [];
}
async function load() {
    init(); loading.value = true;
    try {
        await loadPlans();
        if (!editing.value) return;
        let endpoint = `${resource.value.endpoint}/${encodeURIComponent(route.params.id)}`;
        if (key.value === 'movies') endpoint = `/catalog/items/${route.params.id}`;
        if (key.value === 'seasons') endpoint = `/catalog/seasons/${route.params.id}`;
        if (key.value === 'episodes') endpoint = `/catalog/episodes/${route.params.id}`;
        const record = valueData(await api(endpoint)); currentRecord.value = record; fill(record);
        if (key.value === 'seasons') seasonEpisodes.value = (record.episodes || []).map(episode => ({ id: episode.id, title: episode.title || `Episode ${episode.episode_number}`, isPayPerView: Boolean(episode.isPayPerView), amount: Number(episode.amount || 0) }));
        if (key.value === 'legal') legalSections.value = record.sections?.length ? record.sections.map(section => ({ ...section })) : [{ heading: '', body: '' }];
        if (key.value === 'plans') {
            const featureData = valueData(await api(`/admin/plans/${route.params.id}/features`));
            existingFeatures.value = featureData.features || record.features || [];
            model.features = existingFeatures.value.map(row => row.feature).filter(Boolean).join('\n');
            model.ppv_discount = existingFeatures.value[0]?.ppv_discount ?? '';
        }
        if (key.value === 'polls') existingOptions.value = record.options || [];
        if (key.value === 'episodes') {
            const urls = valueData(await api(`/admin/catalog/episodes/${route.params.id}/urls`));
            const first = (Array.isArray(urls) ? urls : urls.data || [])[0];
            if (first) { model.url = first.url || ''; model.type = first.type || 'DASH'; model.quality = first.quality || 'HD'; }
            model.movie_id ||= record.season?.movie_id || record.season?.movie?.num || '';
        }
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
const relationContext = computed(() => {
    if (!editing.value || !['seasons', 'episodes'].includes(key.value)) return null;
    const record = currentRecord.value || {};
    if (key.value === 'seasons') {
        const movieTitle = record.movie?.title || record.movie_title || (model.movie_id ? `Movie ${model.movie_id}` : 'Unknown movie');
        return {
            eyebrow: 'Currently editing season',
            title: model.title || `Season ${model.season_number || ''}`.trim() || record.id,
            subtitle: movieTitle,
            facts: [
                model.season_number ? `Season ${model.season_number}` : '',
                model.status || '',
                record.id ? `ID ${record.id}` : ''
            ].filter(Boolean)
        };
    }
    const season = record.season || {};
    const movieTitle = season.movie?.title || record.movie_title || (model.movie_id ? `Movie ${model.movie_id}` : '');
    const seasonTitle = season.title || record.season_title || model.season_id || '';
    return {
        eyebrow: 'Currently editing episode',
        title: model.title || `Episode ${model.episode_number || ''}`.trim() || record.id,
        subtitle: [movieTitle, seasonTitle].filter(Boolean).join(' · ') || 'Season not loaded',
        facts: [
            model.episode_number ? `Episode ${model.episode_number}` : '',
            model.status || '',
            record.id ? `ID ${record.id}` : ''
        ].filter(Boolean)
    };
});
const relationSearchLabel = computed(() => {
    if (key.value === 'subscriptions') return 'Find user by name, phone, email or UID';
    if (editing.value && key.value === 'seasons') return 'Change movie by movie title';
    if (editing.value && key.value === 'episodes') return 'Change season by movie title';
    return 'Find movie / season by movie title';
});
const plansByDevice = computed(() => {
    const groups = {};
    plans.value.forEach(plan => {
        const deviceType = String(plan.device_type || 'other').toLowerCase();
        groups[deviceType] ||= [];
        groups[deviceType].push(plan);
    });
    return Object.entries(groups).sort(([left], [right]) => left.localeCompare(right));
});
const episodePpvSelectedCount = computed(() => seasonEpisodes.value.filter(episode => episode.isPayPerView).length);
const episodePpvTotal = computed(() => seasonEpisodes.value.reduce((total, episode) => total + (episode.isPayPerView ? Number(episode.amount || 0) : 0), 0));
function toggleSubscriptionPlan(planId) {
    const id = String(planId);
    const selected = Array.isArray(model.plan_id) ? model.plan_id.map(String) : [];
    model.plan_id = selected.includes(id)
        ? selected.filter(value => value !== id)
        : [...selected, id];
}
function isSubscriptionPlanSelected(planId) {
    const selected = Array.isArray(model.plan_id) ? model.plan_id.map(String) : [String(model.plan_id || '')];
    return selected.includes(String(planId));
}
function queueRelationSearch() {
    clearTimeout(relationTimer);
    relationTimer = setTimeout(searchRelations, 300);
}
async function searchRelations() {
    const query = relationQuery.value.trim(); if (query.length < 2) { relationResults.value = []; return; }
    relationBusy.value = true; error.value = '';
    try {
        if (key.value === 'subscriptions') {
            const data = valueData(await api(`/admin/users-search${queryString({ q: query, per_page: 20 })}`));
            relationResults.value = Array.isArray(data) ? data : data.data || [];
        } else {
            const movies = valueData(await api(`/admin/catalog/seasons/search${queryString({ q: query, limit: 20 })}`));
            relationResults.value = (Array.isArray(movies) ? movies : []).flatMap(movie => {
                if (key.value === 'seasons') return [{ type: 'movie', id: movie.num || movie.id, label: movie.title }];
                return (movie.seasons || []).map(season => ({ type: 'season', id: season.id, movieId: movie.num || movie.id, label: `${movie.title} · Season ${season.season_number}` }));
            });
        }
    } catch (reason) { error.value = reason.message; }
    finally { relationBusy.value = false; }
}
function selectRelation(item) {
    if (key.value === 'subscriptions') model.user_id = item.uid || item.auth_phone;
    if (key.value === 'seasons') model.movie_id = item.id;
    if (key.value === 'episodes') { model.season_id = item.id; model.movie_id = item.movieId; }
    relationQuery.value = item.label || item.name || item.uid || item.auth_phone; relationResults.value = [];
}
function splitSeasonAmount() {
    const selected = seasonEpisodes.value.filter(episode => episode.isPayPerView); if (!selected.length) return;
    const base = Number(model.amount || 0) / selected.length; const value = base + (base * Number(episodeIncrease.value || 0) / 100);
    selected.forEach(episode => { episode.amount = Math.round(value * 100) / 100; });
}
function openEpisodePpv() {
    episodePpvOpen.value = true;
}
async function saveEpisodePpv() {
    await save();
    if (!error.value) episodePpvOpen.value = false;
}
async function syncPlanFeatures(planId, names, discount) {
    for (let index = 0; index < names.length; index++) {
        const payload = { feature: names[index], ppv_discount: Number(discount || 0), sort_order: index + 1, is_active: true };
        const current = existingFeatures.value[index];
        await api(current?.id ? `/admin/plan-features/${current.id}` : `/admin/plans/${planId}/features`, { method: current?.id ? 'PUT' : 'POST', body: payload });
    }
    for (const removed of existingFeatures.value.slice(names.length)) await api(`/admin/plan-features/${removed.id}`, { method: 'DELETE' });
}
async function syncPollOptions(pollId, rows) {
    for (let index = 0; index < rows.length; index++) {
        const payload = { option_text: rows[index].option_text, sort_order: index };
        const current = existingOptions.value[index];
        await api(current?.id ? `/admin/poll-options/${current.id}` : `/admin/polls/${pollId}/options`, { method: current?.id ? 'PUT' : 'POST', body: payload });
    }
    for (const removed of existingOptions.value.slice(rows.length)) await api(`/admin/poll-options/${removed.id}`, { method: 'DELETE' });
}
async function save() {
    saving.value = true; error.value = ''; notice.value = '';
    try {
        if (key.value === 'legal') model.sections = JSON.stringify(legalSections.value);
        const payload = normalizeForSubmit(key.value, model);
        if (key.value === 'legal' && (!payload.sections?.length || payload.sections.some(section => !section.heading?.trim() || !section.body?.trim()))) throw new Error('Every legal section needs a heading and content.');
        if (payload.start_at && payload.end_at && new Date(payload.end_at) < new Date(payload.start_at)) throw new Error('End date cannot be earlier than start date.');
        let planFeatures = []; let discount = 0;
        if (key.value === 'plans') { planFeatures = payload.features || []; discount = payload.ppv_discount; delete payload.features; delete payload.ppv_discount; }
        const pollOptions = key.value === 'polls' ? payload.options || [] : [];
        if (key.value === 'polls' && pollOptions.length < 2) throw new Error('A poll must have at least 2 options.');
        if (editing.value && key.value === 'polls') delete payload.options;
        if (key.value === 'subscriptions' && !editing.value) {
            const planIds = Array.isArray(model.plan_id) ? model.plan_id : [model.plan_id].filter(Boolean);
            if (!planIds.length) throw new Error('Choose at least one plan.');
            for (const planId of planIds) {
                await api(resource.value.createEndpoint, { method: 'POST', body: { ...payload, plan_id: planId, transaction_id: planIds.length > 1 && payload.transaction_id ? `${payload.transaction_id}-${planId}` : payload.transaction_id } });
            }
            notice.value = `${planIds.length} subscription${planIds.length === 1 ? '' : 's'} created with payment history.`;
            router.push(`/manage/${key.value}`); return;
        }
        const endpoint = editing.value ? `${resource.value.endpoint}/${route.params.id}` : (resource.value.createEndpoint || resource.value.endpoint);
        let requestBody = payload; let method = editing.value ? 'PUT' : 'POST';
        if (Object.keys(files).length) {
            requestBody = new FormData();
            Object.entries(payload).forEach(([name, value]) => requestBody.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : typeof value === 'object' ? JSON.stringify(value) : value == null ? '' : String(value)));
            Object.entries(files).forEach(([name, file]) => requestBody.set(name, file));
            if (editing.value) { requestBody.set('_method', 'PUT'); method = 'POST'; }
        }
        const response = await api(endpoint, { method, body: requestBody });
        const saved = valueData(response); const id = route.params.id || saved.id || saved.num;
        if (key.value === 'seasons' && editing.value && payload.isPayPerView) {
            for (const episode of seasonEpisodes.value) await api(`/admin/catalog/episodes/${episode.id}`, { method: 'PUT', body: { isPayPerView: episode.isPayPerView, amount: episode.isPayPerView ? Number(episode.amount || 0) : 0 } });
        }
        if (key.value === 'plans') await syncPlanFeatures(id, planFeatures, discount);
        if (key.value === 'polls' && editing.value) await syncPollOptions(id, pollOptions);
        notice.value = `${resource.value.singular} saved successfully.`;
        router.push(`/manage/${key.value}`);
    } catch (reason) { error.value = reason.message; }
    finally { saving.value = false; }
}
watch(() => [route.params.resource, route.params.id], load);
watch([episodeIncrease, () => model.amount], () => {
    if (episodePpvOpen.value) splitSeasonAmount();
});
onMounted(load);
</script>

<template>
    <div v-if="resource" class="admin-page narrow">
        <PageHeader :eyebrow="editing ? 'Edit record' : 'New record'" :title="`${editing ? 'Update' : 'Add'} ${resource.singular}`" description="Admin3-compatible workflow with validated relationships and nested records." :back="`/manage/${key}`" />
        <StatusPanel tone="success" :message="notice" /><StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-loading">Loading record…</div>
        <form v-else class="admin-editor admin-panel" @submit.prevent="save">
            <section v-if="relationContext" class="admin-current-record-section">
                <article class="admin-current-record">
                    <p>{{ relationContext.eyebrow }}</p>
                    <h2>{{ relationContext.title }}</h2>
                    <span>{{ relationContext.subtitle }}</span>
                    <div><b v-for="fact in relationContext.facts" :key="fact">{{ fact }}</b></div>
                </article>
            </section>
            <section v-if="['subscriptions','seasons','episodes'].includes(key)" class="admin-relation-picker">
                <label><span>{{ relationSearchLabel }}</span><input v-model="relationQuery" type="search" :placeholder="relationContext ? 'Search only if you want to change this link…' : 'Type at least 2 characters…'" @input="queueRelationSearch"></label>
                <small v-if="relationBusy">Searching…</small>
                <div v-if="relationResults.length" class="admin-relation-results"><button v-for="item in relationResults" :key="item.id || item.uid" type="button" @click="selectRelation(item)"><b>{{ item.label || item.name || item.uid }}</b><span>{{ item.auth_phone || item.mail || item.id }}</span></button></div>
            </section>
            <div class="admin-form-grid">
                <label v-for="field in resource.fields.filter(item => !(key === 'legal' && item.name === 'sections'))" :key="field.name" :class="{wide:field.wide,check:field.type==='checkbox'}">
                    <template v-if="field.type==='checkbox'"><input v-model="model[field.name]" type="checkbox"><span>{{field.label}}</span></template>
                    <template v-else><span>{{field.label}} <b v-if="field.required">*</b></span>
                        <textarea v-if="['textarea','lines','json'].includes(field.type)" v-model="model[field.name]" :required="field.required" :placeholder="field.type==='lines'?'First item\nSecond item':field.placeholder" />
                        <div v-else-if="field.relation === 'plan' && key === 'subscriptions' && !editing" class="admin-plan-picker">
                            <section v-for="[deviceType, devicePlans] in plansByDevice" :key="deviceType">
                                <header>{{ deviceType }}</header>
                                <div v-for="plan in devicePlans" :key="plan.id" class="admin-plan-choice" :class="{selected:isSubscriptionPlanSelected(plan.id)}" @click="toggleSubscriptionPlan(plan.id)">
                                    <input :checked="isSubscriptionPlanSelected(plan.id)" type="checkbox" @click.stop @change.stop="toggleSubscriptionPlan(plan.id)">
                                    <span><b>{{ plan.name }}</b><small>₹{{ plan.price }} · {{ plan.duration_days }} days · {{ plan.quality }}</small></span>
                                </div>
                            </section>
                            <small>Select one or more plans. Start/end dates can be left blank to use each plan duration.</small>
                        </div>
                        <select v-else-if="field.relation === 'plan'" v-model="model[field.name]" :required="field.required"><option value="">Choose a plan…</option><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }} · {{ plan.device_type }} · ₹{{ plan.price }}</option></select>
                        <select v-else-if="field.type==='select'" v-model="model[field.name]" :required="field.required"><option value="">Choose…</option><option v-for="option in field.options" :key="option" :value="option">{{option}}</option></select>
                        <template v-else-if="field.upload"><input v-model="model[field.name]" :type="field.type" :required="field.required && !files[field.name]" placeholder="https://…"><input type="file" accept="image/*" @change="files[field.name] = $event.target.files?.[0]"><small v-if="files[field.name]">Selected: {{files[field.name].name}}</small></template>
                        <input v-else v-model="model[field.name]" :type="field.type" :required="field.required" :placeholder="field.placeholder" :step="field.type==='number'?'any':undefined" :min="field.min" :max="field.max">
                    </template>
                </label>
            </div>
            <section v-if="key === 'legal'" class="admin-nested-editor">
                <header><div><p>PAGE CONTENT</p><h2>Sections</h2></div><button type="button" class="admin-secondary" @click="legalSections.push({ heading: '', body: '' })">Add section</button></header>
                <article v-for="(section,index) in legalSections" :key="index"><label>Section heading<input v-model="section.heading" required></label><label>Section content<textarea v-model="section.body" required /></label><button v-if="legalSections.length > 1" type="button" class="admin-danger" @click="legalSections.splice(index,1)">Remove section</button></article>
            </section>
            <section v-if="key === 'seasons' && editing && seasonEpisodes.length" class="admin-season-ppv-panel" :class="{disabled:!model.isPayPerView}">
                <div><p>EPISODE PPV</p><h2>{{ model.isPayPerView ? 'Episode pricing' : 'Episode pricing disabled' }}</h2><span>{{ episodePpvSelectedCount }} selected · Season amount {{ Number(model.amount || 0).toLocaleString() }} · Episode total {{ episodePpvTotal.toLocaleString() }}</span></div>
                <button type="button" class="admin-secondary" :disabled="!model.isPayPerView" @click="openEpisodePpv">View episodes</button>
            </section>
            <footer><RouterLink :to="`/manage/${key}`" class="admin-secondary">Cancel</RouterLink><button class="admin-primary" :disabled="saving">{{saving?'Saving…':`${editing?'Save changes':'Create'} ${resource.singular}`}}</button></footer>
        </form>
        <Teleport to="body">
            <div v-if="episodePpvOpen" class="admin-episode-ppv-backdrop" @click.self="episodePpvOpen = false">
                <section class="admin-episode-ppv-dialog">
                    <header>
                        <div><p>EPISODE PPV</p><h2>Manage episode prices</h2><span>Season amount {{ Number(model.amount || 0).toLocaleString() }} · {{ episodePpvSelectedCount }} of {{ seasonEpisodes.length }} episodes selected</span></div>
                        <button type="button" class="admin-icon-button" @click="episodePpvOpen = false">×</button>
                    </header>
                    <div class="admin-episode-ppv-tools">
                        <label><span>Increase after split (%)</span><input v-model.number="episodeIncrease" type="number" step="any" min="0"></label>
                        <button type="button" class="admin-primary" :disabled="saving" @click="saveEpisodePpv">{{ saving ? 'Saving…' : 'Save PPV settings' }}</button>
                    </div>
                    <div class="admin-episode-ppv-grid">
                        <article v-for="episode in seasonEpisodes" :key="episode.id" class="admin-episode-ppv-card" :class="{selected:episode.isPayPerView}">
                            <label class="admin-modern-check"><input v-model="episode.isPayPerView" type="checkbox" @change="splitSeasonAmount"><span><b>{{ episode.title }}</b><small>{{ episode.isPayPerView ? 'Pay per view enabled' : 'Included with season' }}</small></span></label>
                            <label class="admin-amount-field"><span>PPV amount</span><input v-model.number="episode.amount" type="number" step="any" min="0" :disabled="!episode.isPayPerView"></label>
                        </article>
                    </div>
                    <footer><span>{{ error || 'Percentage and episode selection recalculate prices automatically.' }}</span><button type="button" class="admin-secondary" :disabled="saving" @click="episodePpvOpen = false">Close</button></footer>
                </section>
            </div>
        </Teleport>
    </div>
</template>
