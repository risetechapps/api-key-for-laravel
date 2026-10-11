<template>
    <div>
        <PageHeader title="Inadimplência" subtitle="Dinheiro a recuperar: renovações recusadas, renovações esperando o assinante e excedente não pago" :icon="PhWarningCircle" />

        <div class="ui-stagger grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard v-for="(s, i) in sections" :key="s.key" :style="{ '--i': i }" :label="s.title" :value="d?.totals?.[s.key] ?? 0" format="money" :digits="2" :icon="s.icon" :tone="s.tone" :loading="loading" :hint="`${d?.[s.key]?.length ?? 0} assinatura(s)`" />
        </div>

        <Tabs v-model="tab" class="mt-8" :tabs="tabs">
            <template #default="{ active }">
                <Card :subtitle="current(active).help">
                    <DataTable :columns="columns" :rows="d?.[active] ?? []" :loading="loading" row-key="user_plan_id" clickable empty-title="Nada a recuperar aqui" :empty-icon="PhConfetti" @row-click="(r) => router.push(`/dashboard/admin/users/${r.user_id}`)">
                        <template #cell-user="{ row }">
                            <span class="flex items-center gap-2.5">
                                <Avatar :name="row.user_name" size="xs" />
                                <span class="min-w-0"><span class="block truncate font-medium text-slate-900 dark:text-white">{{ row.user_name }}</span><span class="block truncate text-xs text-slate-500">{{ row.user_email }}</span></span>
                            </span>
                        </template>
                        <template #cell-amount="{ row }"><span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ format.money(row.amount) }}</span></template>
                        <template #cell-end_date="{ row }"><span class="whitespace-nowrap">{{ format.date(row.end_date) }}</span></template>
                        <template #cell-situation="{ row }"><Badge :tone="current(active).tone">{{ situation(active, row) }}</Badge></template>
                    </DataTable>
                </Card>
            </template>
        </Tabs>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { PhWarningCircle, PhArrowsClockwise, PhHourglassMedium, PhChartBar, PhConfetti } from '@phosphor-icons/vue';
import { Avatar, Badge, Card, DataTable, PageHeader, StatCard, Tabs, errorMessage, format, toast } from '@/ui';
import type { Column, TabItem } from '@/ui';

const router = useRouter();
const d = ref<any>(null);
const loading = ref(true);
const tab = ref('renewals_retrying');

const sections = [
    { key: 'renewals_retrying', title: 'Em nova tentativa', help: 'Cartão recusado: o pacote tenta de novo nas datas configuradas.', icon: PhArrowsClockwise, tone: 'warning' as const },
    { key: 'renewals_awaiting_payment', title: 'Esperando o assinante', help: 'Sem cobrança automática possível: o assinante precisa renovar pelo painel.', icon: PhHourglassMedium, tone: 'info' as const },
    { key: 'unpaid_overage', title: 'Excedente não pago', help: 'Períodos encerrados com excedente faturado e ainda em aberto.', icon: PhChartBar, tone: 'danger' as const },
];

const tabs = computed<TabItem[]>(() => sections.map((s) => ({ key: s.key, label: s.title, icon: s.icon, count: d.value?.[s.key]?.length ?? null })));

const columns: Column[] = [
    { key: 'user', label: 'Cliente' },
    { key: 'plan', label: 'Plano' },
    { key: 'amount', label: 'Valor', align: 'right', sortable: true },
    { key: 'end_date', label: 'Vencimento', sortable: true },
    { key: 'situation', label: 'Situação' },
];

const reasons: Record<string, string> = { cvv_required: 'Cartão exige CVV', no_card: 'Sem cartão', card_out_of_use: 'Cartão de outro gateway', pix: 'Paga com PIX' };

function current(key: string) {
    return sections.find((s) => s.key === key) ?? sections[0]!;
}

function situation(key: string, r: any): string {
    if (key === 'renewals_retrying') return `${r.renewal_attempts}ª tentativa · próxima ${format.date(r.next_renewal_attempt_at)}`;
    if (key === 'renewals_awaiting_payment') return reasons[r.reason] ?? r.reason;
    return 'Em aberto';
}

onMounted(async () => {
    try {
        const r = await axios.get('/dashboard/admin/delinquency');
        d.value = r.data?.data;
    } catch (err) {
        toast.error('Não foi possível carregar', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
});
</script>
