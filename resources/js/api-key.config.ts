import type { RouteRecordRaw } from 'vue-router';
import type { Component } from 'vue';

export interface MenuItem {
    name: string;
    to: string;
    icon: Component;
}

export interface ApiKeyConfig {
    app: {
        logoText: string;
        logoHref: string;
        logoImage?: string;
    };
    menu: {
        extraItems: MenuItem[];
        extraAdminItems: MenuItem[];
    };
    routes: {
        extraPublicRoutes: RouteRecordRaw[];
        extraDashboardRoutes: RouteRecordRaw[];
    };
}

const config: ApiKeyConfig = {
    app: {
        logoText: (import.meta.env.VITE_APP_NAME as string) || 'Dashboard',
        logoHref: '/',
        // logoImage: '/images/logo.svg', // Descomente para usar imagem no lugar do ícone padrão
    },

    menu: {
        // Itens adicionados ao menu principal (usuário autenticado)
        // Exemplo: { name: 'Minha Página', to: '/dashboard/minha-pagina', icon: PhHouse }
        extraItems: [],

        // Itens adicionados à seção "Administração" do menu (só visível para admins)
        extraAdminItems: [],
    },

    routes: {
        // Rotas adicionadas ao layout público (/, /login, etc.)
        extraPublicRoutes: [],

        // Rotas adicionadas ao layout do dashboard (/dashboard/*)
        // Exemplo: { path: 'minha-pagina', name: 'dashboard.minha-pagina', component: () => import('@/views/dashboard/minha-pagina.vue') }
        extraDashboardRoutes: [],
    },
};

export default config;
