<script setup>
defineProps({ name: { type: String, required: true } });
</script>

<template>
    <svg v-if="name === 'phone'" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg>
    <svg v-else-if="name === 'apple'" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.7 12.8c0-2.1 1.7-3.1 1.8-3.2a4 4 0 0 0-3.2-1.7c-1.3-.1-2.6.8-3.3.8-.7 0-1.7-.8-2.8-.8-1.4 0-2.8.9-3.6 2.2-1.6 2.7-.4 6.7 1.1 8.9.8 1.1 1.7 2.3 2.9 2.2 1.1-.1 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.1 2.8-2.2.9-1.3 1.2-2.6 1.2-2.7-.1 0-2.9-1.1-2.9-3.5ZM13.5 6.5a4 4 0 0 0 .9-2.9 4.2 4.2 0 0 0-2.7 1.4 3.8 3.8 0 0 0-1 2.8c1 .1 2-.5 2.8-1.3Z"/></svg>
    <svg v-else-if="name === 'globe'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
    <svg v-else-if="name === 'message'" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H9l-5 4V5Z"/><path d="M8 9h8M8 13h5"/></svg>
    <svg v-else-if="name === 'bolt'" viewBox="0 0 24 24" aria-hidden="true"><path d="m13 2-9 12h7l-1 8 9-12h-7l1-8Z"/></svg>
    <svg v-else-if="name === 'quality'" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v12H3zM7 7l2-3h6l2 3"/><path d="m9 15 2-2 2 2 3-4"/></svg>
    <svg v-else-if="name === 'play-store'" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 2 13.5 10L3 22V2Zm15 11.1 2-1.1-2-1.1-2.2 1.1 2.2 1.1Z"/></svg>
</template>
