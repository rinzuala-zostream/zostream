<script setup>
import { onBeforeUnmount, ref } from 'vue';
import CreatorIcon from './CreatorIcon.vue';
import { uploadChannelFile } from '../../admin/lib/channelUploads';
import { formatBytes } from '../../admin/lib/channels';
const props = defineProps({ channelId: [String, Number], contentId: [String, Number], api: Function, kind: { type: String, default: 'artwork' }, disabled: Boolean, label: String });
const emit = defineEmits(['busy', 'uploaded']);
const picker = ref(null); const file = ref(null); const busy = ref(false); const progress = ref(0); const error = ref(''); const done = ref(''); const dragging = ref(false);
let session = null; let cancelled = false; let mounted = true;
const accept = props.kind === 'source' ? '.mp4' : '.jpg,.jpeg,.png,.webp';
async function select(files) {
    if (busy.value || props.disabled) return;
    error.value = ''; done.value = '';
    if (files?.length !== 1) { error.value = 'Choose one file at a time.'; return; }
    const chosen = files[0];
    if (!accept.split(',').includes('.' + chosen.name.split('.').pop().toLowerCase()) || chosen.size <= 0) { error.value = 'Choose a supported, non-empty file.'; return; }
    if (props.kind === 'artwork' && chosen.size > 10 * 1024 * 1024) { error.value = 'Choose an image smaller than 10 MB.'; return; }
    if (session) {
        busy.value = true; emit('busy', true);
        try { await props.api(`/creator/channels/${props.channelId}/uploads/${session.id}`, { method: 'DELETE' }); }
        catch { /* Unfinished sessions expire automatically. */ }
        finally { busy.value = false; emit('busy', false); }
    }
    if (!mounted) return;
    session = null; file.value = chosen; progress.value = 0;
}
async function upload() {
    if (!file.value || busy.value || props.disabled) return;
    busy.value = true; cancelled = false; error.value = ''; done.value = ''; emit('busy', true);
    let completed = null;
    try {
        completed = await uploadChannelFile({ api: props.api, channelId: props.channelId, contentId: props.contentId, audience: 'creator', kind: props.kind, file: file.value, previous: session, progress: value => progress.value = value, isCancelled: () => cancelled });
        session = null;
        if (completed && mounted) { file.value = null; if (picker.value) picker.value.value = ''; done.value = props.kind === 'source' ? 'Source uploaded for staff review.' : 'Image uploaded. Save your changes to apply it.'; }
    } catch (reason) { session = reason.uploadSession || session; if (mounted) error.value = reason.message; }
    finally { if (mounted) { busy.value = false; emit('busy', false); } }
    if (completed && mounted) emit('uploaded', completed);
}
onBeforeUnmount(() => { mounted = false; cancelled = true; });
</script>
<template>
    <div class="cs-upload" :class="{ 'is-dragging': dragging, 'is-busy': busy }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; select($event.dataTransfer.files)">
        <div class="cs-upload-symbol"><CreatorIcon :name="kind === 'source' ? 'upload' : 'image'" /></div>
        <strong>{{ label || (kind === 'source' ? 'Drop your MP4 here' : 'Choose your artwork') }}</strong>
        <p>{{ kind === 'source' ? 'Your original video stays private for Zo Stream staff review.' : 'JPG, PNG or WebP · up to 10 MB' }}</p>
        <input ref="picker" type="file" :accept="accept" :disabled="disabled || busy" :aria-label="label || 'Choose file'" @change="select($event.target.files)">
        <div v-if="file" class="cs-file-selection"><span>{{ file.name }} <small>{{ formatBytes(file.size) }}</small></span><button type="button" class="cs-button cs-button-primary" :disabled="disabled || busy" @click="upload">{{ session ? 'Retry upload' : 'Upload selected file' }}</button></div>
        <div v-if="busy" class="cs-upload-progress" role="status"><progress :value="progress" max="100" aria-label="Upload progress" /><span>{{ progress }}% · {{ progress >= 95 ? 'Checking your file…' : 'Uploading…' }}</span><button type="button" class="cs-button cs-button-quiet" @click="cancelled = true">Cancel upload</button></div>
        <p v-if="error" class="cs-inline-error" role="alert">{{ error }}</p><p v-if="done" class="cs-inline-success" role="status">{{ done }}</p>
    </div>
</template>
