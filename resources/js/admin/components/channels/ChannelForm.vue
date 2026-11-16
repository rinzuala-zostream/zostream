<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { api, queryString } from '../../lib/api';
import { formValues, formPayload } from '../../lib/channels';

const props = defineProps({ fields: Array, record: Object, busy: Boolean, title: String, submitLabel: { type: String, default: 'Save' } });
const emit = defineEmits(['save', 'cancel']);
const values = ref({});
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
    <form class="admin-panel admin-editor channel-form" @submit.prevent="emit('save', formPayload(fields, values))">
        <h2 v-if="title">{{ title }}</h2>
        <fieldset :disabled="busy">
            <div class="admin-form-grid">
                <label v-for="field in fields" :key="field.name" :class="{ wide: ['textarea', 'user'].includes(field.type), check: field.type === 'checkbox' }">
                    <span>{{ field.label }} <b v-if="field.required">*</b></span>
                    <input v-if="field.type === 'checkbox'" v-model="values[field.name]" type="checkbox">
                    <textarea v-else-if="field.type === 'textarea'" v-model="values[field.name]" :required="field.required" />
                    <select v-else-if="field.type === 'select'" v-model="values[field.name]" :required="field.required">
                        <option v-for="option in field.options" :key="option" :value="option">{{ option.replaceAll('_', ' ') }}</option>
                    </select>
                    <input v-else v-model="values[field.name]" :type="field.type === 'user' ? 'text' : field.type" :required="field.required" :min="field.min" :max="field.type === 'number' ? field.max : undefined" :maxlength="field.type !== 'number' ? field.max : undefined" :step="field.step || (field.type === 'datetime-local' ? '1' : undefined)">
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
        <footer><button type="button" class="admin-secondary" :disabled="busy" @click="emit('cancel')">Cancel</button><button type="submit" class="admin-primary" :disabled="busy">{{ busy ? 'Saving…' : submitLabel }}</button></footer>
    </form>
</template>
<style scoped>
fieldset{min-width:0;margin:0;padding:0;border:0}h2{margin:0 0 20px;font-size:16px}.channel-owner-results{display:grid;gap:5px}.channel-owner-results button{padding:10px;text-align:left;color:var(--a-text);background:var(--a-panel);border:1px solid var(--a-line);border-radius:8px}small{color:var(--a-muted)}
</style>
