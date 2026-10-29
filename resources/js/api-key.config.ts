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
    /** Cartões do app nas abas do perfil. Ver `ProfileCard`. */
    profile?: {
        extraCards?: ProfileCard[];
    };
}

/**
 * Abas do perfil que aceitam cartões extras. `notifications` só aparece para o
 * dono da conta. `key` é obsoleto: a aba da API key saiu do perfil e o cartão
 * vai para `personal`.
 */
export type ProfileTab = 'personal' | 'address' | 'key' | 'notifications' | 'privacy';

/**
 * Cartão extra do perfil: o componente (vai para a aba "Dados pessoais")
 * ou `{ component, tab }` para escolher a aba. Os cartões entram depois dos do
 * pacote, na ordem da lista.
 */
export type ProfileCard = Component | { component: Component; tab?: ProfileTab };

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

    // Cartões extras no perfil (opcional). Só o componente vai para a aba "Dados pessoais".
    // Exemplo: profile: { extraCards: [defineAsyncComponent(() => import('@/views/MeuCard.vue')), { component: OutroCard, tab: 'privacy' }] },
};

export default config;
