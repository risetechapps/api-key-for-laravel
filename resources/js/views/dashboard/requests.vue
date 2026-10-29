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
            <div class="mb-5 space-y-4">
                <div class="flex gap-3">
                    <TextInput v-model="filters.search" class="flex-1" placeholder="Buscar por endpoint ou IP…" :icon="PhMagnifyingGlass" />
                    <Button variant="ghost" :icon="PhX" :disabled="!hasFilters" @click="resetFilters">Limpar</Button>
                </div>

                <!-- Filtros em botões: as opções ficam à vista e a escolhida, marcada. -->
                <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
                    <div v-for="group in filterGroups" :key="group.key" class="flex min-w-0 max-w-full flex-col gap-1.5">
                        <span :id="`filter-${group.key}`" class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ group.label }}</span>
                        <div role="group" :aria-labelledby="`filter-${group.key}`" class="flex max-w-full flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800/70">
                            <button
                                v-for="option in group.options"
                                :key="option.value"
                                type="button"
                                :aria-pressed="group.value === option.value"
                                :class="[
                                    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                                    group.value === option.value
                                        ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200',
                                ]"
                                @click="group.set(option.value)"
                            >
                                <span v-if="option.dot" :class="['h-1.5 w-1.5 rounded-full', option.dot]" aria-hidden="true" />
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
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
                <template #cell-api_key="{ row }">
                    <span v-if="row.api_key" :class="['whitespace-nowrap text-xs', row.api_key.deleted ? 'italic text-slate-400' : 'text-slate-600 dark:text-slate-300']">{{ keyLabel(row.api_key) }}</span>
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
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-slate-500 dark:text-slate-400">Chave</dt>
                        <dd v-if="selected.api_key" class="text-right font-medium text-slate-900 dark:text-white">{{ keyLabel(selected.api_key) }}<br><span class="font-mono text-xs font-normal text-slate-500">{{ selected.api_key.id }}</span></dd>
                        <dd v-else class="text-right text-slate-500 dark:text-slate-400">Não registrada (requisição anterior ao registro da chave)</dd>
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
import axios from 'axios';
import { PhClockCounterClockwise, PhArrowsClockwise, PhPulse, PhCheckCircle, PhWarningCircle, PhMagnifyingGlass, PhX, PhCaretRight } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { Badge, Button, Card, CopyField, DataTable, Drawer, PageHeader, Pagination, StatCard, TextInput, format } from '@/ui';
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

// O filtro por chave vai ao servidor: para investigar uma chave (vazada, por
// exemplo) não basta filtrar a página já carregada.
const apiKeys = ref<{ id: string; name: string | null; revoked?: boolean }[]>([]);
const apiKeyId = ref('');

type FilterOption = { value: string; label: string; dot?: string };

const filterGroups = computed(() => {
    const groups: { key: string; label: string; value: string; options: FilterOption[]; set: (v: string) => void }[] = [
        {
            key: 'method',
            label: 'Método',
            value: filters.method,
            options: [{ value: '', label: 'Todos' }, ...['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m }))],
            set: (v) => (filters.method = v),
        },
        {
            key: 'status',
            label: 'Status',
            value: filters.status,
            options: [
                { value: '', label: 'Todos' },
                { value: 'success', label: 'Sucesso (2xx)', dot: 'bg-emerald-500' },
                { value: 'error', label: 'Erro (4xx/5xx)', dot: 'bg-red-500' },
            ],
            set: (v) => (filters.status = v),
        },
    ];

    // Com uma chave só, o filtro não separa nada.
    if (apiKeys.value.length > 1) {
        groups.push({
            key: 'api_key',
            label: 'Chave',
            value: apiKeyId.value,
            options: [
                { value: '', label: 'Todas' },
                ...apiKeys.value.map((k) => ({ value: k.id, label: `${k.name || 'Sem nome'}${k.revoked ? ' (excluída)' : ''}`, dot: k.revoked ? 'bg-slate-400' : undefined })),
            ],
            set: (v) => (apiKeyId.value = v),
        });
    }

    return groups;
});

const columns: Column[] = [
    { key: 'requested_at', label: 'Data e hora' },
    { key: 'endpoint', label: 'Endpoint' },
    { key: 'method', label: 'Método' },
    { key: 'ip', label: 'IP' },
    { key: 'api_key', label: 'Chave' },
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
const hasFilters = computed(() => !!(filters.search || filters.method || filters.status || apiKeyId.value));

const successRate = computed(() => requests.value.length ? (requests.value.filter((r) => r.response_code < 300).length / requests.value.length) * 100 : 0);
const errorCount = computed(() => requests.value.filter((r) => r.response_code >= 400).length);

watch(filters, () => (page.value = 1));
watch(apiKeyId, async () => {
    page.value = 1;
    loading.value = true;
    try {
        await load();
    } finally {
        loading.value = false;
    }
});

function load() {
    return dashboardStore.fetchRequests(apiKeyId.value ? { api_key_id: apiKeyId.value } : {});
}

function keyLabel(key: { name: string | null; deleted: boolean }): string {
    return key.deleted ? 'Chave excluída' : key.name || 'Sem nome';
}

function methodTone(method: string): BadgeTone {
    return ({ GET: 'success', POST: 'info', PUT: 'warning', PATCH: 'warning', DELETE: 'danger' } as Record<string, BadgeTone>)[method] ?? 'neutral';
}

function statusTone(code: number): BadgeTone {
    return code < 300 ? 'success' : code < 400 ? 'warning' : 'danger';
}

// Campos que o log trouxer além dos que já estão na tela.
function extra(row: any): Record<string, string> {
    const skip = new Set(['id', 'endpoint', 'method', 'ip', 'api_key', 'response_code', 'requested_at']);
    return Object.fromEntries(Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== '' && typeof v !== 'object').map(([k, v]) => [k, String(v)]));
}

function open(row: any) {
    selected.value = row;
    detailsOpen.value = true;
}

function resetFilters() {
    Object.assign(filters, { search: '', method: '', status: '' });
    apiKeyId.value = '';
}

async function refresh() {
    refreshing.value = true;
    try {
        await load();
    } finally {
        refreshing.value = false;
    }
}

onMounted(async () => {
    try {
        await Promise.all([authStore.fetchProfile(), load(), loadApiKeys()]);
    } finally {
        loading.value = false;
    }
});

// Ativas e excluídas: investigar uma chave apagada (vazada, por exemplo) é
// justamente um dos usos do filtro. Sem a lista (ex.: papel sem acesso às
// chaves), a tela segue sem o filtro.
async function loadApiKeys() {
    try {
        const [active, revoked] = await Promise.all([
            axios.get('/dashboard/api-keys'),
            axios.get('/dashboard/api-keys/revoked').catch(() => ({ data: { data: [] } })),
        ]);
        const current = (active.data?.data ?? []) as { id: string; name: string | null }[];
        const gone = ((revoked.data?.data ?? []) as { id: string; name: string | null }[])
            .filter((k) => !current.some((c) => c.id === k.id))
            .map((k) => ({ ...k, revoked: true }));
        apiKeys.value = [...current, ...gone];
    } catch {
        apiKeys.value = [];
    }
}
</script>
