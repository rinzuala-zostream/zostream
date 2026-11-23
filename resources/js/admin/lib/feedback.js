import { reactive, shallowRef } from 'vue';

export const toasts = reactive([]);
export const confirmation = shallowRef(null);
const timers = new Map();
const pending = [];
let sequence = 0;
const tones = new Set(['success', 'error', 'warning', 'info']);

export function dismissToast(id) {
    clearTimeout(timers.get(id)?.timer);
    timers.delete(id);
    const index = toasts.findIndex(item => item.id === id);
    if (index >= 0) toasts.splice(index, 1);
}
function startTimer(id) {
    const timing = timers.get(id);
    if (!timing || timing.remaining <= 0) return;
    timing.started = Date.now();
    timing.timer = setTimeout(() => dismissToast(id), timing.remaining);
    timing.timer?.unref?.();
}
export function pauseToast(id, reason = 'interaction') {
    const timing = timers.get(id);
    if (!timing || timing.reasons.has(reason)) return;
    if (!timing.reasons.size) {
        clearTimeout(timing.timer);
        timing.remaining = Math.max(1, timing.remaining - (Date.now() - timing.started));
    }
    timing.reasons.add(reason);
}
export function resumeToast(id, reason = 'interaction') {
    const timing = timers.get(id);
    if (!timing || !timing.reasons.delete(reason)) return;
    if (!timing.reasons.size) startTimer(id);
}
export function notify(message, tone = 'info') {
    const text = String(message || '').trim();
    if (!text) return null;
    const kind = tones.has(tone) ? tone : 'info';
    const existing = toasts.find(item => item.message === text && item.tone === kind);
    if (existing) return existing.id;
    while (toasts.length >= 5) dismissToast(toasts[0].id);
    const item = { id: ++sequence, message: text, tone: kind };
    toasts.push(item);
    // Errors and warnings stay visible until dismissed. Other messages pause on hover/focus.
    if (kind === 'success' || kind === 'info') {
        timers.set(item.id, { remaining: kind === 'success' ? 6500 : 8000, started: 0, reasons: new Set() });
        startTimer(item.id);
        if (confirmation.value) pauseToast(item.id, 'dialog');
    }
    return item.id;
}
export function clearToasts() { [...toasts].forEach(item => dismissToast(item.id)); }
function showNext() {
    confirmation.value = pending[0]?.view || null;
    toasts.forEach(item => confirmation.value ? pauseToast(item.id, 'dialog') : resumeToast(item.id, 'dialog'));
}
export function confirmAction(message, options = {}) {
    const text = String(message || '').trim();
    if (!text || pending.some(item => item.view.message === text)) return Promise.resolve(false);
    return new Promise(resolve => {
        pending.push({ resolve, view: { id: ++sequence, message: text, title: options.title || 'Confirm action', confirmLabel: options.confirmLabel || 'Confirm', tone: options.tone || 'warning' } });
        if (pending.length === 1) showNext();
    });
}
export function resolveConfirmation(approved, id = confirmation.value?.id) {
    if (!pending.length || pending[0].view.id !== id) return;
    const item = pending.shift();
    showNext();
    item.resolve(Boolean(approved));
}
export function cancelConfirmations() {
    const items = pending.splice(0);
    showNext();
    items.forEach(item => item.resolve(false));
}
