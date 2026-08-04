<script setup>
import { platformStats } from '../data/landing';
</script>

<template>
    <section class="numbers section-pad" aria-label="Platform statistics" data-reveal>
        <div class="numbers-label"><span>ZO STREAM</span><p>Entertainment in numbers</p></div>
        <div v-for="stat in platformStats" :key="stat.label" class="number"><strong>{{ stat.value }}<span>{{ stat.suffix }}</span></strong><p>{{ stat.label }}</p></div>
    </section>
</template>
