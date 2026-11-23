<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId } from 'vue';
import AdminIcon from './AdminIcon.vue';
import StatusPanel from './StatusPanel.vue';
import { api } from '../lib/api';
import { notify } from '../lib/feedback';
import { userEditSections, userFieldValue, userChanges } from '../lib/userFields';

const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['saved', 'cancel']);
const id = useId(); const errorPanel = ref(null); const dialog = ref(null); const cancelButton = ref(null);
const draft = reactive({}); const original = {};
for (const field of userEditSections.flatMap(section => section.fields)) {
    original[field.name] = userFieldValue(field, props.user[field.name]);
    draft[field.name] = original[field.name];
}
const saving = ref(false); const error = ref(''); const fieldErrors = ref({});
const districts = ref([]); const locationError = ref(''); const locationLoading = ref(false);
const selectedDistrict = computed(() => districts.value.find(district => String(district.lgd_code) === draft.profile_district_code));
const villages = computed(() => selectedDistrict.value?.villages || []);
const changes = computed(() => userChanges(draft, original));
const dirty = computed(() => Object.keys(changes.value).length > 0);
const previousFocus = document.activeElement;
let active = true;
function cancel() { if (!saving.value) emit('cancel'); }
function changeDistrict() { draft.profile_village_code = ''; }
async function loadLocations() {
    locationLoading.value = true; locationError.value = '';
    try {
        const response = await api('/admin/users/profile-locations');
        if (active) districts.value = (response?.data ?? response)?.districts || [];
    } catch (reason) { if (active) locationError.value = reason.message; }
    finally { if (active) locationLoading.value = false; }
}
async function save() {
    if (saving.value || !dirty.value) return;
    saving.value = true; error.value = ''; fieldErrors.value = {};
    try {
        const response = await api(`/admin/users/${encodeURIComponent(props.user.uid)}`, { method: 'PATCH', body: changes.value });
        notify('User saved successfully.', 'success');
        if (active) emit('saved', response?.data ?? response);
    } catch (reason) {
        if (active) {
            error.value = reason.message;
            const details = reason.details || {};
            fieldErrors.value = Object.fromEntries(Object.entries(details).map(([name, value]) => [name, Array.isArray(value) ? value[0] : value]));
            await nextTick();
            const invalid = dialog.value?.querySelector('[aria-invalid="true"]');
            if (invalid) invalid.focus();
            else { errorPanel.value?.scrollIntoView?.({ block: 'start' }); errorPanel.value?.focus(); }
        }
    } finally { if (active) saving.value = false; }
}
onMounted(async () => { dialog.value.showModal(); cancelButton.value?.focus(); await loadLocations(); });
onBeforeUnmount(() => { active = false; dialog.value?.close(); if (previousFocus?.isConnected) previousFocus.focus(); });
</script>

<template>
    <dialog ref="dialog" class="user-edit-dialog" :aria-labelledby="`${id}-title`" @cancel.prevent="cancel">
        <form autocomplete="off" :aria-busy="saving" @submit.prevent="save">
            <header class="user-edit-header"><div><p>User account</p><h2 :id="`${id}-title`">Edit {{ user.name || user.username || 'user' }}</h2><span>{{ user.uid }}</span></div><button type="button" :disabled="saving" aria-label="Close user editor" @click="cancel"><AdminIcon name="x" /></button></header>
            <div class="user-edit-body">
                <div ref="errorPanel" tabindex="-1"><StatusPanel tone="error" :message="error" /></div>
                <fieldset :disabled="saving">
                    <section v-for="section in userEditSections" :key="section.title" class="user-edit-section"><h3>{{ section.title }}</h3><p v-if="section.title === 'Identity'">These identifiers keep the account linked to its subscriptions, devices and history.</p><template v-if="section.title === 'Location'"><StatusPanel tone="warning" :message="locationError" /><button v-if="locationError" type="button" class="admin-secondary compact" :disabled="locationLoading" @click="loadLocations">Retry locations</button><p v-if="locationLoading" role="status">Loading district and village options…</p></template><div class="user-edit-grid">
                        <label v-for="field in section.fields" :key="field.name" :class="{ wide: field.wide, check: field.type === 'checkbox' }">
                            <template v-if="field.type === 'checkbox'"><input v-model="draft[field.name]" type="checkbox"><span>{{ field.label }}</span></template>
                            <template v-else>
                                <span>{{ field.label }}</span>
                                <select v-if="field.type === 'district'" v-model="draft[field.name]" :disabled="locationLoading || !districts.length" :aria-invalid="Boolean(fieldErrors[field.name])" :aria-describedby="fieldErrors[field.name] ? `${id}-${field.name}-error` : undefined" @change="changeDistrict"><option value="">Not set</option><option v-if="draft.profile_district_code && !selectedDistrict" :value="draft.profile_district_code">{{ draft.profile_district_code }}</option><option v-for="district in districts" :key="district.lgd_code" :value="String(district.lgd_code)">{{ district.name }}</option></select>
                                <select v-else-if="field.type === 'village'" v-model="draft[field.name]" :disabled="locationLoading || !districts.length" :aria-invalid="Boolean(fieldErrors[field.name])" :aria-describedby="fieldErrors[field.name] ? `${id}-${field.name}-error` : undefined"><option value="">Not set</option><option v-if="draft.profile_village_code && !villages.some(village => String(village.lgd_code) === draft.profile_village_code)" :value="draft.profile_village_code">{{ draft.profile_village_code }}</option><option v-for="village in villages" :key="village.lgd_code" :value="String(village.lgd_code)">{{ village.name }}</option></select>
                                <select v-else-if="field.type === 'select'" v-model="draft[field.name]" :aria-invalid="Boolean(fieldErrors[field.name])" :aria-describedby="fieldErrors[field.name] ? `${id}-${field.name}-error` : undefined"><option value="">Not set</option><option v-for="[value, label] in field.options" :key="value" :value="value">{{ label }}</option></select>
                                <textarea v-else-if="field.type === 'textarea'" v-model="draft[field.name]" :maxlength="field.max" :aria-invalid="Boolean(fieldErrors[field.name])" :aria-describedby="fieldErrors[field.name] ? `${id}-${field.name}-error` : undefined" rows="3" />
                                <input v-else v-model="draft[field.name]" :type="field.type" :readonly="field.readonly" :maxlength="field.max" :step="field.type === 'datetime-local' ? 1 : undefined" :aria-invalid="Boolean(fieldErrors[field.name])" :aria-describedby="fieldErrors[field.name] ? `${id}-${field.name}-error` : undefined">
                            </template>
                            <small v-if="fieldErrors[field.name]" :id="`${id}-${field.name}-error`" class="user-field-error">{{ fieldErrors[field.name] }}</small>
                        </label>
                    </div></section>
                </fieldset>
            </div>
            <footer class="user-edit-footer"><span>{{ dirty ? 'Unsaved changes' : 'No changes' }}</span><button ref="cancelButton" type="button" class="admin-secondary compact" :disabled="saving" @click="cancel">Cancel</button><button type="submit" class="admin-primary compact" :disabled="saving || !dirty">{{ saving ? 'Saving…' : 'Save changes' }}</button></footer>
        </form>
    </dialog>
</template>

<style scoped>
.user-edit-dialog{width:min(900px,calc(100vw - 28px));max-height:calc(100svh - 32px);margin:auto;padding:0;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel);color:var(--a-text);box-shadow:0 20px 70px #0005;font:inherit;overflow:hidden}.user-edit-dialog::backdrop{background:#0009}.user-edit-dialog>form{display:flex;flex-direction:column;max-height:calc(100svh - 34px)}.user-edit-header{display:flex;align-items:start;gap:16px;padding:22px 24px;border-bottom:1px solid var(--a-line)}.user-edit-header>div{flex:1;min-width:0}.user-edit-header p{margin:0 0 6px;color:var(--a-muted);font-size:11px;text-transform:uppercase;letter-spacing:1px}.user-edit-header h2{margin:0;font-size:22px;overflow-wrap:anywhere}.user-edit-header span{display:block;margin-top:7px;font-size:12px;color:var(--a-muted);overflow-wrap:anywhere}.user-edit-header>button{display:grid;place-items:center;width:34px;height:34px;flex:none;border:1px solid var(--a-line);border-radius:8px;background:var(--a-soft);color:var(--a-text)}.user-edit-header svg{width:18px;height:18px}.user-edit-body{overflow-y:auto;overscroll-behavior:contain;padding:24px;min-height:0}.user-edit-body fieldset{min-width:0;border:0;padding:0;margin:0}.user-edit-section+section{margin-top:26px;padding-top:24px;border-top:1px solid var(--a-line)}.user-edit-section h3{margin:0 0 16px;font-size:16px}.user-edit-section>p{margin:-5px 0 16px;color:var(--a-muted);font-size:12px;line-height:1.6}.user-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.user-edit-grid label{display:grid;gap:8px;min-width:0;font-size:12px;font-weight:600}.user-edit-grid .wide{grid-column:1/-1}.user-edit-grid .check{display:flex;align-items:center;gap:10px;min-height:42px}.user-edit-grid input:not([type=checkbox]),.user-edit-grid select,.user-edit-grid textarea{width:100%;min-width:0;min-height:42px;padding:10px 12px;border:1px solid var(--a-line);border-radius:8px;background:var(--surface-field);color:var(--a-text);font:inherit;font-size:13px}.user-edit-grid input[readonly]{color:var(--a-muted);background:var(--a-soft)}.user-edit-grid input[type=checkbox]{width:18px;height:18px;accent-color:var(--a-cyan)}.user-edit-grid textarea{resize:vertical}.user-edit-grid [aria-invalid=true]{border-color:var(--a-red)}.user-field-error{font-size:12px;color:var(--a-red);font-weight:400}.user-edit-footer{display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:18px 24px;border-top:1px solid var(--a-line)}.user-edit-footer>span{margin-right:auto;font-size:12px;color:var(--a-muted)}.user-edit-dialog :is(button,input,select,textarea):focus-visible{outline:2px solid var(--a-cyan);outline-offset:3px}
@media(max-width:600px){.user-edit-header,.user-edit-body{padding:18px}.user-edit-grid{grid-template-columns:1fr}.user-edit-footer{padding:16px;flex-wrap:wrap}.user-edit-footer>span{flex-basis:100%}}
</style>
