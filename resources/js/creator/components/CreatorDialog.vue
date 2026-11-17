<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
const props = defineProps({ title: { type: String, required: true }, closeLabel: { type: String, default: 'Close dialog' }, busy: Boolean });
const emit = defineEmits(['close']);
const dialog = ref(null);
let previousFocus; let previousOverflow; let mounted = true;
function close() { if (!props.busy) emit('close'); }
onMounted(async () => {
    previousFocus = document.activeElement;
    previousOverflow = document.body?.style.overflow;
    if (document.body) document.body.style.overflow = 'hidden';
    dialog.value?.showModal?.();
    await nextTick();
    if (mounted) (dialog.value?.querySelector?.('input:not([disabled]):not([type=file]), textarea:not([disabled])') || dialog.value?.querySelector?.('h2'))?.focus?.();
});
onBeforeUnmount(() => {
    mounted = false;
    dialog.value?.close?.();
    if (document.body) document.body.style.overflow = previousOverflow;
    if (previousFocus?.isConnected) previousFocus.focus?.({ preventScroll: true });
    else document.querySelector?.('.cs-library-bar button, .cs-recent-edit, .cs-topbar button')?.focus?.({ preventScroll: true });
});
</script>
<template>
    <dialog ref="dialog" class="cs-dialog" :aria-label="title" :aria-busy="busy" @cancel.prevent="close" @click.self="close">
        <div class="cs-dialog-surface"><header class="cs-dialog-heading"><div><p class="cs-eyebrow">CREATOR STUDIO</p><h2 tabindex="-1">{{ title }}</h2></div><button type="button" class="cs-button cs-button-quiet cs-dialog-close" :disabled="busy" :aria-label="closeLabel" @click="close"><span aria-hidden="true">×</span><span>{{ closeLabel }}</span></button></header><slot /></div>
    </dialog>
</template>
