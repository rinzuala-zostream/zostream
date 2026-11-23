<script setup>
import StatusPanel from './StatusPanel.vue';
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import { api, queryString } from '../lib/api';

const props = defineProps({
    id: String,
    modelValue: [String, Number], required: Boolean, disabled: Boolean, readonly: Boolean,
    placeholder: { type: String, default: 'Username, name, phone, email or UID…' },
    label: { type: String, default: 'Find user' },
});
const listId = useId();
const emit = defineEmits(['update:modelValue', 'select']);
const text = ref(String(props.modelValue || ''));
const results = ref([]); const busy = ref(false); const error = ref(''); const searched = ref(false);
const chosen = ref(null); const open = ref(false); const active = ref(-1);
let timer; let version = 0; let requestController;
function reset() { clearTimeout(timer); version++; requestController?.abort(); requestController = null; results.value = []; busy.value = false; error.value = ''; searched.value = false; active.value = -1; }
watch(() => props.modelValue, value => {
    if (String(value || '') !== text.value) { reset(); text.value = String(value || ''); chosen.value = null; }
});
function search() {
    reset(); chosen.value = null; open.value = true;
    emit('update:modelValue', text.value);
    const term = text.value.trim();
    if (term.length < 2 || props.disabled || props.readonly) return;
    const current = version; busy.value = true;
    timer = setTimeout(async () => {
        const controller = new AbortController(); requestController = controller;
        try {
            const response = await api(`/admin/users-search${queryString({ q: term, per_page: 12, lookup: 1 })}`, { signal: controller.signal });
            if (current !== version) return;
            const data = response?.data ?? response;
            results.value = (Array.isArray(data) ? data : data?.data || []).filter(user => user.uid);
            searched.value = true;
        } catch (reason) { if (current === version && reason.name !== 'AbortError') error.value = reason.message; }
        finally { if (current === version) busy.value = false; }
    }, 300);
}
function choose(user) {
    reset(); text.value = String(user.uid); chosen.value = user; open.value = false;
    emit('update:modelValue', user.uid); emit('select', user);
}
function move(direction) {
    open.value = true;
    if (results.value.length) active.value = active.value < 0
        ? (direction > 0 ? 0 : results.value.length - 1)
        : (active.value + direction + results.value.length) % results.value.length;
}
function enter() { if (open.value && active.value >= 0 && results.value[active.value]) choose(results.value[active.value]); }
function blur(event) { if (!event.currentTarget.contains(event.relatedTarget)) open.value = false; }
onBeforeUnmount(reset);
</script>

<template>
    <span class="user-lookup" @focusout="blur">
        <input :id="id" v-model="text" type="text" role="combobox" :aria-controls="listId" :aria-activedescendant="open && active >= 0 ? `${listId}-${active}` : undefined" :aria-label="label" :placeholder="placeholder" :required="required" :disabled="disabled" :readonly="readonly" autocomplete="off" :aria-expanded="open && Boolean(results.length)" aria-haspopup="listbox" @input="search" @focus="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="enter" @keydown.esc.stop.prevent="open = false">
        <span v-if="open && (busy || (searched && !results.length))" class="lookup-status" role="status">{{ busy ? 'Searching users…' : 'No users found. Try a username, name, phone, email or UID.' }}</span>
        <StatusPanel v-if="open" tone="error" :message="error" />
        <span v-if="open && results.length" :id="listId" class="lookup-results" role="listbox" aria-label="Matching users">
            <button v-for="(user, index) in results" :key="user.uid" :id="`${listId}-${index}`" type="button" role="option" :aria-selected="index === active" :class="{ active: index === active }" :disabled="disabled" @mousedown.prevent @click="choose(user)">
                <strong>{{ user.name || user.username || 'Unnamed user' }}<b v-if="user.username">@{{ user.username }}</b></strong>
                <small>{{ [user.auth_phone || user.call, user.mail, user.uid].filter(Boolean).join(' · ') }}</small>
            </button>
        </span>
        <span v-if="chosen" class="lookup-selected">{{ chosen.name || 'Selected user' }}<b v-if="chosen.username"> · @{{ chosen.username }}</b></span>
    </span>
</template>

<style scoped>
.user-lookup{display:block;min-width:0;width:100%;text-transform:none;letter-spacing:normal;font-weight:400}
.user-lookup>input{display:block;width:100%;min-width:0;min-height:42px;margin:0;padding:10px 12px;border:1px solid var(--a-line);border-radius:9px;background:var(--surface-field,var(--a-panel));color:var(--a-text);font:inherit;font-size:13px}
.lookup-status,.lookup-selected{display:block;margin-top:7px;color:var(--a-muted);font-size:12px;line-height:1.5;overflow-wrap:anywhere}.lookup-selected b{font-weight:600;color:var(--a-cyan)}
.lookup-results{display:grid;max-height:260px;overflow-y:auto;margin-top:7px;padding:4px;border:1px solid var(--a-line);border-radius:9px;background:var(--surface-overlay,var(--a-panel))}
.lookup-results button{display:grid;gap:5px;width:100%;padding:10px;border:0;border-radius:6px;background:transparent;color:var(--a-text);text-align:left;white-space:normal;cursor:pointer}
.lookup-results button:hover,.lookup-results button.active{background:var(--surface-hover,var(--a-soft))}.lookup-results strong{font-size:13px;overflow-wrap:anywhere}.lookup-results strong b{display:inline-block;margin-left:8px;color:var(--a-cyan);font-size:12px;font-weight:600}.lookup-results small{color:var(--a-muted);font-size:11px;line-height:1.5;overflow-wrap:anywhere}
.user-lookup :is(input,button):focus-visible{outline:2px solid var(--a-cyan);outline-offset:2px}
</style>
