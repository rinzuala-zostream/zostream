<script setup>
import { computed, ref, watch } from 'vue';
import CreatorIcon from './CreatorIcon.vue';
const props = defineProps({ detail: Object, recent: Array, busy: Boolean, writable: Boolean });
const emit = defineEmits(['create', 'edit', 'navigate']);
const failedLogo = ref(false);
watch(() => props.detail.channel.logo, () => failedLogo.value = false);
const profileItems = computed(() => [
    { label: 'Channel name', complete: Boolean(props.detail.channel.name) },
    { label: 'Channel logo', complete: Boolean(props.detail.channel.logo) },
    { label: 'Banner artwork', complete: Boolean(props.detail.channel.banner) },
    { label: 'About your channel', complete: Boolean(props.detail.channel.description) },
]);
const completed = computed(() => profileItems.value.filter(item => item.complete).length);
const metrics = computed(() => [
    { label: 'Total content', value: props.detail.summary.contents, icon: 'video', note: 'Across your channel' },
    { label: 'Published', value: props.detail.summary.published, icon: 'check', note: 'Includes scheduled releases' },
    { label: 'Drafts', value: props.detail.summary.drafts, icon: 'clock', note: 'Prepare for staff review' },
    { label: 'Active members', value: props.detail.summary.active_members, icon: 'members', note: 'Current channel memberships' },
]);
function addedDate(value) { const date = value && new Date(value); return date && !Number.isNaN(date.getTime()) ? new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' }).format(date) : 'Date unavailable'; }
</script>
<template>
    <div class="cs-overview">
        <section class="cs-overview-welcome"><div class="cs-overview-channel"><img v-if="detail.channel.logo && !failedLogo" :src="detail.channel.logo" alt="Channel logo" referrerpolicy="no-referrer" @error="failedLogo = true"><span v-else class="cs-overview-avatar">{{ (detail.channel.name || 'C').slice(0, 1) }}</span><div><p class="cs-eyebrow">YOUR CHANNEL AT A GLANCE</p><h2>{{ detail.channel.name }}</h2><p>/{{ detail.channel.slug }}</p></div></div><div class="cs-overview-welcome-actions"><span class="cs-status" :data-status="detail.channel.status">{{ detail.channel.status }}</span><button class="cs-button cs-button-outline" :disabled="busy" @click="emit('navigate', 'profile')">Channel settings<CreatorIcon name="arrow" /></button></div></section>
        <div class="cs-overview-metrics" aria-label="Channel totals"><article v-for="metric in metrics" :key="metric.label"><div><span>{{ metric.label }}</span><CreatorIcon :name="metric.icon" /></div><strong>{{ metric.value ?? '—' }}</strong><small>{{ metric.note }}</small></article></div>
        <div class="cs-overview-columns">
            <section class="cs-overview-panel cs-recent-panel"><header><div><h2>Recently added</h2><p>Your latest content, in one place.</p></div><button class="cs-button cs-button-quiet" :disabled="busy" @click="emit('navigate', 'contents')">View all videos<CreatorIcon name="arrow" /></button></header><ul v-if="recent?.length" class="cs-recent-list"><li v-for="video in recent" :key="video.id"><div class="cs-recent-art"><CreatorIcon name="video" /></div><div class="cs-recent-copy"><strong :title="video.title">{{ video.title || 'Untitled video' }}</strong><small>{{ video.created_at ? 'Added ' + addedDate(video.created_at) : 'Creation date unavailable' }}</small></div><span class="cs-status" :data-status="video.status">{{ video.status }}</span><button class="cs-button cs-button-quiet cs-recent-edit" :disabled="busy || !writable" :aria-label="`Edit ${video.title || 'untitled video'}`" @click="emit('edit', video)">Edit</button></li></ul><div v-else class="cs-overview-empty"><CreatorIcon name="video" /><h3>Your channel’s next chapter starts here.</h3><p>Create a draft and upload an MP4 for staff review.</p><button class="cs-button cs-button-outline" :disabled="busy || !writable" @click="emit('create')">Create your first video</button></div></section>
            <div class="cs-overview-side"><section class="cs-overview-panel cs-next-step"><span class="cs-eyebrow">KEEP CREATING</span><h2>{{ Number(detail.summary.drafts) > 0 ? 'Pick up where you left off.' : 'Ready for your next story?' }}</h2><p>{{ Number(detail.summary.drafts) > 0 ? 'Your drafts are waiting. Add details and source files before staff review.' : 'Start a new video with a title, a cover image and your original MP4.' }}</p><button class="cs-button cs-button-primary" :disabled="busy || !writable" @click="Number(detail.summary.drafts) > 0 ? emit('navigate', 'contents', 'draft') : emit('create')">{{ Number(detail.summary.drafts) > 0 ? 'Review drafts' : 'Create a video' }}<CreatorIcon name="arrow" /></button></section>
            <section class="cs-overview-panel cs-profile-checklist"><header><div><h2>Channel profile</h2><p>{{ completed }} of {{ profileItems.length }} details added</p></div><span class="cs-profile-progress">{{ completed }}/{{ profileItems.length }}</span></header><ul><li v-for="item in profileItems" :key="item.label" :class="{ complete: item.complete }"><span class="cs-check-circle"><CreatorIcon v-if="item.complete" name="check" /></span>{{ item.label }}<small>{{ item.complete ? 'Added' : 'Missing' }}</small></li></ul><button class="cs-button cs-button-quiet" :disabled="busy" @click="emit('navigate', 'profile')">{{ completed === profileItems.length ? 'Manage profile' : 'Complete your profile' }}<CreatorIcon name="arrow" /></button></section></div>
        </div>
    </div>
</template>
