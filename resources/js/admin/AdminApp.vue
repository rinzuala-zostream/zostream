<script setup>
import { onBeforeUnmount, onErrorCaptured, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import AdminFeedback from './components/AdminFeedback.vue';
import { cancelConfirmations, notify } from './lib/feedback';
const route = useRoute();
let validationTimer;
watch(() => route.fullPath, () => { cancelConfirmations(); clearTimeout(validationTimer); validationTimer = null; }, { flush: 'sync' });
function invalid(event) {
    if (validationTimer) return;
    const field = event.target;
    validationTimer = setTimeout(() => {
        validationTimer = null;
        field.focus();
        const label = field.getAttribute('aria-label') || field.labels?.[0]?.textContent?.trim().split('\n')[0] || 'This field';
        notify(`${label.slice(0, 120)}: ${field.validationMessage || 'Check this value before continuing.'}`, 'warning');
    }, 0);
}
onErrorCaptured(error => { console.error(error); notify(error?.message || 'The action could not be completed. Please try again.', 'error'); return false; });
function rejected(event) { if (event.reason?.name !== 'AbortError') notify(event.reason?.message || 'The action could not be completed. Please try again.', 'error'); }
onMounted(() => { document.addEventListener('invalid', invalid, true); window.addEventListener('unhandledrejection', rejected); });
onBeforeUnmount(() => { clearTimeout(validationTimer); cancelConfirmations(); document.removeEventListener('invalid', invalid, true); window.removeEventListener('unhandledrejection', rejected); });
</script>
<template><RouterView /><AdminFeedback /></template>
