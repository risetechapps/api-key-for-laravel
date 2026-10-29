import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import router from '@/router';
import { useAuthStore } from '@/stores/auth';

function signIn(user: Record<string, unknown>) {
    const auth = useAuthStore();
    auth.token = 'token';
    auth.user = { email: 'admin@example.com', role: 'admin', admin_abilities: ['view', 'accounts', 'billing', 'manage'], ...user } as any;
}

describe('guarda de 2FA nas telas de admin', () => {
    beforeEach(async () => {
        // O jsdom não implementa a rolagem que o scrollBehavior do router pede.
        window.scrollTo = (() => {}) as typeof window.scrollTo;
        setActivePinia(createPinia());
        await router.push('/');
    });

    it('admin sem 2FA vai para a segurança antes de a tela de admin abrir', async () => {
        signIn({ two_factor_required: true });

        await router.push('/dashboard/admin/users');

        expect(router.currentRoute.value.path).toBe('/dashboard/security');
        expect(router.currentRoute.value.query.two_factor_required).toBe('1');
    });

    it('admin com 2FA entra normalmente', async () => {
        signIn({ two_factor_required: false });

        await router.push('/dashboard/admin/users');

        expect(router.currentRoute.value.path).toBe('/dashboard/admin/users');
    });
});
