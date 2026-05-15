//@ts-nocheck
import { createRouter, createWebHistory, RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import appConfig from '@/api-key.config';
import PublicLayout from '../layouts/PublicLayout.vue';
import home from '@/views/home/home.vue';
import login from '@/views/authentication/login.vue';
import forgotPassword from '@/views/authentication/forgotPassword.vue';
import resetPassword from '@/views/authentication/resetPassword.vue';
import register from '@/views/authentication/register.vue';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import dashboard from '@/views/dashboard/dashboard.vue';
import requests from '@/views/dashboard/requests.vue';
import profile from '@/views/dashboard/profile.vue';
import plans from '@/views/dashboard/plans.vue';
import billing from '@/views/dashboard/billing.vue';
import adminPlans from '@/views/admin/plans.vue';
import adminCoupons from '@/views/admin/coupons.vue';
import adminUsers from '@/views/admin/users.vue';
import adminRefunds from '@/views/admin/refunds.vue';

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        component: PublicLayout,
        children: [
            { path: '', name: 'home', component: home },
            { path: 'login', name: 'login', component: login, meta: { guest: true } },
            { path: 'register', name: 'register', component: register, meta: { guest: true } },
            { path: 'forgot-password', name: 'forgot-password', component: forgotPassword, meta: { guest: true } },
            { path: 'reset-password', name: 'reset-password', component: resetPassword, meta: { guest: true } },
            ...appConfig.routes.extraPublicRoutes,
        ],
    },
    {
        path: '/dashboard',
        component: DashboardLayout,
        meta: { requiresAuth: true },
        children: [
            { path: '', name: 'dashboard', component: dashboard },
            { path: 'profile', name: 'dashboard.profile', component: profile },
            { path: 'requests', name: 'dashboard.requests', component: requests },
            { path: 'plans', name: 'dashboard.plans', component: plans },
            { path: 'billing', name: 'dashboard.billing', component: billing },
            // Admin
            { path: 'admin/plans',   name: 'admin.plans',   component: adminPlans,   meta: { requiresAdmin: true } },
            { path: 'admin/coupons', name: 'admin.coupons', component: adminCoupons, meta: { requiresAdmin: true } },
            { path: 'admin/users',   name: 'admin.users',   component: adminUsers,   meta: { requiresAdmin: true } },
            { path: 'admin/refunds', name: 'admin.refunds', component: adminRefunds, meta: { requiresAdmin: true } },
            ...appConfig.routes.extraDashboardRoutes,
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(to, from, savedPosition) {
        if (to.hash) {
            return new Promise((resolve) => {
                setTimeout(() => {
                    const element = document.querySelector(to.hash);
                    if (element) {
                        element.scrollIntoView({ behavior: 'smooth' });
                        resolve({ el: to.hash, behavior: 'smooth' });
                    } else {
                        resolve({ top: 0 });
                    }
                }, 100);
            });
        }
        if (savedPosition) return savedPosition;
        return { top: 0 };
    },
});

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();

    if (authStore.token && !authStore.user) {
        try {
            await authStore.initializeAuth();
        } catch (err) {
            console.log('Falha ao inicializar auth:', err);
        }
    }

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return next({ name: 'login' });
    }

    if (to.meta.requiresAdmin && !authStore.isAdmin) {
        return next({ name: 'dashboard' });
    }

    if (to.meta.guest && authStore.isAuthenticated) {
        return next({ name: 'dashboard' });
    }

    next();
});

export default router;
