<script setup>
defineProps({ kicker: String, title: String, accent: String, description: String });
</script>

<template>
    <header class="section-heading">
        <span>{{ kicker }}</span>
        <h2>{{ title }} <b>{{ accent }}</b></h2>
        <p>{{ description }}</p>
    </header>
</template>
