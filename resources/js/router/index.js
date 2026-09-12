import { createRouter, createWebHistory } from 'vue-router';
import LoginPage from '../pages/LoginPage.vue';
import SettingsPage from '../pages/SettingsPage.vue';
import OrganizationPage from '../pages/OrganizationPage.vue';
import SnapshotPage from '../pages/SnapshotPage.vue';
import { fetchMe } from '../api';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', name: 'login', component: LoginPage, meta: { guest: true } },
        { path: '/', name: 'organization', component: OrganizationPage, meta: { auth: true } },
        { path: '/snapshots/:id', name: 'snapshot', component: SnapshotPage, meta: { auth: true } },
        { path: '/settings', name: 'settings', component: SettingsPage, meta: { auth: true } },
    ],
});

let cachedUser = null;

export function getCachedUser() {
    return cachedUser;
}

export function setCachedUser(user) {
    cachedUser = user;
}

router.beforeEach(async (to) => {
    if (!to.meta.auth && !to.meta.guest) {
        return true;
    }

    if (cachedUser === null) {
        try {
            cachedUser = await fetchMe();
        } catch {
            cachedUser = false;
        }
    }

    if (to.meta.auth && !cachedUser) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guest && cachedUser) {
        return { name: 'organization' };
    }

    return true;
});

export default router;
