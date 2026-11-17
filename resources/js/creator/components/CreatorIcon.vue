<script setup>
defineProps({ name: String });
const paths = {
    overview: ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h7v7h-7z'],
    video: ['M4 4h16v16H4z', 'm10 8 6 4-6 4z'],
    upload: ['M12 16V3', 'm7 8 5-5 5 5', 'M4 15v6h16v-6'],
    channel: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8', 'M4 21v-2a8 6 0 0 1 16 0v2'],
    members: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8', 'M22 21v-2a4 4 0 0 0-3-3.87'],
    arrow: ['M5 12h14', 'm13 6 6 6-6 6'],
    plus: ['M12 5v14', 'M5 12h14'],
    check: ['m5 12 4 4L19 6'],
    image: ['M3 4h18v16H3z', 'm3 16 5-5 4 4 3-3 6 6', 'M15 9h.01'],
    logout: ['M10 17l5-5-5-5', 'M15 12H3', 'M21 19V5a2 2 0 0 0-2-2h-6'],
    clock: ['M12 8v4l3 2', 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'],
};
</script>
<template><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path v-for="path in paths[name] || paths.video" :key="path" :d="path" /></svg></template>
