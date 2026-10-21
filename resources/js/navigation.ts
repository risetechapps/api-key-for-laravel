import { computed } from 'vue';
import type { Component } from 'vue';
import {
    PhHouse, PhUser, PhShieldCheck, PhKey, PhPlugsConnected, PhClockCounterClockwise, PhCreditCard, PhReceipt,
    PhChartLineUp, PhUsersThree, PhWarningCircle, PhArrowCounterClockwise, PhTag, PhWallet, PhHeartbeat,
    PhFileText, PhListChecks, PhPaintBrush, PhUsersFour,
} from '@phosphor-icons/vue';
import appConfig from '@/api-key.config';
import type { MenuItem } from '@/api-key.config';
import { useAuthStore } from '@/stores/auth';
import type { TeamRole } from '@/stores/auth';
import { useRouter } from 'vue-router';

/**
 * Menu do painel, usado pelo menu lateral e pela busca rápida.
 *
 * Itens extras vêm de `api-key.config.ts` (`menu.extraItems` e
 * `menu.extraAdminItems`) e aceitam `ability` (só aparecem para quem tem a
 * permissão), `section` (cabeçalho próprio) e `badge`.
 */
export interface NavItem extends MenuItem {
    exact?: boolean;
}

export interface NavSection {
    title: string | null;
    items: NavItem[];
}

const main: NavItem[] = [
    { name: 'Visão geral', to: '/dashboard', icon: PhHouse, exact: true },
    { name: 'API Keys', to: '/dashboard/api-keys', icon: PhKey },
    { name: 'Requisições', to: '/dashboard/requests', icon: PhClockCounterClockwise },
    { name: 'Webhooks', to: '/dashboard/webhooks', icon: PhPlugsConnected },
    { name: 'Planos', to: '/dashboard/plans', icon: PhCreditCard },
    { name: 'Faturamento', to: '/dashboard/billing', icon: PhReceipt },
];

const account: NavItem[] = [
    { name: 'Perfil', to: '/dashboard/profile', icon: PhUser },
    { name: 'Segurança', to: '/dashboard/security', icon: PhShieldCheck },
    { name: 'Equipe', to: '/dashboard/team', icon: PhUsersFour },
];

const admin: NavItem[] = [
    { name: 'Métricas', to: '/dashboard/admin/metrics', icon: PhChartLineUp, ability: 'view' },
    { name: 'Usuários', to: '/dashboard/admin/users', icon: PhUsersThree, ability: 'view' },
    { name: 'Cobranças', to: '/dashboard/admin/billing', icon: PhReceipt, ability: 'view' },
    { name: 'Inadimplência', to: '/dashboard/admin/delinquency', icon: PhWarningCircle, ability: 'view' },
    { name: 'Estornos', to: '/dashboard/admin/refunds', icon: PhArrowCounterClockwise, ability: 'view' },
    { name: 'Planos', to: '/dashboard/admin/plans', icon: PhCreditCard, ability: 'manage' },
    { name: 'Cupons', to: '/dashboard/admin/coupons', icon: PhTag, ability: 'manage' },
    { name: 'Gateways', to: '/dashboard/admin/gateways', icon: PhWallet, ability: 'manage' },
    { name: 'Aparência', to: '/dashboard/admin/appearance', icon: PhPaintBrush, ability: 'manage' },
    { name: 'Saúde', to: '/dashboard/admin/health', icon: PhHeartbeat, ability: 'view' },
    { name: 'Logs', to: '/dashboard/admin/logs', icon: PhFileText, ability: 'view' },
    { name: 'Auditoria', to: '/dashboard/admin/audit', icon: PhListChecks, ability: 'view' },
];

function group(items: NavItem[], fallback: string | null): NavSection[] {
    const sections = new Map<string | null, NavItem[]>();
    for (const item of items) {
        const key = item.section ?? fallback;
        if (!sections.has(key)) sections.set(key, []);
        sections.get(key)!.push(item);
    }
    return [...sections.entries()].map(([title, list]) => ({ title, items: list }));
}

export function useNavigation() {
    const auth = useAuthStore();
    const router = useRouter();

    // Equipe: o papel mínimo mora no `meta.teamRole` da rota (padrão admin para
    // as telas do painel), e o menu o consulta. Ver o guard em router/index.ts.
    const teamRole = (to: string) => (router.resolve(to).meta?.teamRole as TeamRole | undefined) ?? 'admin';

    const allowed = (item: NavItem) => (!item.ability || auth.can(item.ability)) && auth.teamCan(teamRole(item.to));

    const sections = computed<NavSection[]>(() => {
        const out: NavSection[] = [
            ...group([...main, ...appConfig.menu.extraItems].filter(allowed), null),
            { title: 'Conta', items: account.filter(allowed) },
        ];

        if (auth.isStaff) {
            out.push(...group([...admin, ...appConfig.menu.extraAdminItems].filter(allowed), 'Administração'));
        }

        return out.filter((s) => s.items.length);
    });

    return { sections };
}

function matchesPath(current: string, item: NavItem): boolean {
    if (item.exact) return current === item.to;
    return current === item.to || current.startsWith(`${item.to}/`);
}

/**
 * Item ativo para a página atual. Quando dois itens casam (ex.: `/envios` e
 * `/envios/remetentes`), só o mais específico fica marcado; antes os dois
 * acendiam juntos.
 */
export function isActivePath(current: string, item: NavItem): boolean {
    if (!matchesPath(current, item)) return false;

    const all: NavItem[] = [...main, ...account, ...admin, ...appConfig.menu.extraItems, ...appConfig.menu.extraAdminItems];

    return !all.some((other) => other.to !== item.to && other.to.length > item.to.length && matchesPath(current, other));
}

export type { Component };
