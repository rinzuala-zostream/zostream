<script setup>
defineProps({ name: { type: String, default: 'grid' } });
const paths = {
    message: ['M21 15a4 4 0 0 1-4 4H8l-5 3 1.5-4.5A7 7 0 0 1 3 13V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z', 'M8 9h8', 'M8 13h5'],
    grid: ['M4 4h6v6H4z', 'M14 4h6v6h-6z', 'M4 14h6v6H4z', 'M14 14h6v6h-6z'],
    users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8', 'M22 21v-2a4 4 0 0 0-3-3.87'],
    film: ['M3 5h18v14H3z', 'M7 5v14', 'M17 5v14'],
    layers: ['m12 2 9 5-9 5-9-5z', 'm3 12 9 5 9-5', 'm3 17 9 5 9-5'],
    play: ['M5 3l14 9-14 9z'],
    image: ['M3 4h18v16H3z', 'm3 16 5-5 4 4 3-3 6 6', 'M15 9h.01'],
    'credit-card': ['M3 5h18v14H3z', 'M3 10h18'],
    badge: ['M12 2l3 3 4-.5.5 4 3 3-3 3-.5 4-4-.5-3 3-3-3-4 .5-.5-4-3-3 3-3 .5-4 4 .5z'],
    chart: ['M4 20V10', 'M10 20V4', 'M16 20v-7', 'M22 20H2'],
    file: ['M6 2h8l4 4v16H6z', 'M14 2v6h6', 'M9 13h6', 'M9 17h6'],
    monitor: ['M3 4h18v12H3z', 'M8 20h8', 'M12 16v4'],
    bell: ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
    settings: ['M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7', 'M4 4l3 3M17 17l3 3M20 4l-3 3M7 17l-3 3'],
    menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
    x: ['M6 6l12 12', 'M18 6 6 18'],
    search: ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16', 'm21 21-4.35-4.35'],
    plus: ['M12 5v14', 'M5 12h14'],
    arrow: ['M19 12H5', 'm12 19-7-7 7-7'],
    trash: ['M3 6h18', 'M8 6V4h8v2', 'M19 6l-1 15H6L5 6'],
    edit: ['M12 20h9', 'M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z'],
    logout: ['M10 17l5-5-5-5', 'M15 12H3', 'M21 19V5a2 2 0 0 0-2-2h-6'],
    sun: ['M12 3V1', 'M12 23v-2', 'm4.95-13.95 1.42-1.42', 'm-12.74 12.74 1.42-1.42', 'M21 12h2', 'M1 12h2', 'm16.37 6.37-1.42-1.42', 'M7.05 7.05 5.63 5.63', 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10'],
    moon: ['M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z'],
};
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path v-for="path in (paths[name] || paths.grid)" :key="path" :d="path" />
    </svg>
</template>
