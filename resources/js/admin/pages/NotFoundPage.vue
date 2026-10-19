<script setup>
const savedTheme = localStorage.getItem('zostream_admin_theme');
const isLight = savedTheme ? savedTheme === 'light' : window.matchMedia('(prefers-color-scheme: light)').matches;
</script>
<template><main class="admin-not-found" :class="{ 'is-light': isLight }"><p>404</p><h1>Workspace page not found</h1><RouterLink to="/dashboard" class="admin-primary compact">Return to dashboard</RouterLink></main></template>
