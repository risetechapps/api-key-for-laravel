<template>
    <div>
        <PageHeader title="Requisições" subtitle="Histórico das chamadas à API feitas com as suas chaves" :icon="PhClockCounterClockwise">
            <template #actions>
                <Button variant="outline" :icon="PhArrowsClockwise" :loading="refreshing" @click="refresh">Atualizar</Button>
            </template>
        </PageHeader>

        <div class="ui-stagger grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard style="--i: 0" label="Requisições listadas" :value="requests.length" :icon="PhPulse" :loading="loading" />
            <StatCard style="--i: 1" label="Taxa de sucesso" :value="successRate" format="percent" :digits="1" :icon="PhCheckCircle" tone="success" :loading="loading" hint="respostas 2xx" />
            <StatCard style="--i: 2" label="Com erro" :value="errorCount" :icon="PhWarningCircle" :tone="errorCount ? 'danger' : 'neutral'" :loading="loading" hint="respostas 4xx e 5xx" />
        </div>

        <Card class="mt-6">
            <div class="mb-5 flex flex-col gap-3 lg:flex-row">
                <TextInput v-model="filters.search" class="lg:flex-1" placeholder="Buscar por endpoint ou IP…" :icon="PhMagnifyingGlass" />
                <div class="flex gap-3">
                    <Select v-model="filters.method" class="w-40" placeholder="Todos os métodos" :options="['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m }))" />
                    <Select v-model="filters.status" class="w-44" placeholder="Todos os status" :options="{ success: 'Sucesso (2xx)', error: 'Erro (4xx/5xx)' }" />
                    <Tooltip text="Limpar filtros">
                        <Button variant="outline" square :icon="PhX" aria-label="Limpar filtros" :disabled="!hasFilters" @click="resetFilters" />
                    </Tooltip>
                </div>
            </div>

            <DataTable
                :columns="columns"
                :rows="paginated"
                :loading="loading"
                clickable
                :empty-title="hasFilters ? 'Nenhuma requisição com esses filtros' : 'Nenhuma requisição ainda'"
                :empty-text="hasFilters ? 'Tente limpar os filtros.' : 'As chamadas à API aparecem aqui assim que acontecerem.'"
                :empty-icon="PhMagnifyingGlass"
                @row-click="open"
            >
                <template #cell-requested_at="{ row }">
                    <span class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ format.date(normalize(row.requested_at), true) }}</span>
                </template>
                <template #cell-endpoint="{ row }"><span class="font-mono text-xs text-slate-900 dark:text-white">{{ row.endpoint }}</span></template>
                <template #cell-method="{ row }"><Badge :tone="methodTone(row.method)">{{ row.method }}</Badge></template>
                <template #cell-ip="{ row }">
                    <span v-if="row.ip" class="whitespace-nowrap font-mono text-xs text-slate-600 dark:text-slate-300">{{ row.ip }}</span>
                    <span v-else class="text-slate-400">—</span>
                </template>
                <template #cell-response_code="{ row }"><Badge :tone="statusTone(row.response_code)" dot>{{ row.response_code }}</Badge></template>
                <template #cell-actions><PhCaretRight :size="16" class="text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500" /></template>
            </DataTable>

            <Pagination :page="page" :last-page="lastPage" :total="filtered.length" label="requisições" @change="(p) => (page = p)" />
        </Card>

        <Drawer v-model="detailsOpen" title="Detalhes da requisição" :description="selected?.endpoint">
            <div v-if="selected" class="space-y-6">
                <div class="flex items-center gap-2">
                    <Badge :tone="methodTone(selected.method)" size="md">{{ selected.method }}</Badge>
                    <Badge :tone="statusTone(selected.response_code)" size="md" dot>{{ selected.response_code }}</Badge>
                </div>
                <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-slate-500 dark:text-slate-400">Quando</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ format.date(normalize(selected.requested_at), true) }}<br><span class="text-xs font-normal text-slate-500">{{ format.relative(normalize(selected.requested_at)) }}</span></dd>
                    </div>
                    <div class="py-3">
                        <dt class="text-slate-500 dark:text-slate-400">Endpoint</dt>
                        <dd class="mt-2"><CopyField :value="selected.endpoint" /></dd>
                    </div>
                    <div class="py-3">
                        <dt class="text-slate-500 dark:text-slate-400">IP de origem</dt>
                        <dd v-if="selected.ip" class="mt-2"><CopyField :value="selected.ip" /></dd>
                        <dd v-else class="mt-2 text-slate-500 dark:text-slate-400">Não registrado (requisição anterior ao registro de IP)</dd>
                    </div>
                    <div v-for="(value, key) in extra(selected)" :key="key" class="flex justify-between gap-4 py-3">
                        <dt class="text-slate-500 dark:text-slate-400">{{ key }}</dt>
                        <dd class="break-all text-right font-mono text-xs text-slate-900 dark:text-white">{{ value }}</dd>
                    </div>
                </dl>
            </div>
        </Drawer>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { PhClockCounterClockwise, PhArrowsClockwise, PhPulse, PhCheckCircle, PhWarningCircle, PhMagnifyingGlass, PhX, PhCaretRight } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { Badge, Button, Card, CopyField, DataTable, Drawer, PageHeader, Pagination, Select, StatCard, TextInput, Tooltip, format } from '@/ui';
import type { BadgeTone, Column } from '@/ui';

const authStore = useAuthStore();
const dashboardStore = useDashboardStore();

const PER_PAGE = 15;
const loading = ref(true);
const refreshing = ref(false);
const page = ref(1);
const detailsOpen = ref(false);
const selected = ref<any>(null);
const filters = reactive({ search: '', method: '', status: '' });

const requests = computed<any[]>(() => dashboardStore.requests);

const columns: Column[] = [
    { key: 'requested_at', label: 'Data e hora' },
    { key: 'endpoint', label: 'Endpoint' },
    { key: 'method', label: 'Método' },
    { key: 'ip', label: 'IP' },
    { key: 'response_code', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

// O backend manda "Y-m-d H:i:s"; o Safari só entende a forma ISO.
function normalize(value: string): string {
    return String(value ?? '').replace(' ', 'T');
}

const filtered = computed(() => requests.value
    .filter((r) => {
        const q = filters.search.trim().toLowerCase();
        return (!q || r.endpoint?.toLowerCase().includes(q) || r.ip?.toLowerCase().includes(q))
            && (!filters.method || r.method === filters.method)
            && (!filters.status || (filters.status === 'success' ? r.response_code < 300 : r.response_code >= 400));
    })
    .sort((a, b) => new Date(normalize(b.requested_at)).getTime() - new Date(normalize(a.requested_at)).getTime()));

const lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)));
const paginated = computed(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));
const hasFilters = computed(() => !!(filters.search || filters.method || filters.status));

const successRate = computed(() => requests.value.length ? (requests.value.filter((r) => r.response_code < 300).length / requests.value.length) * 100 : 0);
const errorCount = computed(() => requests.value.filter((r) => r.response_code >= 400).length);

watch(filters, () => (page.value = 1));

function methodTone(method: string): BadgeTone {
    return ({ GET: 'success', POST: 'info', PUT: 'warning', PATCH: 'warning', DELETE: 'danger' } as Record<string, BadgeTone>)[method] ?? 'neutral';
}

function statusTone(code: number): BadgeTone {
    return code < 300 ? 'success' : code < 400 ? 'warning' : 'danger';
}

// Campos que o log trouxer além dos que já estão na tela.
function extra(row: any): Record<string, string> {
    const skip = new Set(['id', 'endpoint', 'method', 'ip', 'response_code', 'requested_at']);
    return Object.fromEntries(Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== '' && typeof v !== 'object').map(([k, v]) => [k, String(v)]));
}

function open(row: any) {
    selected.value = row;
    detailsOpen.value = true;
}

function resetFilters() {
    Object.assign(filters, { search: '', method: '', status: '' });
}

async function refresh() {
    refreshing.value = true;
    try {
        await dashboardStore.fetchRequests();
    } finally {
        refreshing.value = false;
    }
}

onMounted(async () => {
    try {
        await Promise.all([authStore.fetchProfile(), dashboardStore.fetchRequests()]);
    } finally {
        loading.value = false;
    }
});
</script>
