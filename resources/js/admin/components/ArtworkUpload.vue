<script setup>
import StatusPanel from './StatusPanel.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    field: { type: Object, required: true },
    file: { type: Object, default: null },
});
const emit = defineEmits(['update:modelValue', 'file-change']);
const input = ref(null);
const localUrl = ref('');
const error = ref('');
const previewFailed = ref(false);
const preview = computed(() => localUrl.value || props.modelValue);
const titleImage = computed(() => props.field.name === 'title_img');

function releasePreview() {
    if (localUrl.value) URL.revokeObjectURL(localUrl.value);
    localUrl.value = '';
}
watch(() => props.file, file => {
    releasePreview();
    if (file) localUrl.value = URL.createObjectURL(file);
    else if (input.value) input.value.value = '';
}, { immediate: true });
watch(preview, () => { previewFailed.value = false; });
onBeforeUnmount(releasePreview);

function selectFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    error.value = '';
    if (!file.type.startsWith('image/') || file.size > 10 * 1024 * 1024) {
        error.value = 'Choose an image no larger than 10 MB.';
        event.target.value = '';
        return;
    }
    emit('file-change', file);
}
function cancelFile() {
    emit('file-change', null);
    error.value = '';
}
function updateUrl(event) {
    cancelFile();
    emit('update:modelValue', event.target.value);
}
</script>

<template>
    <div class="artwork-upload">
        <input :value="modelValue" type="url" :aria-label="field.label" :readonly="field.readOnly" :required="field.required && !file" placeholder="https://…" @input="updateUrl">
        <input ref="input" type="file" accept="image/*" :aria-label="`Upload ${field.label}`" @change="selectFile">
        <small>Upload up to 10 MB. Saved as WebP, up to 500 KB.<template v-if="titleImage"> Use a transparent PNG or WebP for the title logo; transparency is preserved.</template></small>
        <div v-if="file" class="artwork-selection">
            <small>{{ file.name }} · {{ (file.size / 1024).toFixed(1) }} KB · Replaces the URL when saved</small>
            <button type="button" class="admin-secondary" @click.prevent="cancelFile">Cancel selected file</button>
        </div>
        <StatusPanel tone="error" :message="error" />
        <div v-if="preview && !previewFailed" class="artwork-preview" :class="{ 'title-preview': titleImage, 'poster-preview': field.name === 'poster' }">
            <img :src="preview" :alt="`${field.label} preview`" @error="previewFailed = true">
        </div>
        <StatusPanel v-else-if="previewFailed" tone="warning" message="Unable to preview this image. Check the URL or select another file." />
    </div>
</template>

<style scoped>
.artwork-upload { display: grid; gap: 10px; min-width: 0; }
.artwork-selection { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.artwork-selection small { overflow-wrap: anywhere; }
.artwork-preview { width: min(100%, 520px); aspect-ratio: 16 / 9; border: 1px solid #ffffff26; border-radius: 10px; overflow: hidden; background: #16202b; }
.artwork-preview img { display: block; width: 100%; height: 100%; object-fit: contain; }
.poster-preview { width: min(100%, 200px); aspect-ratio: 2 / 3; }
.title-preview { background-color: #35404b; background-image: conic-gradient(#ffffff20 25%, transparent 0 50%, #ffffff20 0 75%, transparent 0); background-size: 24px 24px; }
.title-preview img { padding: 16px; box-sizing: border-box; }
</style>
