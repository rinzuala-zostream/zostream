<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import ResourceEditorPage from '../pages/ResourceEditorPage.vue';
import AdminIcon from './AdminIcon.vue';
const props = defineProps({ movie: { type: Object, required: true }, links: { type: Object, default: null } });
const emit = defineEmits(['saved', 'cancel']);
const body = ref(null); const dialog = ref(null); const cancelButton = ref(null); const busy = ref(false); const loading = ref(true);
const ready = ref(false);
const formId = useId(); const previousFocus = document.activeElement;
async function showError() {
    await nextTick();
    if (!body.value) return;
    body.value.scrollTop = 0;
    const message = body.value.querySelector('.admin-status.is-error');
    if (message) { message.setAttribute('tabindex', '-1'); message.focus(); }
    else body.value.focus();
}
function cancel() { if (!busy.value) emit('cancel'); }
onMounted(() => { dialog.value.showModal(); cancelButton.value?.focus(); });
onBeforeUnmount(() => { dialog.value?.close(); if (previousFocus?.isConnected) previousFocus.focus(); });
</script>

<template>
    <dialog ref="dialog" class="movie-edit-dialog" :aria-labelledby="`${formId}-title`" @cancel.prevent="cancel">
        <header><div><p>Movie editor</p><h2 :id="`${formId}-title`">Edit {{ movie.title || movie.id }}</h2></div><button type="button" :disabled="busy" aria-label="Close movie editor" @click="cancel"><AdminIcon name="x" /></button></header>
        <div ref="body" tabindex="-1" class="movie-editor-body"><ResourceEditorPage embedded resource-key="movies" :initial-record="movie" :initial-links="links" :record-id="movie.id" :form-id="formId" @saved="emit('saved', $event)" @saving="busy = $event" @loading="loading = $event" @ready="ready = $event" @error="showError" /></div>
        <footer><button ref="cancelButton" type="button" class="admin-secondary compact" :disabled="busy" @click="cancel">Cancel</button><button type="submit" :form="formId" class="admin-primary compact" :disabled="busy || loading || !ready">{{ busy ? 'Saving…' : 'Save changes' }}</button></footer>
    </dialog>
</template>

<style scoped>
.movie-edit-dialog{display:none;flex-direction:column;width:min(1050px,calc(100vw - 28px));max-height:calc(100svh - 32px);padding:0;margin:auto;border:1px solid var(--a-line);border-radius:14px;background:var(--a-panel);color:var(--a-text);box-shadow:0 20px 70px #0005;font:inherit;overflow:hidden}.movie-edit-dialog[open]{display:flex}.movie-edit-dialog::backdrop{background:#0009}.movie-edit-dialog>header{display:flex;align-items:start;gap:16px;flex:none;padding:22px 24px;border-bottom:1px solid var(--a-line)}.movie-edit-dialog header>div{flex:1;min-width:0}.movie-edit-dialog header p{margin:0 0 6px;font-size:11px;color:var(--a-muted);text-transform:uppercase;letter-spacing:1px}.movie-edit-dialog h2{margin:0;font-size:22px;overflow-wrap:anywhere}.movie-edit-dialog header>button{display:grid;place-items:center;width:34px;height:34px;flex:none;border:1px solid var(--a-line);border-radius:8px;background:var(--a-soft);color:var(--a-text)}.movie-edit-dialog header svg{width:18px;height:18px}.movie-editor-body{overflow-y:auto;overscroll-behavior:contain;min-height:0;padding:22px 24px}.movie-editor-body :deep(.admin-page){padding:0!important;margin:0;max-width:none}.movie-editor-body :deep(.admin-editor){padding:0;border:0;border-radius:0;box-shadow:none;background:transparent}.movie-edit-dialog>footer{display:flex;justify-content:flex-end;gap:10px;flex:none;padding:18px 24px;border-top:1px solid var(--a-line)}.movie-edit-dialog button:focus-visible{outline:2px solid var(--a-cyan);outline-offset:3px}@media(max-width:600px){.movie-edit-dialog>header,.movie-editor-body{padding:18px}.movie-edit-dialog>footer{padding:16px}}
</style>
