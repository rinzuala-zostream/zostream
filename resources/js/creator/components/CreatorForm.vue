<script setup>
import { ref, watch } from 'vue';
import CreatorUpload from './CreatorUpload.vue';
import { formValues, formPayload } from '../../admin/lib/channels';
const props = defineProps({ fields: Array, record: Object, busy: Boolean, title: String, channelId: [String, Number], contentId: [String, Number], api: Function });
const emit = defineEmits(['save', 'cancel', 'busy']);
const values = ref({}); const uploading = ref(false);
watch(() => [props.fields, props.record], () => { values.value = formValues(props.fields, props.record); }, { immediate: true });
watch(uploading, value => emit('busy', value));
const hints = { name: 'The name viewers see on your channel.', slug: 'Your channel handle. Use lowercase letters, numbers and hyphens.', title: 'Give your video a clear, memorable title.', description: 'Tell your audience what to expect.', logo: 'A square image works best.', banner: 'Use a wide landscape image.', thumbnail: 'Choose a landscape image that represents your video.' };
function save() { if (!props.busy && !uploading.value) emit('save', formPayload(props.fields, values.value)); }
</script>
<template>
    <form class="cs-editor" @submit.prevent="save">
        <header><div><p class="cs-eyebrow">{{ contentId ? 'EDIT VIDEO' : fields.some(field => field.name === 'title') ? 'START SOMETHING NEW' : 'MAKE IT YOURS' }}</p><h2>{{ title }}</h2></div><span class="cs-muted">* Required</span></header>
        <fieldset :disabled="busy"><div class="cs-form-fields"><div v-for="field in fields" :key="field.name" class="cs-field" :class="{ 'cs-field-wide': field.type === 'textarea' || field.name === 'title' || ['logo', 'banner', 'thumbnail'].includes(field.name) }">
            <label v-if="!['logo', 'banner', 'thumbnail'].includes(field.name)"><span>{{ field.label }} <b v-if="field.required">*</b></span><textarea v-if="field.type === 'textarea'" v-model="values[field.name]" :required="field.required" :maxlength="field.max" rows="5" /><input v-else v-model="values[field.name]" :type="field.type" :required="field.required" :maxlength="field.max" :pattern="field.name === 'slug' ? '[a-z0-9]+(-[a-z0-9]+)*' : undefined"><small>{{ hints[field.name] }}</small></label>
            <template v-if="['logo', 'banner', 'thumbnail'].includes(field.name)"><div class="cs-artwork-heading"><strong>{{ field.label }}</strong><small>{{ hints[field.name] }}</small></div><div v-if="values[field.name]" class="cs-artwork-preview" :class="{ square: field.name === 'logo' }"><img :src="values[field.name]" :alt="`${field.label} preview`" referrerpolicy="no-referrer"></div><CreatorUpload :api="api" :channel-id="channelId" :content-id="contentId" :disabled="busy || uploading" :label="`Upload ${field.label}`" @busy="uploading = $event" @uploaded="values[field.name] = $event.url" /><details class="cs-image-link"><summary>Use an image link instead</summary><label><span>{{ field.label }} image link</span><input v-model="values[field.name]" type="url" :maxlength="field.max" placeholder="https://"></label></details></template>
        </div></div></fieldset>
        <footer><small>{{ uploading ? 'Finish or cancel your upload before saving.' : 'Changes are applied when you save.' }}</small><button type="button" class="cs-button cs-button-quiet" :disabled="busy || uploading" @click="emit('cancel')">Cancel</button><button type="submit" class="cs-button cs-button-primary" :disabled="busy || uploading">{{ busy ? 'Saving…' : 'Save changes' }}</button></footer>
    </form>
</template>
