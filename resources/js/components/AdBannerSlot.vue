<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    placement: { type: String, required: true },
    platform: { type: String, default: 'web' },
});

const ad = ref(null);
const slot = ref(null);
const imageLoaded = ref(false);
const visible = ref(false);
const hidden = ref(false);
let observer;
let impressionEventId = '';
let impressionPromise;

const requestHeaders = (json = false) => ({
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
    'X-Client-Platform': props.platform,
    'X-Client-Version': '1.0',
});

function eventId() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.random() * 16 | 0;
        const value = character === 'x' ? random : (random & 0x3 | 0x8);
        return value.toString(16);
    });
}

async function sendEvent(event, extra = {}) {
    if (!ad.value?.tracking_token) return false;
    const response = await fetch('/api/v4/ads/events', {
        method: 'POST',
        headers: requestHeaders(true),
        body: JSON.stringify({
            tracking_token: ad.value.tracking_token,
            event_id: eventId(),
            event,
            ...extra,
        }),
        keepalive: true,
    });

    return response.ok;
}

async function recordImpression() {
    if (!ad.value || !imageLoaded.value || !visible.value) return false;
    if (impressionEventId) return true;
    if (impressionPromise) return impressionPromise;

    const pendingId = eventId();
    impressionPromise = fetch('/api/v4/ads/events', {
        method: 'POST',
        headers: requestHeaders(true),
        body: JSON.stringify({
            tracking_token: ad.value.tracking_token,
            event_id: pendingId,
            event: 'impression',
        }),
        keepalive: true,
    }).then((response) => {
        if (!response.ok) return false;
        impressionEventId = pendingId;
        observer?.disconnect();
        return true;
    }).catch(() => false).finally(() => {
        impressionPromise = null;
    });

    return impressionPromise;
}

async function openAdvertiser() {
    const destination = ad.value?.target_url || ad.value?.ad_url;
    if (!destination) return;

    const popup = window.open('about:blank', '_blank');
    if (popup) popup.opener = null;
    try {
        visible.value = true;
        const recorded = await recordImpression();
        if (recorded) {
            void sendEvent('click', { impression_event_id: impressionEventId }).catch(() => false);
        }
    } finally {
        if (popup) popup.location.href = destination;
        else window.location.href = destination;
    }
}

async function loadAd() {
    try {
        const query = new URLSearchParams({ placement: props.placement, platform: props.platform });
        const response = await fetch(`/api/v4/ads/serve?${query}`, { headers: requestHeaders() });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success === false || !['image', 'website'].includes(payload?.data?.type)) return;
        if (!payload.data.media_url && !payload.data.thumbnail_url) return;

        ad.value = payload.data;
        await nextTick();
        observer = new IntersectionObserver((entries) => {
            visible.value = entries.some((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.5);
            if (visible.value) window.setTimeout(recordImpression, 1000);
        }, { threshold: [0.5] });
        if (slot.value) observer.observe(slot.value);
    } catch {
        // Advertising must never block the public website.
    }
}

function imageReady() {
    imageLoaded.value = true;
    if (visible.value) window.setTimeout(recordImpression, 1000);
}

onMounted(loadAd);
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <aside v-if="ad && !hidden" ref="slot" class="ad-banner-slot" :data-placement="placement" aria-label="Sponsored advertisement">
        <button type="button" :disabled="!ad.target_url && !ad.ad_url" @click="openAdvertiser">
            <img
                :src="ad.media_url || ad.thumbnail_url"
                :alt="ad.name || 'Sponsored advertisement'"
                :loading="placement === 'home_top' ? 'eager' : 'lazy'"
                @load="imageReady"
                @error="hidden = true"
            >
            <span>Sponsored</span>
            <strong v-if="ad.name">{{ ad.name }}</strong>
            <i v-if="ad.target_url || ad.ad_url">Visit advertiser ↗</i>
        </button>
    </aside>
</template>

<style scoped>
.ad-banner-slot{width:min(calc(100% - 40px),1180px);margin:28px auto}.ad-banner-slot button{position:relative;display:block;width:100%;overflow:hidden;padding:0;border:1px solid rgba(255,255,255,.12);border-radius:20px;background:#0c1016;color:#fff;cursor:pointer;box-shadow:0 22px 60px rgba(0,0,0,.25);text-align:left}.ad-banner-slot button:disabled{cursor:default}.ad-banner-slot img{display:block;width:100%;max-height:310px;aspect-ratio:16/5;object-fit:cover}.ad-banner-slot span{position:absolute;top:13px;left:13px;padding:5px 8px;border:1px solid rgba(255,255,255,.18);border-radius:999px;background:rgba(5,9,13,.76);color:#dce5ea;font-size:8px;font-style:normal;font-weight:850;letter-spacing:1.2px;text-transform:uppercase;backdrop-filter:blur(9px)}.ad-banner-slot strong{position:absolute;right:18px;bottom:17px;left:18px;overflow:hidden;color:#fff;font:800 clamp(17px,2.5vw,30px) var(--display);text-overflow:ellipsis;text-shadow:0 2px 12px #000;white-space:nowrap}.ad-banner-slot i{position:absolute;right:15px;bottom:15px;padding:7px 10px;border-radius:8px;background:rgba(5,9,13,.78);color:var(--cyan);font-size:9px;font-style:normal;font-weight:850;backdrop-filter:blur(9px)}.ad-banner-slot strong~i{bottom:56px}@media(max-width:650px){.ad-banner-slot{width:calc(100% - 28px);margin:18px auto}.ad-banner-slot button{border-radius:13px}.ad-banner-slot img{min-height:120px;aspect-ratio:16/7}.ad-banner-slot strong{right:12px;bottom:12px;left:12px}.ad-banner-slot i{right:10px;bottom:10px}.ad-banner-slot strong~i{bottom:45px}}
</style>
