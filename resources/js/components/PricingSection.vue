<script setup>
import SectionHeading from './SectionHeading.vue';
import { plans } from '../data/landing';
</script>

<template>
    <section id="plans" class="plans section-pad">
        <div data-reveal><SectionHeading kicker="Simple pricing" title="Plan awlsam," accent="entertainment unlimited" description="Hidden charge awm lo. I mamawh dan milin plan thlang rawh." /></div>
        <div class="plan-grid" data-reveal>
            <article v-for="plan in plans" :key="plan.name" class="plan-card" :class="{ popular: plan.popular }">
                <div v-if="plan.popular" class="popular-label">MOST POPULAR</div><h3>{{ plan.name }}</h3>
                <div class="price">{{ plan.price }}<span>{{ plan.period }}</span></div>
                <div class="plan-spec"><span>{{ plan.quality }}</span><span>{{ plan.devices }}</span></div>
                <ul><li v-for="item in plan.items" :key="item"><span>✓</span>{{ item }}</li></ul>
                <button type="button" :class="plan.popular ? 'primary-button' : 'outline-button'">Choose {{ plan.name }}</button>
            </article>
        </div>
    </section>
</template>
