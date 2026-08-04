<script setup>
import AppIcon from './AppIcon.vue';
import SectionHeading from './SectionHeading.vue';
import { features } from '../data/landing';
</script>

<template>
    <section id="features" class="features section-pad">
        <div data-reveal><SectionHeading kicker="Made for us" title="Streaming chu" accent="kan style-in" description="Local content leh modern technology kan fin. I duh ang takin, i duh hunah." /></div>
        <div class="feature-grid" data-reveal>
            <article v-for="(feature, index) in features" :key="feature.title" class="feature-card"><div class="feature-top"><div class="feature-icon"><AppIcon :name="feature.icon" /></div><span>0{{ index + 1 }}</span></div><div><h3>{{ feature.title }}</h3><p>{{ feature.text }}</p></div><i>↗</i></article>
        </div>
    </section>
</template>
