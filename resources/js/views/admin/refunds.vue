<template>
    <div>
        <PageHeader title="Estornos" subtitle="Compras com pagamento registrado, de todos os gateways. O estorno devolve o valor e encerra a assinatura." :icon="PhArrowCounterClockwise" />

        <Card>
            <DataTable :columns="columns" :rows="rows" :loading="loading" empty-title="Nenhuma compra com pagamento registrado" :empty-icon="PhReceipt">
                <template #cell-user="{ row }">
                    <span class="flex items-center gap-2.5">
                        <Avatar :name="row.user?.name" size="xs" />
                        <span class="min-w-0"><span class="block truncate font-medium text-slate-900 dark:text-white">{{ row.user?.name }}</span><span class="block truncate text-xs text-slate-500">{{ row.user?.email }}</span></span>
                    </span>
                </template>
                <template #cell-plan="{ row }"><span class="font-medium text-slate-900 dark:text-white">{{ row.plan?.name }}</span></template>
                <template #cell-amount="{ row }"><span class="font-semibold tabular-nums">{{ format.money(row.payment_amount) }}</span></template>
                <template #cell-period="{ row }"><span class="whitespace-nowrap text-slate-500">{{ format.date(row.start_date) }} – {{ format.date(row.end_date) }}</span></template>
                <template #cell-status="{ row }"><Badge :tone="row.refunded_at ? 'danger' : row.active ? 'success' : 'neutral'" dot>{{ row.refunded_at ? 'Estornada' : row.active ? 'Ativa' : 'Encerrada' }}</Badge></template>
                <template #cell-payment_id="{ row }"><span class="font-mono text-xs text-slate-500">{{ row.payment_id }}</span></template>
                <template #cell-actions="{ row }">
                    <Button v-if="row.active && !row.refunded_at && authStore.can('billing')" variant="outline" size="xs" :icon="PhArrowCounterClockwise" class="!text-red-600" @click="refund(row)">Estornar</Button>
                </template>
            </DataTable>

            <Pagination :page="page" :last-page="totalPages" :total="total" label="compras" @change="changePage" />
        </Card>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { PhArrowCounterClockwise, PhReceipt } from '@phosphor-icons/vue';
import { useAdminStore } from '@/stores/admin';
import { useAuthStore } from '@/stores/auth';
import { Avatar, Badge, Button, Card, DataTable, PageHeader, Pagination, confirm, format, toast } from '@/ui';
import type { Column } from '@/ui';

const authStore = useAuthStore();
const adminStore = useAdminStore();

const loading = computed(() => adminStore.loading);
const rows = computed<any[]>(() => adminStore.refunds?.data ?? []);
const total = computed<number>(() => adminStore.refunds?.total ?? 0);
const totalPages = computed<number>(() => adminStore.refunds?.last_page ?? 1);
const page = ref(1);

const columns: Column[] = [
    { key: 'user', label: 'Cliente' },
    { key: 'plan', label: 'Plano' },
    { key: 'amount', label: 'Valor', align: 'right' },
    { key: 'period', label: 'Período' },
    { key: 'status', label: 'Status' },
    { key: 'payment_id', label: 'Pagamento' },
    { key: 'actions', label: '', align: 'right' },
];

onMounted(() => adminStore.fetchRefunds({ page: page.value }));

function changePage(p: number) {
    page.value = p;
    adminStore.fetchRefunds({ page: p });
}

async function refund(sub: any) {
    const ok = await confirm({
        title: `Estornar ${format.money(sub.payment_amount)}?`,
        description: `Devolve o pagamento ${sub.payment_id} de ${sub.user?.name ?? 'cliente'} e encerra a assinatura na hora.`,
        tone: 'danger',
        confirmText: 'Estornar',
        onConfirm: async () => {
            await adminStore.processRefund(sub.id);
        },
    });
    if (ok) toast.success('Estorno realizado', { description: 'O valor foi devolvido e a assinatura encerrada.' });
}
</script>
