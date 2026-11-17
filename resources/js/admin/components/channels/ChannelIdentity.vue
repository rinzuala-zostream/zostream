<script setup>
import { ref, watch } from 'vue';
import AdminIcon from '../AdminIcon.vue';
const props = defineProps({ channel: { type: Object, required: true }, compact: Boolean });
const failedLogo = ref(false); const failedBanner = ref(false);
watch(() => props.channel.logo, () => failedLogo.value = false);
watch(() => props.channel.banner, () => failedBanner.value = false);
</script>
<template>
    <section class="channel-identity" :class="{ compact }" aria-label="Channel overview">
        <div class="identity-cover"><img v-if="channel.banner && !failedBanner" :src="channel.banner" alt="" referrerpolicy="no-referrer" @error="failedBanner = true"><span>ZO STREAM CHANNELS</span></div>
        <div class="identity-body">
            <div class="identity-logo"><img v-if="channel.logo && !failedLogo" :src="channel.logo" alt="Channel logo" referrerpolicy="no-referrer" @error="failedLogo = true"><span v-else>{{ (channel.name || 'C').slice(0, 1).toUpperCase() }}</span></div>
            <div class="identity-copy"><div class="identity-title"><h2>{{ channel.name }}</h2><AdminIcon v-if="channel.is_verified" name="badge" aria-label="Verified channel" /></div><p>/{{ channel.slug || 'your-channel' }}</p></div>
            <span class="identity-status" :data-status="channel.status">{{ channel.status }}</span>
        </div>
        <p v-if="channel.description && !compact" class="identity-description">{{ channel.description }}</p>
        <slot />
    </section>
</template>
<style scoped>
.channel-identity{overflow:hidden;border:1px solid var(--a-line);border-radius:18px;background:var(--a-panel);margin:0 0 24px}.identity-cover{height:140px;position:relative;background:linear-gradient(120deg,#123b41,#152330 55%,#263449);overflow:hidden}.identity-cover img{width:100%;height:100%;object-fit:cover}.identity-cover>span{position:absolute;right:20px;bottom:18px;font-size:10px;letter-spacing:3px;color:#d8f6f3;text-shadow:0 1px 5px #000}.identity-body{display:flex;gap:16px;align-items:center;padding:0 24px 20px}.identity-logo{width:76px;height:76px;flex-shrink:0;border:4px solid var(--a-panel);border-radius:18px;margin-top:-22px;z-index:1;overflow:hidden;background:#19474b;display:grid;place-items:center;color:#b5f3e9;font-size:30px;font-weight:700}.identity-logo img{width:100%;height:100%;object-fit:cover}.identity-copy{flex:1;min-width:0;padding-top:15px}.identity-title{display:flex;align-items:center;gap:8px}.identity-title h2{font-size:22px;margin:0;overflow-wrap:anywhere}.identity-title svg{width:20px;height:20px;flex-shrink:0;color:var(--a-cyan)}.identity-copy p{font-size:12px;color:var(--a-muted);margin:6px 0 0;overflow-wrap:anywhere}.identity-status{font-size:11px;padding:6px 11px;border:1px solid var(--a-line);border-radius:99px;text-transform:capitalize;color:var(--a-muted)}.identity-status[data-status=active]{color:var(--a-cyan);background:rgba(53,213,208,.08)}.identity-status[data-status=pending]{color:#d7aa52}.identity-description{padding:0 24px 22px;color:var(--a-muted);font-size:13px;line-height:1.7;margin:0;white-space:pre-line;overflow-wrap:anywhere}.compact .identity-cover{height:100px}@media(max-width:600px){.identity-cover{height:110px}.identity-body{padding:0 16px 16px;gap:10px;flex-wrap:wrap}.identity-title h2{font-size:18px}.identity-logo{width:60px;height:60px}.identity-status{margin-left:auto}.identity-description{padding:0 16px 18px}}
</style>
