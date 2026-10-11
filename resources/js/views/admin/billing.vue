<template>
    <div>
        <PageHeader title="Cobranças" subtitle="Assinaturas, excedentes, compras em análise e recusadas, de todos os gateways" :icon="PhReceipt">
            <template v-if="authStore.can('billing')" #actions>
                <Dropdown :items="exportItems">
                    <template #trigger>
                        <Button variant="outline" :icon="PhDownloadSimple" :icon-right="PhCaretDown" :loading="!!exporting">Exportar CSV</Button>
                    </template>
                </Dropdown>
            </template>
        </PageHeader>

        <div class="ui-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard v-for="(tile, i) in tiles" :key="tile.status" :style="{ '--i': i }" :label="tile.label" :value="summary[tile.status]?.amount ?? 0" format="money" :digits="2" :icon="tile.icon" :tone="tile.tone" :loading="loading && !rows.length" :hint="`${summary[tile.status]?.count ?? 0} cobrança(s)`" />
        </div>

        <Card class="mt-6">
            <div class="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                <TextInput v-model="filters.search" class="xl:col-span-2" placeholder="Cliente, e-mail ou id do pagamento" :icon="PhMagnifyingGlass" @keyup.enter="load(1)" />
                <Select v-model="filters.status" placeholder="Todos os status" :options="statusLabel" />
                <Select v-model="filters.gateway" placeholder="Todos os gateways" :options="GATEWAY_LABELS" />
                <TextInput v-model="filters.from" type="date" aria-label="De" />
                <TextInput v-model="filters.to" type="date" aria-label="Até" />
            </div>

            <DataTable :columns="columns" :rows="rows" :loading="loading" row-key="key" clickable sort-mode="server" empty-title="Nenhuma cobrança encontrada" empty-text="Ajuste os filtros ou o período." :empty-icon="PhReceipt" @row-click="(r) => router.push(`/dashboard/admin/users/${r.user_id}`)">
                <template #cell-date="{ row }"><span class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ format.date(row.date, true) }}</span></template>
                <template #cell-user="{ row }">
                    <span class="flex items-center gap-2.5">
                        <Avatar :name="row.user_name" size="xs" />
                        <span class="min-w-0"><span class="block truncate font-medium text-slate-900 dark:text-white">{{ row.user_name }}</span><span class="block truncate text-xs text-slate-500">{{ row.user_email }}</span></span>
                    </span>
                </template>
                <template #cell-kind="{ row }"><span class="text-slate-600 dark:text-slate-300">{{ kinds[row.kind] }}</span><span v-if="row.plan" class="block text-xs text-slate-400">{{ row.plan }}</span></template>
                <template #cell-amount="{ row }"><span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ format.money(row.amount) }}</span></template>
                <template #cell-gateway="{ row }">
                    <span class="text-slate-600 dark:text-slate-300">{{ gatewayLabel(row.gateway) }}</span>
                    <span v-if="row.method" class="block text-xs text-slate-400">{{ row.method === 'pix' ? 'PIX' : 'Cartão' }}</span>
                </template>
                <template #cell-status="{ row }"><Badge :tone="statusTone[row.status]" dot>{{ statusLabel[row.status] }}</Badge></template>
                <template #cell-payment_id="{ row }"><span class="font-mono text-xs text-slate-500">{{ row.payment_id }}</span></template>
            </DataTable>

            <Pagination :page="page" :last-page="lastPage" :total="total" label="cobranças" @change="load" />
        </Card>
    </div>
</template>

<script setup lang="ts">
import { GATEWAY_LABELS, gatewayLabel } from '@/utils/gateways';
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { PhReceipt, PhDownloadSimple, PhCaretDown, PhMagnifyingGlass, PhCheckCircle, PhArrowCounterClockwise, PhHourglass, PhXCircle } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { Avatar, Badge, Button, Card, DataTable, Dropdown, PageHeader, Pagination, Select, StatCard, TextInput, errorMessage, format, toast } from '@/ui';
import type { BadgeTone, Column, DropdownItem } from '@/ui';

const router = useRouter();
const authStore = useAuthStore();

const filters = ref({ search: '', status: '', gateway: '', from: '', to: '' });
const rows = ref<any[]>([]);
const summary = ref<Record<string, { count: number; amount: number }>>({});
const loading = ref(true);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const exporting = ref<string | null>(null);

const tiles = [
    { status: 'approved', label: 'Aprovado', icon: PhCheckCircle, tone: 'success' as const },
    { status: 'refunded', label: 'Estornado', icon: PhArrowCounterClockwise, tone: 'neutral' as const },
    { status: 'pending', label: 'Em análise', icon: PhHourglass, tone: 'warning' as const },
    { status: 'rejected', label: 'Recusado', icon: PhXCircle, tone: 'danger' as const },
];
const kinds: Record<string, string> = { subscription: 'Assinatura', overage: 'Excedente', pending: 'Compra' };
const statusLabel: Record<string, string> = { approved: 'Aprovado', refunded: 'Estornado', pending: 'Em análise', rejected: 'Recusado' };
const statusTone: Record<string, BadgeTone> = { approved: 'success', refunded: 'neutral', pending: 'warning', rejected: 'danger' };

const columns: Column[] = [
    { key: 'date', label: 'Data' },
    { key: 'user', label: 'Cliente' },
    { key: 'kind', label: 'Tipo' },
    { key: 'amount', label: 'Valor', align: 'right' },
    { key: 'gateway', label: 'Gateway' },
    { key: 'status', label: 'Status' },
    { key: 'payment_id', label: 'Id' },
];

const exportItems: DropdownItem[] = [
    { label: 'Cobranças (com os filtros atuais)', onClick: () => download('payments') },
    { label: 'Estornos', onClick: () => download('refunds') },
    { label: 'Usuários', onClick: () => download('users') },
];

function params(): Record<string, string> {
    return Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== ''));
}

async function load(p = 1) {
    loading.value = true;
    try {
        const r = await axios.get('/dashboard/admin/payments', { params: { ...params(), page: p } });
        const data = r.data?.data;
        rows.value = (data?.data ?? []).map((row: any) => ({ ...row, key: `${row.kind}-${row.payment_id}` }));
        summary.value = data?.summary ?? {};
        page.value = data?.current_page ?? 1;
        lastPage.value = data?.last_page ?? 1;
        total.value = data?.total ?? 0;
    } catch (err) {
        toast.error('Não foi possível carregar as cobranças', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
}

let debounce: ReturnType<typeof setTimeout> | null = null;
watch(filters, () => {
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(() => load(1), 350);
}, { deep: true });

onMounted(() => load());

// Download pelo axios (o token vai no header), e não por link direto.
async function download(type: string) {
    exporting.value = type;
    try {
        const r = await axios.get(`/dashboard/admin/export/${type}`, { params: type === 'payments' ? params() : {}, responseType: 'blob' });
        const url = URL.createObjectURL(r.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${type}-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        toast.success('Exportação pronta');
    } catch {
        toast.error('Não foi possível exportar');
    } finally {
        exporting.value = null;
    }
}
</script>
