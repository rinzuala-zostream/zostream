import './bootstrap';
import { createApp } from 'vue';

const isAdmin = window.location.pathname === '/admin'
    || window.location.pathname.startsWith('/admin/');

const isCreator = window.location.pathname === '/creator' || window.location.pathname.startsWith('/creator/');

if (isCreator) {
    Promise.all([import('./creator/CreatorApp.vue'), import('../css/admin.css')]).then(([{ default: CreatorApp }]) => {
        createApp(CreatorApp).mount('#app');
    });
} else if (isAdmin) {
    Promise.all([
        import('./admin/AdminApp.vue'),
        import('./admin/router.js'),
        import('../css/admin.css'),
    ]).then(([{ default: AdminApp }, { default: router }]) => {
        createApp(AdminApp).use(router).mount('#app');
    });
} else {
    import('./App.vue').then(({ default: App }) => createApp(App).mount('#app')).catch(error => {
        const status = document.getElementById('stats-load-status');
        if (status) status.textContent = 'Couldn’t load your account. Check your connection and reload to try again.';
        console.error('Unable to load the website', error);
    });
}
