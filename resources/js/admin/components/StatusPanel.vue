<script setup>
import { watch } from 'vue';
import { notify } from '../lib/feedback';
const props = defineProps({ tone: { type: String, default: 'info' }, message: String });
watch(() => [props.message, props.tone], ([message, tone]) => { if (message) notify(message, tone); }, { immediate: true, flush: 'post' });
</script>
<template><div v-if="message" class="admin-status" :class="`is-${tone}`">{{ message }}</div></template>
