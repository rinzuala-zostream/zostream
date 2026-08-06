import { onBeforeUnmount, reactive } from 'vue';
import { apiBlob } from './api';

const supportedTypes = new Set(['image', 'document']);

export function useWhatsAppMedia() {
    const urls = reactive({});
    const loading = reactive({});
    const failed = reactive({});

    async function loadOne(message) {
        if (!supportedTypes.has(message.type) || urls[message.id] || loading[message.id] || failed[message.id]) return;
        loading[message.id] = true;
        try {
            const blob = await apiBlob(`/admin/whatsapp/messages/${message.id}/media`);
            urls[message.id] = URL.createObjectURL(blob);
        } catch (reason) {
            failed[message.id] = reason?.message || 'Media unavailable.';
        } finally {
            loading[message.id] = false;
        }
    }

    function loadAll(messages) {
        messages.filter(message => supportedTypes.has(message.type)).forEach(loadOne);
    }

    function filename(message) {
        return message.payload?.document?.filename || `WhatsApp document ${message.id}`;
    }

    function caption(message) {
        return /^\[(image|document)\]$/i.test(message.body || '') ? '' : message.body;
    }

    onBeforeUnmount(() => {
        Object.values(urls).forEach(url => URL.revokeObjectURL(url));
    });

    return { urls, loading, failed, loadAll, filename, caption };
}
