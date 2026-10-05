<script setup>
import AppIcon from './AppIcon.vue';
import SectionHeading from './SectionHeading.vue';
import { links } from '../data/landing';
</script>

<template>
    <section id="services" class="services section-pad">
        <div data-reveal>
            <SectionHeading
                kicker="One brand. Two services."
                title="Entertainment leh internet,"
                accent="Zo Stream atangin"
                description="I screen-a entertainment leh i in-a internet connection—service pahnih kan pe che."
            />
        </div>

        <div class="service-grid" data-reveal>
            <article class="service-card service-streaming">
                <div class="service-copy">
                    <span class="service-index">01 · ENTERTAINMENT</span>
                    <div class="service-icon"><AppIcon name="play" /></div>
                    <h3>Zo Stream</h3>
                    <p>Mizo content, movies leh series te phone, web leh TV-ah i duh hun hunah en rawh.</p>
                    <a class="service-link" :href="links.downloadPage">Start watching <span>↗</span></a>
                </div>
                <div class="streaming-visual" aria-hidden="true">
                    <div class="streaming-screen">
                        <span>Zo Stream</span>
                        <b>Kan stories.<br />Kan tawng.</b>
                        <i>▶</i>
                    </div>
                    <div class="streaming-rail"><i></i><i></i><i></i></div>
                </div>
            </article>

            <article class="service-card service-wifi">
                <div class="service-copy">
                    <span class="service-index">02 · CONNECTIVITY</span>
                    <div class="service-icon"><AppIcon name="wifi" /></div>
                    <h3>Zo Stream WiFi</h3>
                    <p>In leh office atan internet connection. Streaming, work leh everyday browsing atan.</p>
                    <a class="service-link" :href="links.wifiWhatsApp" target="_blank" rel="noreferrer">WiFi chungchang zawt rawh <span>↗</span></a>
                </div>
                <div class="wifi-visual" aria-hidden="true">
                    <i class="wifi-ring wifi-ring-one"></i>
                    <i class="wifi-ring wifi-ring-two"></i>
                    <i class="wifi-ring wifi-ring-three"></i>
                    <div class="wifi-router"><span></span><span></span><span></span><b>Zo</b></div>
                    <div class="wifi-status"><i></i><span><b>Connected</b><small>Zo Stream WiFi</small></span></div>
                </div>
            </article>
        </div>
    </section>
</template>
