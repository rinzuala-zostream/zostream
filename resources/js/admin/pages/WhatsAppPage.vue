<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';
import { formatChatTime } from '../lib/chatTime';

const loading = ref(true); const busy = ref(false); const error = ref(''); const notice = ref('');
const conversations = ref([]); const messages = ref([]); const selectedPhone = ref(''); const reply = ref('');
const configOpen = ref(localStorage.getItem('zostream_whatsapp_config') !== 'closed');
const generatedToken = ref(''); let refreshTimer;
const settings = reactive({ verify_token: '', auto_reply_enabled: false, auto_reply_message: '', webhook_url: '', has_verify_token: false, server_credentials_configured: false });
const selectedConversation = computed(() => conversations.value.find(item => item.phone === selectedPhone.value));

function showError(reason) { error.value = reason?.message || 'Something went wrong.'; }
async function loadSettings() { Object.assign(settings, await api('/admin/whatsapp/settings') || {}); }
async function loadConversations(quiet = false) {
    try {
        conversations.value = await api('/admin/whatsapp/conversations') || [];
        if (!selectedPhone.value && conversations.value.length) await selectConversation(conversations.value[0].phone);
        else if (selectedPhone.value) await loadMessages(selectedPhone.value, true);
    } catch (reason) { if (!quiet) showError(reason); }
}
async function loadMessages(phone, quiet = false) {
    try { messages.value = await api(`/admin/whatsapp/conversations/${encodeURIComponent(phone)}`) || []; }
    catch (reason) { if (!quiet) showError(reason); }
}
async function selectConversation(phone) { selectedPhone.value = phone; await loadMessages(phone); }
async function saveSettings() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const saved = await api('/admin/whatsapp/settings', { method: 'PUT', body: settings });
        Object.assign(settings, saved || {}, { verify_token: '' });
        notice.value = 'WhatsApp configuration saved.';
    } catch (reason) { showError(reason); }
    finally { busy.value = false; }
}
async function generateToken() {
    busy.value = true; error.value = ''; generatedToken.value = '';
    try {
        const result = await api('/admin/whatsapp/verify-token', { method: 'POST' });
        generatedToken.value = result?.verify_token || '';
        settings.has_verify_token = Boolean(generatedToken.value);
        notice.value = result?.message || 'Verify token generated.';
    } catch (reason) { showError(reason); }
    finally { busy.value = false; }
}
async function copy(value) {
    try { await navigator.clipboard.writeText(value); notice.value = 'Copied to clipboard.'; }
    catch { error.value = 'Copy failed. Select and copy the value manually.'; }
}
function toggleConfig() {
    configOpen.value = !configOpen.value;
    localStorage.setItem('zostream_whatsapp_config', configOpen.value ? 'open' : 'closed');
}
async function sendReply() {
    const body = reply.value.trim(); if (!body || !selectedPhone.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try {
        await api('/admin/whatsapp/reply', { method: 'POST', body: { to: selectedPhone.value, message: body } });
        reply.value = ''; await loadMessages(selectedPhone.value); await loadConversations(true);
        notice.value = 'Reply sent.';
    } catch (reason) { showError(reason); }
    finally { busy.value = false; }
}

onMounted(async () => {
    try { await Promise.all([loadSettings(), loadConversations()]); }
    catch (reason) { showError(reason); }
    finally { loading.value = false; }
    refreshTimer = window.setInterval(() => loadConversations(true), 15000);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));
</script>

<template>
    <div class="admin-page whatsapp-page">
        <PageHeader eyebrow="Customer care" title="WhatsApp Inbox" description="Receive customer messages, reply from Zo Admin and configure Meta WhatsApp Cloud API." />
        <StatusPanel tone="success" :message="notice" /><StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-loading">Loading WhatsApp…</div>
        <template v-else>
            <section class="admin-panel whatsapp-setup">
                <header><div><p>CLOUD API</p><h2>Webhook</h2></div><div class="whatsapp-setup-controls"><strong :class="{ off: !settings.server_credentials_configured }">{{ settings.server_credentials_configured ? 'Server configured' : 'Missing .env credentials' }}</strong><button type="button" class="admin-secondary whatsapp-collapse" :aria-expanded="configOpen" aria-controls="whatsapp-webhook-settings" @click="toggleConfig">{{ configOpen ? 'Collapse' : 'Expand' }}<span :class="{ open: configOpen }" aria-hidden="true">⌄</span></button></div></header>
                <form v-if="configOpen" id="whatsapp-webhook-settings" class="admin-form-grid" @submit.prevent="saveSettings">
                    <label><span>Verify token</span><input v-model="settings.verify_token" type="password" :placeholder="settings.has_verify_token ? 'Saved — leave blank to keep' : 'Webhook verify token'"></label>
                    <label class="wide"><span>Callback URL</span><div class="whatsapp-copy"><input :value="settings.webhook_url" readonly><button type="button" class="admin-secondary" @click="copy(settings.webhook_url)">Copy</button></div></label>
                    <label v-if="generatedToken" class="wide"><span>New verify token — copy it now</span><div class="whatsapp-copy"><input :value="generatedToken" readonly><button type="button" class="admin-secondary" @click="copy(generatedToken)">Copy</button></div></label>
                    <label class="check"><input v-model="settings.auto_reply_enabled" type="checkbox"><span>Auto-reply to new messages</span></label>
                    <label class="wide"><span>Auto-reply message</span><textarea v-model="settings.auto_reply_message" placeholder="Thanks for contacting Zo Stream. We will reply shortly." /></label>
                    <footer class="wide whatsapp-actions"><button type="button" class="admin-secondary" :disabled="busy" @click="generateToken">Generate verify token</button><button class="admin-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save configuration' }}</button></footer>
                </form>
            </section>

            <section class="admin-panel whatsapp-inbox">
                <aside class="whatsapp-conversations">
                    <header><div><p>MESSAGES</p><h2>Conversations</h2></div><button class="admin-secondary" @click="loadConversations()">Refresh</button></header>
                    <button v-for="item in conversations" :key="item.phone" :class="{ active: selectedPhone === item.phone }" @click="selectConversation(item.phone)">
                        <i>{{ (item.name || item.phone).slice(0, 2).toUpperCase() }}</i><span><b>{{ item.name || item.phone }}</b><small>{{ item.last_message }}</small></span><time>{{ formatChatTime(item.last_message_at) }}</time>
                    </button>
                    <p v-if="!conversations.length" class="admin-empty">Webhook messages will appear here.</p>
                </aside>
                <article class="whatsapp-thread">
                    <header v-if="selectedConversation"><div><p>{{ selectedConversation.name || 'WhatsApp customer' }}</p><h2>+{{ selectedPhone }}</h2></div></header>
                    <div v-if="selectedPhone" class="whatsapp-message-list">
                        <div v-for="item in messages" :key="item.id" :class="['whatsapp-message', item.direction]"><span>{{ item.body }}</span><small>{{ formatChatTime(item.message_at) }} · {{ item.status }}</small></div>
                    </div>
                    <div v-else class="admin-empty-state"><h2>No conversation selected</h2><p>Incoming customer messages will be listed on the left.</p></div>
                    <form v-if="selectedPhone" class="whatsapp-reply" @submit.prevent="sendReply"><textarea v-model="reply" required maxlength="4096" placeholder="Type a reply…" /><button class="admin-primary" :disabled="busy || !reply.trim()">Send reply</button></form>
                </article>
            </section>
        </template>
    </div>
</template>
