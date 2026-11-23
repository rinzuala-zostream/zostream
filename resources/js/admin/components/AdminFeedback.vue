<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AdminIcon from './AdminIcon.vue';
import { toasts, confirmation, dismissToast, pauseToast, resumeToast, resolveConfirmation, cancelConfirmations } from '../lib/feedback';

const dialog = ref(null); const cancelButton = ref(null);
const media = window.matchMedia('(prefers-color-scheme: light)');
const light = ref(localStorage.getItem('zostream_admin_theme') ? localStorage.getItem('zostream_admin_theme') === 'light' : media.matches);
const titles = { success: 'Success', error: 'Error', warning: 'Warning', info: 'Information' };
let previousFocus = null;
function themeChanged(event) { light.value = event.detail === 'light'; }
function systemThemeChanged() { if (!localStorage.getItem('zostream_admin_theme')) light.value = media.matches; }
watch(confirmation, async (value, previous) => {
    if (value && !previous) previousFocus = document.activeElement;
    await nextTick();
    if (confirmation.value?.id !== value?.id) return;
    if (value) {
        if (!dialog.value?.open) dialog.value?.showModal();
        cancelButton.value?.focus();
    } else {
        dialog.value?.close();
        if (previousFocus?.isConnected) previousFocus.focus();
        previousFocus = null;
    }
}, { immediate: true });
function answer(value) { resolveConfirmation(value, confirmation.value?.id); }
onMounted(() => {
    window.addEventListener('zostream:admin-theme-change', themeChanged);
    media.addEventListener('change', systemThemeChanged);
});
onBeforeUnmount(() => {
    cancelConfirmations(); dialog.value?.close();
    window.removeEventListener('zostream:admin-theme-change', themeChanged);
    media.removeEventListener('change', systemThemeChanged);
});
</script>

<template>
    <div class="admin-feedback" :class="{ 'is-light': light }">
        <div class="admin-toast-stack" aria-label="Notifications">
            <article v-for="toast in toasts" :key="toast.id" class="admin-toast" :class="`is-${toast.tone}`" :role="toast.tone === 'error' || toast.tone === 'warning' ? 'alert' : 'status'" aria-atomic="true" @mouseenter="pauseToast(toast.id, 'hover')" @mouseleave="resumeToast(toast.id, 'hover')" @focusin="pauseToast(toast.id, 'focus')" @focusout="resumeToast(toast.id, 'focus')">
                <span class="feedback-symbol" aria-hidden="true">{{ toast.tone === 'success' ? '✓' : toast.tone === 'info' ? 'i' : '!' }}</span>
                <div><strong>{{ titles[toast.tone] }}</strong><p>{{ toast.message }}</p></div>
                <button type="button" :aria-label="`Dismiss ${titles[toast.tone].toLowerCase()} notification`" @click="dismissToast(toast.id)"><AdminIcon name="x" /></button>
            </article>
        </div>
        <dialog ref="dialog" class="admin-confirm-dialog" aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-message" @cancel.prevent="answer(false)">
            <template v-if="confirmation">
                <header><span class="feedback-symbol" aria-hidden="true">!</span><h2 id="admin-confirm-title">{{ confirmation.title }}</h2></header>
                <p id="admin-confirm-message">{{ confirmation.message }}</p>
                <footer><button ref="cancelButton" type="button" class="feedback-cancel" @click="answer(false)">Cancel</button><button type="button" class="feedback-confirm" @click="answer(true)">{{ confirmation.confirmLabel }}</button></footer>
            </template>
        </dialog>
    </div>
</template>

<style scoped>
.admin-feedback{--f-bg:#1f2937;--f-line:#455166;--f-text:#f3f4f6;--f-muted:#c0c9d6;--f-success:#6ee7b7;--f-error:#fda4af;--f-warning:#fcd34d;--f-info:#93b4ff;color:var(--f-text);font-family:'DM Sans',sans-serif}.admin-feedback.is-light{--f-bg:#fff;--f-line:#dce1e8;--f-text:#1f2937;--f-muted:#596579;--f-success:#16734d;--f-error:#b4233b;--f-warning:#875b00;--f-info:#315fbd}.admin-feedback *{box-sizing:border-box}.admin-feedback button{font:inherit;cursor:pointer}.admin-feedback button:focus-visible{outline:2px solid var(--f-info);outline-offset:3px}
.admin-toast-stack{position:fixed;z-index:10000;top:max(18px,env(safe-area-inset-top));right:max(18px,env(safe-area-inset-right));display:grid;gap:10px;width:min(430px,calc(100vw - 28px));max-height:calc(100svh - 36px);overflow-y:auto;padding:4px;pointer-events:none}.admin-toast{--f-accent:var(--f-info);display:flex;align-items:flex-start;gap:12px;padding:16px;border:1px solid var(--f-line);border-left:4px solid var(--f-accent);border-radius:10px;background:var(--f-bg);box-shadow:0 6px 26px #0003;pointer-events:auto}.admin-toast.is-success{--f-accent:var(--f-success)}.admin-toast.is-error{--f-accent:var(--f-error)}.admin-toast.is-warning{--f-accent:var(--f-warning)}.feedback-symbol{display:grid;place-items:center;flex:none;width:26px;height:26px;border:1px solid currentColor;border-radius:50%;color:var(--f-accent,var(--f-warning));font-size:16px;font-weight:800}.admin-toast>div{flex:1;min-width:0}.admin-toast strong{font-size:14px;color:var(--f-accent)}.admin-toast p{margin:6px 0 0;color:var(--f-text);font-size:13px;line-height:1.6;overflow-wrap:anywhere;white-space:pre-line}.admin-toast button{display:grid;place-items:center;flex:none;width:30px;height:30px;padding:5px;border:0;border-radius:6px;background:transparent;color:var(--f-muted)}.admin-toast button:hover{background:#80808020}.admin-toast svg{width:18px;height:18px}
.admin-confirm-dialog{width:min(460px,calc(100vw - 32px));max-height:calc(100svh - 40px);margin:auto;padding:24px;border:1px solid var(--f-line);border-radius:14px;background:var(--f-bg);color:var(--f-text);box-shadow:0 16px 60px #0005;font:inherit;overflow:auto}.admin-confirm-dialog::backdrop{background:#0009}.admin-confirm-dialog header{display:flex;align-items:center;gap:12px}.admin-confirm-dialog h2{margin:0;font-size:20px;line-height:1.4}.admin-confirm-dialog>p{margin:20px 0 24px;font-size:14px;line-height:1.7;white-space:pre-line;overflow-wrap:anywhere}.admin-confirm-dialog footer{display:flex;justify-content:flex-end;gap:10px}.admin-confirm-dialog footer button{min-height:42px;padding:10px 18px;border:1px solid var(--f-line);border-radius:8px;font-size:13px;font-weight:700}.feedback-cancel{background:transparent;color:var(--f-text)}.feedback-confirm{background:#fcd34d;color:#1f2937;border-color:#fcd34d!important}
@media(max-width:600px){.admin-toast-stack{top:auto;bottom:max(14px,env(safe-area-inset-bottom));right:10px;width:calc(100vw - 20px);max-height:50svh}.admin-toast{padding:13px}.admin-confirm-dialog{padding:20px}}
</style>
