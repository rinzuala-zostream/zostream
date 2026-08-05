<script setup>
const experiences = [
    { number: '01', title: 'I tawngin', text: 'Menu, audio leh experience pumpui Mizo tawng centered-in.' },
    { number: '02', title: 'I screen zawngah', text: 'Phone, web, tablet leh TV-ah seamless-in continue rawh.' },
    { number: '03', title: 'I hun remchangin', text: 'Download offline la, internet awm lo pawhin enjoy rawh.' },
];
</script>

<template>
    <section id="featured" class="experience section-pad">
        <div class="experience-heading" data-reveal><span class="section-kicker">THE ZO STREAM EXPERIENCE</span><h2>Entertainment hi<br /><b>i nun dan milin.</b></h2><p>Technology harsa tak chu kan thukru. Nangman enjoy mai tur a ni.</p></div>
        <div class="experience-stage" data-reveal>
            <div class="experience-orbit orbit-one"></div><div class="experience-orbit orbit-two"></div>
            <div class="experience-logo"><img :src="'/images/zostream-logo.jpg'" alt="Zo Stream" /><span>ZO <b>STREAM</b></span></div>
            <div class="screen-card screen-tv"><div class="screen-bar"><i></i><i></i><i></i></div><div class="screen-content"><span>SMART TV</span><h3>Big screen.<br />Local heart.</h3><div><i></i><i></i><i></i></div></div></div>
            <div class="screen-card screen-phone"><div class="phone-speaker"></div><span>Z</span><p>Continue watching</p><i></i><i></i></div>
            <div class="screen-card screen-web"><span>WEB APP</span><div class="web-lines"><i></i><i></i><i></i></div><b>▶</b></div>
        </div>
        <div class="experience-list" data-reveal><article v-for="item in experiences" :key="item.number"><span>{{ item.number }}</span><h3>{{ item.title }}</h3><p>{{ item.text }}</p></article></div>
    </section>
</template>
