import './bootstrap';
import { createApp } from 'vue';

const isAdmin = window.location.pathname === '/admin'
    || window.location.pathname.startsWith('/admin/');

if (isAdmin) {
    Promise.all([
        import('./admin/AdminApp.vue'),
        import('./admin/router.js'),
        import('../css/admin.css'),
    ]).then(([{ default: AdminApp }, { default: router }]) => {
        createApp(AdminApp).use(router).mount('#app');
    });
} else {
    import('./App.vue').then(({ default: App }) => createApp(App).mount('#app'));
}
