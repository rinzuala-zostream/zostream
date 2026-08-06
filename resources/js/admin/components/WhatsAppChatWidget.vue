<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../lib/api';
import { formatChatTime } from '../lib/chatTime';
import { useWhatsAppMedia } from '../lib/whatsappMedia';
import AdminIcon from './AdminIcon.vue';

const LAST_SEEN_KEY = 'zostream_whatsapp_widget_seen_at';
const route = useRoute();
const open = ref(false);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const conversations = ref([]);
const messages = ref([]);
const selectedPhone = ref('');
const reply = ref('');
const messageList = ref(null);
const lastSeenAt = ref(Number(localStorage.getItem(LAST_SEEN_KEY) || 0));
const media = useWhatsAppMedia();
let refreshTimer;

const visible = computed(() => !route.path.startsWith('/whatsapp'));
const selectedConversation = computed(() => conversations.value.find(item => item.phone === selectedPhone.value));
const unreadCount = computed(() => conversations.value.filter(item => (
    item.direction === 'inbound' && Date.parse(item.last_message_at || '') > lastSeenAt.value
)).length);

function markSeen() {
    lastSeenAt.value = Date.now();
    localStorage.setItem(LAST_SEEN_KEY, String(lastSeenAt.value));
}
async function scrollToLatest() {
    await nextTick();
    if (messageList.value) messageList.value.scrollTop = messageList.value.scrollHeight;
}
async function loadMessages(phone, quiet = false) {
    try {
        messages.value = await api(`/admin/whatsapp/conversations/${encodeURIComponent(phone)}`) || [];
        media.loadAll(messages.value);
        await scrollToLatest();
    } catch (reason) {
        if (!quiet) error.value = reason?.message || 'Messages could not be loaded.';
    }
}
async function loadConversations(quiet = false) {
    try {
        conversations.value = await api('/admin/whatsapp/conversations') || [];
        if (open.value) {
            const currentExists = conversations.value.some(item => item.phone === selectedPhone.value);
            if (!currentExists) selectedPhone.value = '';
            if (selectedPhone.value) await loadMessages(selectedPhone.value, true);
        }
    } catch (reason) {
        if (!quiet) error.value = reason?.message || 'Conversations could not be loaded.';
    }
}
async function openWidget() {
    open.value = true;
    loading.value = true;
    error.value = '';
    await loadConversations();
    markSeen();
    loading.value = false;
}
function closeWidget() {
    open.value = false;
    markSeen();
}
async function selectConversation(phone) {
    selectedPhone.value = phone;
    error.value = '';
    await loadMessages(phone);
    markSeen();
}
async function sendReply() {
    const body = reply.value.trim();
    if (!body || !selectedPhone.value) return;
    busy.value = true;
    error.value = '';
    try {
        await api('/admin/whatsapp/reply', { method: 'POST', body: { to: selectedPhone.value, message: body } });
        reply.value = '';
        await Promise.all([loadMessages(selectedPhone.value), loadConversations(true)]);
    } catch (reason) {
        error.value = reason?.message || 'Reply could not be sent.';
    } finally {
        busy.value = false;
    }
}
function handleKeydown(event) {
    if (event.key === 'Escape' && open.value) closeWidget();
}

watch(() => route.path, () => {
    if (!visible.value) closeWidget();
});
watch(() => Object.keys(media.urls).length, scrollToLatest);
onMounted(() => {
    if (visible.value) loadConversations(true);
    refreshTimer = window.setInterval(() => {
        if (visible.value) loadConversations(true);
    }, 15000);
    window.addEventListener('keydown', handleKeydown);
});
onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <template v-if="visible">
        <button v-if="!open" class="whatsapp-widget-launcher" type="button" aria-label="Open WhatsApp conversations" @click="openWidget">
            <AdminIcon name="message" />
            <span v-if="unreadCount" class="whatsapp-widget-badge">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
        </button>
        <div v-if="open" class="whatsapp-widget-scrim" @click="closeWidget" />
        <section v-if="open" class="whatsapp-widget" role="dialog" aria-label="WhatsApp conversations">
            <header class="whatsapp-widget-header">
                <div><span>Customer care</span><h2>WhatsApp Inbox</h2></div>
                <div><RouterLink to="/whatsapp" @click="closeWidget">Open full inbox</RouterLink><button type="button" aria-label="Close WhatsApp conversations" @click="closeWidget"><AdminIcon name="x" /></button></div>
            </header>
            <div class="whatsapp-widget-content">
                <p v-if="error" class="whatsapp-widget-error">{{ error }}</p>
                <div v-if="loading" class="whatsapp-widget-loading">Loading conversations…</div>
                <div v-else class="whatsapp-widget-body" :class="{ 'has-thread': selectedPhone }">
                    <aside class="whatsapp-widget-conversations">
                        <header><b>Conversations</b><button type="button" @click="loadConversations()">Refresh</button></header>
                        <button v-for="item in conversations" :key="item.phone" type="button" :class="{ active: selectedPhone === item.phone }" @click="selectConversation(item.phone)">
                            <i>{{ (item.name || item.phone).slice(0, 2).toUpperCase() }}</i>
                            <span><b>{{ item.name || `+${item.phone}` }}</b><small>{{ item.last_message }}</small></span>
                            <time>{{ formatChatTime(item.last_message_at) }}</time>
                        </button>
                        <p v-if="!conversations.length">No WhatsApp conversations yet.</p>
                    </aside>
                    <article class="whatsapp-widget-thread">
                        <header v-if="selectedConversation"><button class="whatsapp-widget-back" type="button" aria-label="Back to conversations" @click="selectedPhone = ''"><AdminIcon name="arrow" /></button><div><b>{{ selectedConversation.name || 'WhatsApp customer' }}</b><span>+{{ selectedPhone }}</span></div></header>
                        <div v-if="selectedPhone" ref="messageList" class="whatsapp-widget-messages">
                            <div v-for="item in messages" :key="item.id" :class="['whatsapp-widget-message', item.direction]">
                                <a v-if="item.type === 'image' && media.urls[item.id]" class="whatsapp-media-image-link" :href="media.urls[item.id]" target="_blank" rel="noopener"><img class="whatsapp-media-image" :src="media.urls[item.id]" :alt="media.caption(item) || 'WhatsApp image'" @load="scrollToLatest"></a>
                                <a v-else-if="item.type === 'document' && media.urls[item.id]" class="whatsapp-media-document" :href="media.urls[item.id]" :download="media.filename(item)"><b>Document</b><span>{{ media.filename(item) }}</span></a>
                                <span v-else-if="media.loading[item.id]" class="whatsapp-media-state">Loading {{ item.type }}…</span>
                                <span v-else-if="media.failed[item.id]" class="whatsapp-media-state is-error">{{ media.failed[item.id] }}</span>
                                <span v-if="media.caption(item)">{{ media.caption(item) }}</span>
                                <small>{{ formatChatTime(item.message_at) }} · {{ item.status }}</small>
                            </div>
                        </div>
                        <div v-else class="whatsapp-widget-empty"><AdminIcon name="message" /><b>Select a conversation</b><span>Choose a customer to view and reply.</span></div>
                        <form v-if="selectedPhone" class="whatsapp-widget-reply" @submit.prevent="sendReply"><textarea v-model="reply" required maxlength="4096" placeholder="Type a reply…" /><button type="submit" class="admin-primary" :disabled="busy || !reply.trim()">{{ busy ? 'Sending…' : 'Send' }}</button></form>
                    </article>
                </div>
            </div>
        </section>
    </template>
</template>
