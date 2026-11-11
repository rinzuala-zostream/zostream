<script setup>
import { computed } from 'vue';
const props = defineProps({ config: Object, tokens: Object, kind: { type: String, default: 'movie' } });
const shown = computed(() => props.config?.enabled && props.config.preset !== 'none' && props.config[props.kind === 'episode' ? 'episode_cards' : 'movie_cards']);
const style = computed(() => ({ opacity: props.config?.opacity ?? 0.8, '--card-accent': props.tokens?.accent, '--card-primary': props.tokens?.primary, '--card-secondary': props.tokens?.secondary }));
</script>
<template>
    <div v-if="shown" class="theme-card-decoration" :class="`card-${config.preset}`" :style="style" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <g v-if="config.preset === 'snow'"><path d="M12 2v20M3.3 7l17.4 10M3.3 17L20.7 7M8 4l4 3 4-3M8 20l4-3 4 3" /></g>
            <path v-else-if="['stars', 'confetti'].includes(config.preset)" d="m12 2 3 6 7 1-5 5 1 8-6-4-6 4 1-8-5-5 7-1Z" />
            <path v-else-if="config.preset === 'hearts'" d="M12 21S2 15 2 8a5 5 0 0 1 10-1 5 5 0 0 1 10 1c0 7-10 13-10 13Z" />
            <g v-else-if="config.preset === 'woven'"><path d="m12 2 10 10-10 10L2 12Z M12 7l5 5-5 5-5-5Z" /><path d="M2 4h20M2 20h20" /></g>
            <g v-else-if="config.preset === 'petals'"><ellipse cx="12" cy="7" rx="3" ry="5" /><ellipse cx="12" cy="17" rx="3" ry="5" /><ellipse cx="7" cy="12" rx="5" ry="3" /><ellipse cx="17" cy="12" rx="5" ry="3" /><circle cx="12" cy="12" r="2" /></g>
            <g v-else-if="config.preset === 'tricolor'" stroke-width="4"><path d="M3 5h18" stroke="#FF9933" /><path d="M3 12h18" stroke="#FFFFFF" /><path d="M3 19h18" stroke="#138808" /></g>
            <g v-else-if="config.preset === 'lights'"><path d="M3 14c1 10 17 10 18 0Z M12 13c-6-4 0-11 0-11s6 7 0 11Z" /></g>
            <g v-else-if="config.preset === 'pumpkin'"><path d="M12 6V2l4-1" /><ellipse cx="12" cy="14" rx="10" ry="8" /><ellipse cx="12" cy="14" rx="5" ry="8" /></g>
            <g v-else-if="['grain', 'maize'].includes(config.preset)"><path d="M12 22V3M12 8C3 9 4 2 4 2s9-1 8 6ZM12 14C3 15 4 8 4 8s9-1 8 6ZM12 11c9 1 8-6 8-6s-9-1-8 6ZM12 18c9 1 8-6 8-6s-9-1-8 6Z" /></g>
        </svg>
    </div>
</template>
<style scoped>
.theme-card-decoration{position:absolute;inset:1px;border:1px solid var(--card-accent);border-radius:inherit;pointer-events:none;z-index:2;color:var(--card-accent)}svg{position:absolute;top:8px;left:8px;width:23px;height:23px;filter:drop-shadow(0 1px 2px #000)}.card-snow{border-top:4px solid #f4f8ff;color:#f4f8ff}.card-hearts{color:var(--card-primary)}.card-woven{border-left:4px double var(--card-primary);border-bottom:4px double var(--card-accent)}.card-tricolor{border-top-color:#ff9933;border-bottom-color:#138808}.card-confetti{border-style:dashed}.card-lights{box-shadow:inset 0 0 8px #f59e0b35}
</style>
