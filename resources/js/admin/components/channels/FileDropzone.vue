<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { uploadChannelFile } from '../../lib/channelUploads';
const props = defineProps({ api: Function, channelId: [String, Number], contentId: [String, Number], audience: { type: String, default: 'admin' }, kind: { type: String, default: 'artwork' }, disabled: Boolean, label: { type: String, default: 'Upload file' } });
const emit = defineEmits(['uploaded', 'busy']);
const input = ref(null); const drag = ref(false); const busy = ref(false); const percent = ref(0); const file = ref(null); const error = ref(''); const done = ref('');
let cancelled = false; let session = null; let mounted = true;
const accept = props.kind === 'artwork' ? '.jpg,.jpeg,.png,.webp' : props.audience === 'creator' ? '.mp4' : '.mp4,.mp3,.m4a,.wav,.vtt,.srt';
async function select(files) {
    if (busy.value || props.disabled) return;
    if (files?.length !== 1) { error.value = 'Choose one file at a time.'; return; }
    const next = files[0];
    const extension = '.' + next.name.split('.').pop().toLowerCase();
    if (!accept.split(',').includes(extension) || next.size <= 0) { error.value = 'Choose a supported, non-empty file.'; return; }
    if (session) {
        busy.value = true; emit('busy', true);
        try { await props.api(`/${props.audience}/channels/${props.channelId}/uploads/${session.id}`, { method: 'DELETE' }); } catch {}
        finally { busy.value = false; emit('busy', false); }
    }
    file.value = next; session = null; error.value = ''; done.value = ''; percent.value = 0;
}
async function upload() {
    if (!file.value || busy.value || props.disabled) return;
    busy.value = true; cancelled = false; error.value = ''; done.value = ''; emit('busy', true);
    try {
        const result = await uploadChannelFile({ api: props.api, channelId: props.channelId, contentId: props.contentId, audience: props.audience, kind: props.kind, file: file.value, previous: session, progress: value => percent.value = value, isCancelled: () => cancelled });
        session = null;
        if (result && mounted) { busy.value = false; emit('busy', false); done.value = props.kind === 'source' ? 'Source uploaded for staff review.' : 'Image uploaded. Save the form to apply it.'; file.value = null; if (input.value) input.value.value = ''; emit('uploaded', result); }
    } catch (reason) { session = reason.uploadSession || session; if (mounted) error.value = reason.message; }
    finally { if (mounted) { busy.value = false; emit('busy', false); } }
}
async function discard() {
    if (busy.value) return;
    busy.value = true; emit('busy', true);
    try {
        if (session) await props.api(`/${props.audience}/channels/${props.channelId}/uploads/${session.id}`, { method: 'DELETE' });
    } catch { /* Expired sessions are also removed by scheduled cleanup. */ }
    finally {
        session = null; file.value = null; error.value = ''; percent.value = 0;
        if (input.value) input.value.value = '';
        busy.value = false; emit('busy', false);
    }
}
onBeforeUnmount(() => { mounted = false; cancelled = true; });
</script>
<template>
    <div class="channel-dropzone" :class="{ dragging: drag }" @dragover.prevent="drag = true" @dragleave.prevent="drag = false" @drop.prevent="drag = false; select($event.dataTransfer.files)">
        <span>{{ label }}</span><small>Drag a file here or choose from your device · {{ accept }}</small>
        <input ref="input" type="file" :accept="accept" :disabled="disabled || busy" :aria-label="label" @change="select($event.target.files)">
        <template v-if="file"><small>{{ file.name }} · {{ (file.size / 1024 / 1024).toFixed(1) }} MB</small><button class="admin-secondary" type="button" :disabled="disabled || busy" @click="upload">{{ session ? 'Retry upload' : 'Upload selected file' }}</button><button v-if="!busy" class="admin-secondary" type="button" :disabled="disabled" @click="discard">Discard selection</button></template>
        <template v-if="busy"><progress :value="percent" max="100" :aria-label="`${label} progress`" /><small>{{ percent }}% · {{ percent >= 95 ? 'Validating file…' : 'Uploading…' }}</small><button class="admin-secondary" type="button" @click="cancelled = true">Cancel upload</button></template>
        <small v-if="error" role="alert">{{ error }}</small><small v-if="done" role="status">{{ done }}</small>
    </div>
</template>
<style scoped>
.channel-dropzone{display:grid;gap:10px;padding:16px;margin:10px 0;border:1px dashed var(--a-muted);border-radius:10px;background:var(--a-panel);color:var(--a-text)}.channel-dropzone.dragging{border-color:var(--a-cyan);background:rgba(53,213,208,.08)}.channel-dropzone small{color:var(--a-muted);font-size:11px;overflow-wrap:anywhere}.channel-dropzone input{max-width:100%;font-size:12px}.channel-dropzone progress{width:100%;accent-color:var(--a-cyan)}.channel-dropzone button{justify-self:start}
</style>
