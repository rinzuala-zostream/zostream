import { createRouter, createWebHistory } from 'vue-router';
import { getSession } from './lib/api';
import LoginPage from './pages/LoginPage.vue';
import AdminShell from './components/AdminShell.vue';
import DashboardPage from './pages/DashboardPage.vue';
import ResourceListPage from './pages/ResourceListPage.vue';
import ResourceEditorPage from './pages/ResourceEditorPage.vue';
import ToolsPage from './pages/ToolsPage.vue';
import ProfilePage from './pages/ProfilePage.vue';
import PollInsightsPage from './pages/PollInsightsPage.vue';
import PollVotersPage from './pages/PollVotersPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';

const aliases = [
    ['/movies/add', '/manage/movies/new'], ['/movies/update', '/manage/movies'], ['/movies/update/:id', '/manage/movies/:id'],
    ['/seasons/add', '/manage/seasons/new'], ['/seasons/update', '/manage/seasons'], ['/seasons/update/:id', '/manage/seasons/:id'],
    ['/seasons/episodes/add', '/manage/episodes/new'], ['/seasons/episodes/update/:id', '/manage/episodes/:id'],
    ['/subscriptions/subscribers/add', '/manage/subscriptions/new'], ['/subscriptions/subscribers', '/manage/subscriptions'], ['/subscriptions/subscribers/edit', '/manage/subscriptions'], ['/subscriptions/subscribers/edit/:id', '/manage/subscriptions/:id'],
    ['/subscriptions/plans/create', '/manage/plans/new'], ['/subscriptions/plans/edit', '/manage/plans'], ['/subscriptions/plans/edit/:id', '/manage/plans/:id'],
    ['/users/add', '/manage/users/new'], ['/users/update', '/manage/users'], ['/users/update/:id', '/manage/users/:id'], ['/users/suspend-delete', '/manage/users'], ['/users/request-otp', '/tools/operations'],
    ['/banners/add', '/manage/banners/new'], ['/banners/edit', '/manage/banners'], ['/banners/edit/:id', '/manage/banners/:id'],
    ['/devices/list', '/manage/devices'], ['/devices/clear', '/tools/operations'],
    ['/polls/create', '/manage/polls/new'], ['/polls/results', '/manage/polls'], ['/polls/results/:id', '/polls/:id/insights'],
    ['/legal/pages', '/manage/legal'], ['/notifications/create', '/tools/notifications'], ['/notifications/scrolling-text/add', '/tools/notifications'], ['/notifications/warning/add', '/tools/notifications'], ['/notifications/app-update/manage', '/tools/app-releases'],
    ['/verification/official-clients', '/tools/official-clients'],
];

const router = createRouter({
    history: createWebHistory('/admin'),
    scrollBehavior: () => ({ top: 0 }),
    routes: [
        { path: '/', component: LoginPage, meta: { guest: true } },
        {
            path: '/', component: AdminShell, children: [
                { path: 'dashboard', component: DashboardPage },
                { path: 'manage/:resource', component: ResourceListPage },
                { path: 'manage/:resource/new', component: ResourceEditorPage },
                { path: 'manage/:resource/:id', component: ResourceEditorPage },
                { path: 'tools/:tool', component: ToolsPage },
                { path: 'polls/:id/insights', component: PollInsightsPage },
                { path: 'polls/voters', component: PollVotersPage },
                { path: 'profile', component: ProfilePage },
                ...aliases.map(([path, redirect]) => ({ path: path.slice(1), redirect: (to) => redirect.replace(':id', to.params.id || '') })),
            ],
        },
        { path: '/:pathMatch(.*)*', component: NotFoundPage },
    ],
});

router.beforeEach((to) => {
    const loggedIn = Boolean(getSession()?.access_token);
    if (!to.meta.guest && !loggedIn) return { path: '/', query: { redirect: to.fullPath } };
    if (to.meta.guest && loggedIn) return '/dashboard';
});
window.addEventListener('zostream:session-expired', () => router.replace('/'));
export default router;
