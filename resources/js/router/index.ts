import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from '../stores/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            redirect: '/dashboard',
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('../pages/Login.vue'),
            meta: { guestOnly: true },
        },
        {
            path: '/register',
            name: 'register',
            component: () => import('../pages/Register.vue'),
            meta: { guestOnly: true },
        },
        {
            path: '/dashboard',
            name: 'dashboard',
            component: () => import('../pages/Dashboard.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/meeting/:code',
            name: 'join',
            component: () => import('../pages/Join.vue'),
        },
        {
            path: '/meeting/:code/room',
            name: 'room',
            component: () => import('../pages/Room.vue'),
            meta: { hideChrome: true },
        },
        {
            path: '/history',
            name: 'history',
            component: () => import('../pages/History.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/profile',
            name: 'profile',
            component: () => import('../pages/Profile.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/admin',
            name: 'admin',
            component: () => import('../pages/Admin.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
    ],
});

router.beforeEach(async (to) => {
    const { state, fetchUser } = useAuth();

    if (!state.checked) {
        await fetchUser();
    }

    if (to.meta.requiresAuth && !state.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guestOnly && state.user) {
        return { name: 'dashboard' };
    }

    if (to.meta.requiresAdmin && !state.user?.is_admin) {
        return { name: 'dashboard' };
    }

    return true;
});

export default router;
