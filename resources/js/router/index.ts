//@ts-nocheck
import { createRouter, createWebHistory, RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import type { TeamRole } from '../stores/auth';
import appConfig from '@/api-key.config';
import PublicLayout from '../layouts/PublicLayout.vue';
import home from '@/views/home/home.vue';
import login from '@/views/authentication/login.vue';
import forgotPassword from '@/views/authentication/forgotPassword.vue';
import resetPassword from '@/views/authentication/resetPassword.vue';
import register from '@/views/authentication/register.vue';
import terms from '@/views/legal/terms.vue';
import privacy from '@/views/legal/privacy.vue';
import support from '@/views/support/support.vue';
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
import adminLogs from '@/views/admin/logs.vue';
import adminMetrics from '@/views/admin/metrics.vue';
import adminAudit from '@/views/admin/audit.vue';
import adminGateways from '@/views/admin/gateways.vue';
import adminBilling from '@/views/admin/billing.vue';
import adminDelinquency from '@/views/admin/delinquency.vue';
import adminHealth from '@/views/admin/health.vue';
import adminUserDetail from '@/views/admin/userDetail.vue';
import adminAppearance from '@/views/admin/appearance.vue';
import security from '@/views/dashboard/security.vue';
import apiKeys from '@/views/dashboard/apiKeys.vue';
import webhooks from '@/views/dashboard/webhooks.vue';
import team from '@/views/dashboard/team.vue';
import acceptInvite from '@/views/authentication/acceptInvite.vue';

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        component: PublicLayout,
        children: [
            { path: '', name: 'home', component: home },
            { path: 'terms', name: 'terms', component: terms },
            { path: 'privacy', name: 'privacy', component: privacy },
            { path: 'support', name: 'support', component: support },
            ...appConfig.routes.extraPublicRoutes,
        ],
    },
    // Telas de entrada: tela cheia, com a moldura própria (AuthShell), fora do
    // layout público.
    { path: '/login', name: 'login', component: login, meta: { guest: true } },
    { path: '/register', name: 'register', component: register, meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: forgotPassword, meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: resetPassword, meta: { guest: true } },
    // Convite para a equipe: público (a pessoa ainda não tem conta).
    { path: '/invite/:token', name: 'accept-invite', component: acceptInvite },
    {
        path: '/dashboard',
        component: DashboardLayout,
        meta: { requiresAuth: true },
        children: [
            { path: '', name: 'dashboard', component: dashboard },
            { path: 'profile', name: 'dashboard.profile', component: profile, meta: { teamRole: 'agent' } },
            { path: 'requests', name: 'dashboard.requests', component: requests },
            { path: 'plans', name: 'dashboard.plans', component: plans, meta: { teamRole: 'owner' } },
            { path: 'billing', name: 'dashboard.billing', component: billing, meta: { teamRole: 'owner' } },
            { path: 'security', name: 'dashboard.security', component: security, meta: { teamRole: 'agent' } },
            { path: 'team', name: 'dashboard.team', component: team },
            { path: 'api-keys', name: 'dashboard.api-keys', component: apiKeys },
            { path: 'webhooks', name: 'dashboard.webhooks', component: webhooks },
            // Admin
            { path: 'admin/metrics', name: 'admin.metrics', component: adminMetrics, meta: { requiresAdmin: true } },
            { path: 'admin/audit',   name: 'admin.audit',   component: adminAudit,   meta: { requiresAdmin: true } },
            { path: 'admin/plans',   name: 'admin.plans',   component: adminPlans,   meta: { requiresAdmin: true, ability: 'manage' } },
            { path: 'admin/coupons', name: 'admin.coupons', component: adminCoupons, meta: { requiresAdmin: true, ability: 'manage' } },
            { path: 'admin/users',   name: 'admin.users',   component: adminUsers,   meta: { requiresAdmin: true } },
            { path: 'admin/refunds', name: 'admin.refunds', component: adminRefunds, meta: { requiresAdmin: true } },
            { path: 'admin/logs',    name: 'admin.logs',    component: adminLogs,    meta: { requiresAdmin: true } },
            { path: 'admin/users/:id', name: 'admin.user', component: adminUserDetail, meta: { requiresAdmin: true } },
            { path: 'admin/billing', name: 'admin.billing', component: adminBilling, meta: { requiresAdmin: true } },
            { path: 'admin/delinquency', name: 'admin.delinquency', component: adminDelinquency, meta: { requiresAdmin: true } },
            { path: 'admin/health', name: 'admin.health', component: adminHealth, meta: { requiresAdmin: true } },
            { path: 'admin/gateways', name: 'admin.gateways', component: adminGateways, meta: { requiresAdmin: true, ability: 'manage' } },
            { path: 'admin/appearance', name: 'admin.appearance', component: adminAppearance, meta: { requiresAdmin: true, ability: 'manage' } },
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

    if (to.meta.requiresAdmin && !authStore.isStaff) {
        return next({ name: 'dashboard' });
    }

    if (to.meta.ability && !authStore.can(String(to.meta.ability))) {
        return next({ name: 'admin.users' });
    }

    // Equipe: cada tela do painel declara o papel mínimo em `meta.teamRole`
    // (padrão admin). Quem não alcança vai para a tela inicial da equipe
    // (`app.teamHome` em api-key.config.ts). A regra vale no servidor; aqui só
    // evita abrir uma tela que quebraria nas chamadas recusadas.
    if (to.meta.requiresAuth && authStore.isAuthenticated && to.path.startsWith('/dashboard')) {
        const required = (to.meta.teamRole as TeamRole | undefined) ?? 'admin';
        const home = appConfig.app.teamHome ?? '/dashboard/profile';

        if (!authStore.teamCan(required) && to.path !== home) {
            return next(home);
        }
    }

    if (to.meta.guest && authStore.isAuthenticated) {
        return next({ name: 'dashboard' });
    }

    next();
});

export default router;
