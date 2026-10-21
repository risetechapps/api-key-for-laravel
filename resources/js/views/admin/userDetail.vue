<template>
    <div>
        <PageHeader :breadcrumbs="[{ label: 'Usuários', to: '/dashboard/admin/users' }, { label: d?.user.name ?? '…' }]">
            <template #title>
                <span class="flex items-center gap-3">
                    <Avatar v-if="d" :name="d.user.name" size="md" />
                    {{ d?.user.name ?? 'Carregando…' }}
                </span>
            </template>
            <template #subtitle>{{ d?.user.email }}<span v-if="d?.user.pending_email"> · trocando para {{ d.user.pending_email }}</span></template>
            <template v-if="d" #actions>
                <Dropdown :items="accountActions" align="right">
                    <template #trigger>
                        <Button variant="outline" :icon-right="PhCaretDown">Ações da conta</Button>
                    </template>
                </Dropdown>
            </template>
        </PageHeader>

        <div v-if="loading" class="space-y-4"><Skeleton height="6rem" rounded="rounded-2xl" /><Skeleton height="16rem" rounded="rounded-2xl" /></div>

        <template v-else-if="d">
            <div class="mb-6 flex flex-wrap gap-2">
                <Badge :tone="roleLabels[role] ? 'brand' : 'neutral'" :icon="PhShieldStar">{{ roleLabels[role] ?? 'Cliente' }}</Badge>
                <Badge :tone="enabled ? 'success' : 'danger'" dot>{{ enabled ? 'Ativo' : 'Suspenso' }}</Badge>
                <Badge :tone="d.user.email_verified ? 'success' : 'warning'">{{ d.user.email_verified ? 'E-mail verificado' : 'E-mail não verificado' }}</Badge>
                <Badge :tone="d.user.two_factor_enabled ? 'success' : 'neutral'">2FA {{ d.user.two_factor_enabled ? 'ativa' : 'desligada' }}</Badge>
                <Badge v-if="d.user.cpf" tone="neutral">CPF {{ d.user.cpf }}</Badge>
                <Badge tone="neutral">Desde {{ format.date(d.user.created_at) }}</Badge>
            </div>

            <!-- Resumo -->
            <div class="ui-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard style="--i: 0" label="Plano" :value="d.current?.plan ?? 'Sem plano'" :icon="PhCrown" :hint="d.current ? `vence ${format.date(d.current.end_date)}` : ''" />
                <StatCard style="--i: 1" label="Consumo" :value="d.current?.requests_used ?? 0" :icon="PhGauge" tone="info" :hint="d.current ? `de ${d.current.request_limit ?? '∞'}` : ''" />
                <StatCard style="--i: 2" label="Renovação" :value="renewalState.text" :icon="PhArrowsClockwise" :tone="renewalState.tone" />
                <StatCard style="--i: 3" label="Total pago" :value="totalPaid" format="money" :digits="2" :icon="PhCoins" tone="success" :hint="`${paidCount} pagamento(s)`" />
            </div>

            <Tabs v-model="tab" class="mt-8" :tabs="tabs">
                <template #default="{ active }">
                    <!-- Assinatura -->
                    <div v-if="active === 'subscription'" class="space-y-6">
                        <Card v-if="d.current" title="Assinatura atual" :subtitle="`${d.current.plan}${d.current.is_trial ? ' · teste' : ''}`">
                            <template v-if="authStore.can('billing')" #actions>
                                <Button variant="outline" size="sm" :icon="PhCalendarPlus" @click="extend(d.current)">Estender</Button>
                                <Button variant="outline" size="sm" :icon="PhSliders" @click="adjustUsage(d.current)">Ajustar consumo</Button>
                            </template>
                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <Progress :value="d.current.requests_used" :max="d.current.request_limit || 1" label="Consumo do período" show-value />
                                    <p class="mt-2 text-xs text-slate-500">{{ Number(d.current.requests_used).toLocaleString('pt-BR') }} de {{ d.current.request_limit ? Number(d.current.request_limit).toLocaleString('pt-BR') : 'ilimitado' }} requisições</p>
                                </div>
                                <dl class="grid grid-cols-2 gap-3 text-sm">
                                    <div><dt class="text-xs text-slate-500">Início</dt><dd class="font-medium text-slate-900 dark:text-white">{{ format.date(d.current.start_date) }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">Vencimento</dt><dd class="font-medium text-slate-900 dark:text-white">{{ format.date(d.current.end_date) }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">Forma</dt><dd class="font-medium text-slate-900 dark:text-white">{{ methodLabel(d.current.payment_method) }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">Pagamento</dt><dd class="truncate font-mono text-xs text-slate-900 dark:text-white">{{ d.current.payment_id ?? 'cortesia' }}</dd></div>
                                </dl>
                            </div>
                            <template v-if="authStore.can('billing') && (d.current.renewal_attempts > 0 || d.current.renewal_requires_payment || !d.current.cancelled_at)" #footer>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button v-if="d.current.renewal_attempts > 0 || d.current.renewal_requires_payment" variant="soft" size="sm" :icon="PhArrowsClockwise" @click="sub(d.current, 'retry-renewal', { title: 'Cobrar a renovação agora?', description: 'A cobrança vai para a fila e sai em seguida, fora do calendário de novas tentativas.' })">Tentar renovar agora</Button>
                                    <Button v-if="!d.current.cancelled_at" variant="ghost" size="sm" class="!text-red-600" @click="sub(d.current, 'cancel', { title: 'Cancelar a renovação?', description: 'O acesso segue até o fim do período pago. Para devolver o dinheiro, use o estorno.', tone: 'danger', confirmText: 'Cancelar renovação' })">Cancelar renovação</Button>
                                </div>
                            </template>
                        </Card>
                        <Card v-else>
                            <EmptyState title="Sem assinatura ativa" description="Use “Conceder plano” na lista de usuários para dar um plano de cortesia." :icon="PhCrown" />
                        </Card>

                        <Card v-if="d.unpaid_overage.length" title="Excedente em aberto">
                            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                <li v-for="o in d.unpaid_overage" :key="o.id" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0 text-sm">
                                    <span class="text-slate-700 dark:text-slate-300">{{ o.plan }} · {{ o.requests }} requisições · até {{ format.date(o.period_end) }}</span>
                                    <span class="flex items-center gap-3">
                                        <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ format.money(o.amount) }}</span>
                                        <Button v-if="authStore.can('billing')" variant="outline" size="xs" @click="sub({ id: o.id }, 'waive-overage', { title: `Perdoar ${format.money(o.amount)}?`, description: 'O período sai da lista de excedentes a pagar. O valor original fica na auditoria.', tone: 'warning', confirmText: 'Perdoar' })">Perdoar</Button>
                                    </span>
                                </li>
                            </ul>
                        </Card>
                    </div>

                    <!-- Pagamentos -->
                    <Card v-else-if="active === 'payments'" :padding="true">
                        <DataTable :columns="paymentColumns" :rows="paymentRows" row-key="key" empty-title="Nenhum pagamento" :empty-icon="PhReceipt">
                            <template #cell-plan="{ row }"><span class="font-medium text-slate-900 dark:text-white">{{ row.plan }}</span></template>
                            <template #cell-period="{ row }"><span class="whitespace-nowrap text-slate-500">{{ row.period }}</span></template>
                            <template #cell-amount="{ row }"><span class="tabular-nums">{{ row.amount !== null ? format.money(row.amount) : '—' }}</span></template>
                            <template #cell-payment_id="{ row }"><span class="font-mono text-xs text-slate-500">{{ row.payment_id ?? '—' }}</span></template>
                            <template #cell-state="{ row }"><Badge :tone="row.tone" dot>{{ row.state }}</Badge></template>
                        </DataTable>
                    </Card>

                    <!-- Cartões e chaves -->
                    <div v-else-if="active === 'access'" class="grid gap-6 lg:grid-cols-2">
                        <Card title="Cartões">
                            <ul v-if="d.cards.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                                <li v-for="c in d.cards" :key="c.id" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0 text-sm">
                                    <span class="flex items-center gap-3">
                                        <span class="flex h-8 w-12 items-center justify-center rounded-md bg-gradient-to-br from-slate-700 to-slate-900 text-[9px] font-bold uppercase italic text-white">{{ c.brand }}</span>
                                        <span><span class="font-medium text-slate-900 dark:text-white">•••• {{ c.last_four }}</span><span class="block text-xs text-slate-500">{{ c.expiry }} · {{ gatewayName(c.gateway) }}</span></span>
                                    </span>
                                    <span class="flex flex-wrap justify-end gap-1">
                                        <Badge v-if="c.is_default" tone="brand">Padrão</Badge>
                                        <Badge :tone="c.automatic_billing ? 'success' : 'neutral'">{{ c.automatic_billing ? 'Renova sozinho' : 'Pede CVV' }}</Badge>
                                    </span>
                                </li>
                            </ul>
                            <EmptyState v-else compact title="Nenhum cartão salvo" :icon="PhCreditCard" />
                        </Card>
                        <Card title="API keys">
                            <ul v-if="d.api_keys.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                                <li v-for="k in d.api_keys" :key="k.id" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0 text-sm">
                                    <span><span class="font-medium text-slate-900 dark:text-white">{{ k.name || 'Sem nome' }}</span><span class="block text-xs text-slate-500">último uso {{ k.last_used_at ? format.relative(k.last_used_at) : 'nunca' }}</span></span>
                                    <Badge :tone="k.active ? 'success' : 'neutral'" dot>{{ k.active ? 'Ativa' : 'Inativa' }}</Badge>
                                </li>
                            </ul>
                            <EmptyState v-else compact title="Nenhuma API key" :icon="PhKey" />
                        </Card>
                        <Card title="Sessões abertas" class="lg:col-span-2">
                            <ul v-if="d.sessions.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                                <li v-for="s in d.sessions" :key="s.id" class="py-3 first:pt-0 last:pb-0 text-sm">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ s.ip ?? 'IP desconhecido' }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ s.user_agent ?? '—' }} · {{ s.last_used_at ? format.relative(s.last_used_at) : 'nunca usada' }}</p>
                                </li>
                            </ul>
                            <EmptyState v-else compact title="Nenhuma sessão aberta" :icon="PhDesktop" />
                        </Card>
                    </div>

                    <!-- Histórico -->
                    <Card v-else title="Histórico" subtitle="Ações da conta e da equipe sobre ela">
                        <ol v-if="d.audit.length" class="relative space-y-5 border-l border-slate-200 pl-6 dark:border-slate-800">
                            <li v-for="(a, i) in d.audit" :key="i" class="relative">
                                <span class="absolute -left-[31px] top-1 h-3 w-3 rounded-full border-2 border-white bg-brand-500 dark:border-slate-900" />
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ auditLabels[a.action] ?? a.action }}</p>
                                <p class="text-xs text-slate-500">{{ format.date(a.created_at, true) }}<span v-if="a.actor"> · por {{ a.actor }}</span></p>
                            </li>
                        </ol>
                        <EmptyState v-else compact title="Sem registros" :icon="PhListChecks" />
                    </Card>
                </template>
            </Tabs>
        </template>
    </div>
</template>

<script setup lang="ts">
import { gatewayLabel } from '@/utils/gateways';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import {
    PhCaretDown, PhShieldStar, PhCrown, PhGauge, PhArrowsClockwise, PhCoins, PhCalendarPlus, PhSliders, PhReceipt, PhCreditCard,
    PhKey, PhDesktop, PhListChecks, PhEnvelopeSimple, PhSignOut, PhPlugs, PhShieldSlash, PhUserGear, PhIdentificationBadge,
} from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { Avatar, Badge, Button, Card, DataTable, Dropdown, EmptyState, PageHeader, Progress, Skeleton, StatCard, Tabs, confirm, errorMessage, format, prompt, toast } from '@/ui';
import type { BadgeTone, Column, DialogOptions, DropdownItem, TabItem } from '@/ui';

const route = useRoute();
const authStore = useAuthStore();
const id = String(route.params.id);

const d = ref<any>(null);
const loading = ref(true);
const tab = ref('subscription');

const roleLabels: Record<string, string> = { admin: 'Admin', finance: 'Financeiro', support: 'Suporte' };
const auditLabels: Record<string, string> = {
    'admin.user_status_changed': 'Status alterado', 'admin.plan_granted': 'Plano concedido', 'admin.refund_processed': 'Estorno',
    'admin.subscription_cancelled': 'Renovação cancelada pela equipe', 'admin.subscription_extended': 'Vencimento estendido',
    'admin.usage_adjusted': 'Consumo ajustado', 'admin.renewal_retried': 'Nova tentativa de renovação', 'admin.overage_waived': 'Excedente perdoado',
    'admin.verification_resent': 'Verificação reenviada', 'admin.sessions_revoked': 'Sessões encerradas pela equipe',
    'admin.api_keys_revoked': 'API keys desativadas pela equipe', 'admin.two_factor_reset': '2FA desligada pela equipe', 'admin.role_changed': 'Papel alterado',
};

const role = computed(() => String(d.value?.user.role ?? '').toLowerCase());
const enabled = computed(() => !d.value?.user.status || d.value.user.status === 'enabled');

const tabs = computed<TabItem[]>(() => [
    { key: 'subscription', label: 'Assinatura', icon: PhCrown },
    { key: 'payments', label: 'Pagamentos', icon: PhReceipt, count: (d.value?.subscriptions.length ?? 0) + (d.value?.pending_payments.length ?? 0) || null },
    { key: 'access', label: 'Cartões e acesso', icon: PhKey },
    { key: 'history', label: 'Histórico', icon: PhListChecks },
]);

const renewalState = computed<{ text: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }>(() => {
    const c = d.value?.current;
    if (!c) return { text: '—', tone: 'neutral' };
    if (c.cancelled_at) return { text: 'Cancelada', tone: 'neutral' };
    if (c.renewal_requires_payment) return { text: 'Espera pagamento', tone: 'warning' };
    if (c.renewal_failed_at) return { text: 'Tentativas esgotadas', tone: 'danger' };
    if (c.renewal_attempts > 0) return { text: `Recusada · ${c.renewal_attempts}ª tentativa`, tone: 'warning' };
    return { text: 'Automática', tone: 'success' };
});

const totalPaid = computed(() => (d.value?.subscriptions ?? []).filter((s: any) => !s.refunded_at).reduce((acc: number, s: any) => acc + Number(s.payment_amount ?? 0), 0));
const paidCount = computed(() => (d.value?.subscriptions ?? []).filter((s: any) => s.payment_id).length);

const paymentColumns: Column[] = [
    { key: 'plan', label: 'Plano' },
    { key: 'period', label: 'Período' },
    { key: 'amount', label: 'Valor', align: 'right' },
    { key: 'payment_id', label: 'Pagamento' },
    { key: 'state', label: 'Situação' },
];

const paymentRows = computed(() => [
    ...(d.value?.pending_payments ?? []).map((p: any) => ({
        key: `p-${p.payment_id}`, plan: p.plan, period: format.date(p.created_at), amount: p.amount, payment_id: p.payment_id,
        state: p.outcome === 'rejected' ? 'Recusado' : p.outcome === 'approved' ? 'Aprovado' : 'Em análise',
        tone: (p.outcome === 'rejected' ? 'danger' : p.outcome === 'approved' ? 'success' : 'warning') as BadgeTone,
    })),
    ...(d.value?.subscriptions ?? []).map((s: any) => ({
        key: `s-${s.id}`, plan: s.plan, period: `${format.date(s.start_date)} – ${format.date(s.end_date)}`, amount: s.payment_amount, payment_id: s.payment_id,
        state: s.refunded_at ? 'Estornada' : s.active && !s.cancelled_at ? 'Ativa' : s.active ? 'Cancelada' : 'Encerrada',
        tone: (s.refunded_at ? 'danger' : s.active && !s.cancelled_at ? 'success' : 'neutral') as BadgeTone,
    })),
]);

const accountActions = computed<DropdownItem[]>(() => {
    const items: DropdownItem[] = [];
    if (authStore.can('accounts')) {
        if (!d.value?.user.email_verified) items.push({ label: 'Reenviar verificação de e-mail', icon: PhEnvelopeSimple, onClick: () => act('verification', { title: 'Reenviar o e-mail de verificação?' }) });
        items.push({ label: 'Encerrar todas as sessões', icon: PhSignOut, onClick: () => act('sessions/revoke', { title: 'Encerrar todas as sessões?', description: 'O usuário vai precisar entrar de novo em todos os dispositivos.' }) });
        items.push({ label: 'Desativar API keys', icon: PhPlugs, danger: true, onClick: () => act('api-keys/revoke', { title: 'Desativar todas as API keys?', description: 'As integrações do usuário param de funcionar na hora.', tone: 'danger', confirmText: 'Desativar' }) });
    }
    if (authStore.can('manage')) {
        if (items.length) items.push({ divider: true });
        if (d.value?.user.two_factor_enabled) {
            items.push({ label: 'Desligar 2FA', icon: PhShieldSlash, danger: true, onClick: () => act('two-factor/reset', { title: 'Desligar a verificação em duas etapas?', description: 'Faça isso só depois de confirmar a identidade do titular por outro canal. Ele é avisado por e-mail e as sessões caem.', tone: 'danger', confirmText: 'Desligar 2FA' }) });
        }
        items.push({ label: 'Alterar papel na equipe', icon: PhUserGear, onClick: changeRole });
    }
    if (!items.length) items.push({ label: 'Seu papel só permite consultar', icon: PhIdentificationBadge, disabled: true });
    return items;
});

async function load() {
    try {
        const r = await axios.get(`/dashboard/admin/users/${id}`);
        d.value = r.data?.data;
    } catch (err) {
        toast.error('Não foi possível carregar o usuário', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function gatewayName(g: string): string {
    return gatewayLabel(g);
}

function methodLabel(m: string | null): string {
    return m === 'pix' ? 'PIX' : m === 'card' ? 'Cartão' : '—';
}

// Confirma, chama a rota e mostra o resultado; erro aparece no diálogo.
async function run(options: DialogOptions, request: () => Promise<any>) {
    let message = '';
    const ok = await confirm({
        ...options,
        onConfirm: async () => {
            const r = await request();
            message = r.data?.data?.message ?? '';
        },
    });
    if (!ok) return;
    toast.success(message || 'Feito');
    await load();
}

function act(path: string, options: DialogOptions) {
    return run(options, () => axios.post(`/dashboard/admin/users/${id}/${path}`));
}

function sub(s: { id: string }, path: string, options: DialogOptions) {
    return run(options, () => axios.post(`/dashboard/admin/users/${id}/subscriptions/${s.id}/${path}`));
}

async function extend(s: any) {
    let message = '';
    const value = await prompt({
        title: 'Estender o vencimento',
        description: `Hoje vence em ${format.date(s.end_date)}. Uma renovação parada é zerada e recomeça no novo vencimento.`,
        confirmText: 'Estender',
        input: { type: 'number', label: 'Dias a acrescentar', value: 7, min: 1, max: 365 },
        onConfirm: async (days) => {
            const r = await axios.post(`/dashboard/admin/users/${id}/subscriptions/${s.id}/extend`, { days: Number(days) });
            message = r.data?.data?.message ?? '';
        },
    });
    if (value === null) return;
    toast.success(message || 'Vencimento estendido');
    await load();
}

async function adjustUsage(s: any) {
    const value = await prompt({
        title: 'Ajustar consumo do período',
        description: 'Use para devolver requisições de uma falha do serviço, por exemplo.',
        confirmText: 'Salvar',
        input: { type: 'number', label: 'Requisições usadas no período', value: s.requests_used, min: 0 },
        onConfirm: async (n) => {
            await axios.put(`/dashboard/admin/users/${id}/subscriptions/${s.id}/usage`, { requests_used: Number(n) });
        },
    });
    if (value === null) return;
    toast.success('Consumo ajustado');
    await load();
}

async function changeRole() {
    const value = await prompt({
        title: 'Papel na equipe',
        description: 'Quem perde permissões tem as sessões encerradas.',
        confirmText: 'Salvar',
        input: {
            type: 'select',
            label: 'Papel',
            value: role.value && roleLabels[role.value] ? role.value : 'client',
            options: {
                client: 'Cliente (sem acesso ao admin)',
                support: 'Suporte: vê tudo e faz ações de conta',
                finance: 'Financeiro: vê tudo, estornos e cobranças',
                admin: 'Admin: tudo, inclusive gateways e papéis',
            },
        },
        onConfirm: async (r) => {
            await axios.put(`/dashboard/admin/users/${id}/role`, { role: r });
        },
    });
    if (value === null) return;
    toast.success('Papel atualizado');
    await load();
}
</script>
