<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ChannelIdentity from './ChannelIdentity.vue';
import { api, queryString } from '../../lib/api';
import FileDropzone from './FileDropzone.vue';
import { formValues, formPayload } from '../../lib/channels';

const props = defineProps({ fields: Array, record: Object, busy: Boolean, title: String, submitLabel: { type: String, default: 'Save' }, channelId: [String, Number], contentId: [String, Number], uploadApi: { type: Function, default: api }, audience: { type: String, default: 'admin' } });
const emit = defineEmits(['save', 'cancel', 'busy']);
const values = ref({}); const uploading = ref(false);
const profile = computed(() => props.fields.some(field => field.name === 'logo'));
const hints = { user_id: 'Choose an existing Zo Stream account. Search below or paste its UID.', slug: 'Use lowercase letters, numbers and hyphens. New channels can leave this blank to generate it from the name.', description: 'Tell viewers what they can expect from this channel or video.', logo: 'Square artwork works best. JPG, PNG or WebP, up to 10 MB.', banner: 'Use a wide landscape image. JPG, PNG or WebP, up to 10 MB.', thumbnail: 'Use landscape artwork that clearly represents this video.', release_date: 'Your local time. Published content stays hidden until this date.', status: 'Only active channels and published, released content appear in the public catalog.', discount_percent: 'The final plan price is calculated from the price and discount.' };
watch(uploading, value => emit('busy', value));
function save() { if (!props.busy && !uploading.value) emit('save', formPayload(props.fields, values.value)); }
const search = ref(''); const results = ref([]); const searchError = ref(''); const searching = ref(false);
let generation = 0; let timer;
watch(() => [props.fields, props.record], () => { values.value = formValues(props.fields, props.record); }, { immediate: true });
watch(search, term => {
    clearTimeout(timer);
    const current = ++generation;
    results.value = []; searchError.value = ''; searching.value = false;
    if (term.trim().length < 2) return;
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            const response = await api(`/admin/users-search${queryString({ q: term.trim(), per_page: 10 })}`);
            const data = response?.status ? response.data : response;
            if (current === generation) results.value = Array.isArray(data) ? data : data?.data || [];
        } catch (reason) { if (current === generation) searchError.value = reason.message; }
        finally { if (current === generation) searching.value = false; }
    }, 300);
});
onBeforeUnmount(() => { clearTimeout(timer); generation++; });
function selectUser(user) { values.value.user_id = user.uid; search.value = ''; }
</script>

<template>
    <form class="admin-panel admin-editor channel-form" @submit.prevent="save">
        <div class="channel-form-heading"><h2 v-if="title">{{ title }}</h2><span>Fields marked * are required</span></div>
        <ChannelIdentity v-if="profile && values.name" :channel="{ ...record, ...values }" compact />
        <fieldset :disabled="busy">
            <div class="admin-form-grid">
                <label v-for="field in fields" :key="field.name" :class="{ wide: ['textarea', 'user'].includes(field.type), check: field.type === 'checkbox' }">
                    <span>{{ field.label }} <b v-if="field.required">*</b></span>
                    <input v-if="field.type === 'checkbox'" v-model="values[field.name]" type="checkbox">
                    <textarea v-else-if="field.type === 'textarea'" v-model="values[field.name]" :required="field.required" :maxlength="field.max" rows="4" />
                    <select v-else-if="field.type === 'select'" v-model="values[field.name]" :required="field.required">
                        <option v-for="option in field.options" :key="option" :value="option">{{ option.replaceAll('_', ' ') }}</option>
                    </select>
                    <input v-else v-model="values[field.name]" :type="field.type === 'user' ? 'text' : field.type" :required="field.required" :min="field.min" :max="field.type === 'number' ? field.max : undefined" :maxlength="field.type !== 'number' ? field.max : undefined" :step="field.step || (field.type === 'datetime-local' ? '1' : undefined)">
                    <small v-if="hints[field.name]" class="field-hint">{{ hints[field.name] }}</small>
                    <FileDropzone v-if="channelId && ['logo', 'banner', 'thumbnail'].includes(field.name)" :api="uploadApi" :channel-id="channelId" :content-id="contentId" :audience="audience" :disabled="busy || uploading" :label="`Upload ${field.label}`" @busy="uploading = $event" @uploaded="values[field.name] = $event.url" />
                    <template v-if="field.type === 'user'">
                        <input v-model="search" type="search" aria-label="Find channel owner" placeholder="Find owner by name, phone, email or UID" autocomplete="off">
                        <small v-if="searching">Searching users…</small>
                        <small v-if="searchError" role="alert">{{ searchError }}</small>
                        <div v-if="results.length" class="channel-owner-results">
                            <button v-for="user in results" :key="user.uid" type="button" @click="selectUser(user)">{{ user.name || 'Unnamed user' }} · {{ user.uid }}</button>
                        </div>
                    </template>
                </label>
            </div>
        </fieldset>
        <footer><small v-if="uploading" role="status">Finish the upload before saving.</small><button type="button" class="admin-secondary" :disabled="busy || uploading" @click="emit('cancel')">Cancel</button><button type="submit" class="admin-primary" :disabled="busy || uploading">{{ busy ? 'Saving…' : submitLabel }}</button></footer>
    </form>
</template>
<style scoped>
.channel-form-heading{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}.channel-form-heading h2{margin:0}.channel-form-heading>span{font-size:11px;color:var(--a-muted)}.field-hint{font-size:11px;line-height:1.6;font-weight:400;letter-spacing:normal;text-transform:none}.channel-form :deep(.channel-identity){max-width:620px}.channel-form footer{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;align-items:center;border-top:1px solid var(--a-line);padding-top:18px;margin-top:24px}.channel-form textarea{resize:vertical;min-height:110px}
fieldset{min-width:0;margin:0;padding:0;border:0}h2{margin:0 0 20px;font-size:16px}.channel-owner-results{display:grid;gap:5px}.channel-owner-results button{padding:10px;text-align:left;color:var(--a-text);background:var(--a-panel);border:1px solid var(--a-line);border-radius:8px}small{color:var(--a-muted)}
</style>
