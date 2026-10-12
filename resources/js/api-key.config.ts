import type { RouteRecordRaw } from 'vue-router';
import type { Component } from 'vue';
import type { Command } from '@/ui/commands';

export interface MenuItem {
    name: string;
    to: string;
    icon: Component;
    /** Permissão do admin exigida para ver o item: 'view', 'accounts', 'billing' ou 'manage'. */
    ability?: string;
    /** Cabeçalho de seção próprio no menu (ex.: 'Relatórios'). */
    section?: string;
    /** Selo ao lado do nome (ex.: 'Novo', '3'). */
    badge?: string | number;
}

export interface ApiKeyConfig {
    app: {
        logoText: string;
        logoHref: string;
        logoImage?: string;
        /**
         * Tela inicial de quem é da equipe e não pode ver a tela pedida (ex.:
         * atendente). Padrão: /dashboard/profile. O papel mínimo de cada tela
         * fica em `meta.teamRole` da rota: 'agent', 'admin' (padrão) ou 'owner'.
         */
        teamHome?: string;
    };
    menu: {
        extraItems: MenuItem[];
        extraAdminItems: MenuItem[];
    };
    routes: {
        extraPublicRoutes: RouteRecordRaw[];
        extraDashboardRoutes: RouteRecordRaw[];
    };
    /** Comandos extras da busca rápida (Ctrl+K). Ver `registerCommands` em `@/ui`. */
    commands?: Command[];
    /** Texto do painel da marca nas telas de login e cadastro. */
    auth?: {
        headline?: string;
        bullets?: string[];
    };
}

const config: ApiKeyConfig = {
    app: {
        //@ts-ignore
        logoText:  'Dashboard',
        logoHref: '/',
        // logoImage: '/images/logo.svg', // Descomente para usar imagem no lugar do ícone padrão
    },

    menu: {
        // Itens adicionados ao menu principal (usuário autenticado)
        // Exemplo: { name: 'Minha Página', to: '/dashboard/minha-pagina', icon: PhHouse, section: 'Meu módulo', badge: 'Novo' }
        extraItems: [],

        // Itens adicionados à seção "Administração" do menu (só para a equipe).
        // Use `ability` para restringir: { name: 'Relatórios', to: '/dashboard/admin/relatorios', icon: PhChart, ability: 'billing' }
        extraAdminItems: [],
    },

    routes: {
        // Rotas adicionadas ao layout público (/, /login, etc.)
        extraPublicRoutes: [],

        // Rotas adicionadas ao layout do dashboard (/dashboard/*)
        // Exemplo: { path: 'minha-pagina', name: 'dashboard.minha-pagina', component: () => import('@/views/dashboard/minha-pagina.vue'), meta: { title: 'Minha página' } }
        extraDashboardRoutes: [],
    },

    // Comandos extras da busca rápida (Ctrl+K)
    // Exemplo: { id: 'relatorios.novo', label: 'Novo relatório', group: 'Relatórios', to: '/dashboard/relatorios/novo' }
    commands: [],

    // Painel da marca no login e no cadastro (opcional)
    // auth: { headline: 'A API de dados mais rápida do Brasil.', bullets: ['…', '…'] },
};

export default config;
