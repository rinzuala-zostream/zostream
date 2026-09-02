<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminIcon from './AdminIcon.vue';
import WhatsAppChatWidget from './WhatsAppChatWidget.vue';
import { resources } from '../lib/resources';
import { useAuth } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const mobileOpen = ref(false);
const compact = ref(localStorage.getItem('zostream_admin_sidebar') === 'compact');
const savedTheme = localStorage.getItem('zostream_admin_theme');
const theme = ref(savedTheme || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'));
const item = (key) => ({ label: resources[key].label, to: `/manage/${key}`, icon: resources[key].icon });
const groups = [
    { label: 'Overview', items: [{ label: 'Dashboard', to: '/dashboard', icon: 'grid' }] },
    { label: 'Advertising', items: [{ label: 'Ad submissions', to: '/ads/submissions', icon: 'image' }] },
    { label: 'Content', items: ['movies', 'seasons', 'episodes', 'banners'].map(item) },
    { label: 'Audience', items: ['users', 'subscriptions', 'plans', 'devices'].map(item) },
    { label: 'Engagement', items: ['polls', 'legal'].map(item).concat([
        { label: 'Poll voters', to: '/polls/voters', icon: 'users' },
        { label: 'Notifications', to: '/tools/notifications', icon: 'bell' },
        { label: 'WhatsApp Inbox', to: '/whatsapp', icon: 'message' },
        { label: 'App releases', to: '/tools/app-releases', icon: 'settings' },
        { label: 'Official clients', to: '/tools/official-clients', icon: 'monitor' },
        { label: 'Admin tools', to: '/tools/operations', icon: 'settings' },
    ]) },
];
const initials = computed(() => String(auth.state.session?.uid || 'ZA').slice(0, 2).toUpperCase());
const active = (to) => route.path === to || (to !== '/dashboard' && route.path.startsWith(to));
function toggle() {
    compact.value = !compact.value;
    localStorage.setItem('zostream_admin_sidebar', compact.value ? 'compact' : 'full');
}
function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    localStorage.setItem('zostream_admin_theme', theme.value);
}
async function logout() {
    await auth.logout();
    router.replace('/');
}
</script>

<template>
    <div class="admin-root" :class="{ 'is-compact': compact, 'is-light': theme === 'light' }">
        <button class="admin-mobile-trigger" aria-label="Open navigation" @click="mobileOpen = true"><AdminIcon name="menu" /></button>
        <div v-if="mobileOpen" class="admin-scrim" @click="mobileOpen = false" />
        <aside class="admin-sidebar" :class="{ 'is-open': mobileOpen }">
            <header class="admin-brand">
                <img :src="'/images/zostream-logo.jpg'" alt="Zo Stream">
                <span><b>ZO</b> ADMIN<small>CONTROL ROOM</small></span>
                <button class="admin-close" aria-label="Close navigation" @click="mobileOpen = false"><AdminIcon name="x" /></button>
            </header>
            <nav class="admin-nav">
                <section v-for="group in groups" :key="group.label">
                    <p>{{ group.label }}</p>
                    <RouterLink v-for="link in group.items" :key="link.to" :to="link.to" :class="{ active: active(link.to) }" @click="mobileOpen = false">
                        <AdminIcon :name="link.icon" /><span>{{ link.label }}</span>
                    </RouterLink>
                </section>
            </nav>
            <footer class="admin-sidebar-footer">
                <button class="admin-user" @click="router.push('/profile')"><i>{{ initials }}</i><span><strong>Administrator</strong><small>{{ auth.state.session?.uid }}</small></span></button>
                <button class="admin-icon-button admin-theme-toggle" :aria-label="theme === 'dark' ? 'Use light mode' : 'Use dark mode'" :aria-pressed="theme === 'light'" :title="theme === 'dark' ? 'Light mode' : 'Dark mode'" @click="toggleTheme"><AdminIcon :name="theme === 'dark' ? 'sun' : 'moon'" /></button>
                <button class="admin-icon-button" aria-label="Logout" @click="logout"><AdminIcon name="logout" /></button>
            </footer>
            <button class="admin-collapse" @click="toggle"><AdminIcon name="arrow" /><span>Collapse</span></button>
        </aside>
        <main class="admin-main"><RouterView /></main>
        <WhatsAppChatWidget />
    </div>
</template>
