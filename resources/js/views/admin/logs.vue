<template>
    <div>
        <PageHeader title="Logs" subtitle="Registros da aplicação e exceções, do mais recente ao mais antigo" :icon="PhFileText" />

        <Card>
            <div class="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                <TextInput v-model="search" class="xl:col-span-2" placeholder="Buscar na mensagem ou no contexto…" :icon="PhMagnifyingGlass" />
                <Select v-model="type" placeholder="Logs e exceções" :options="types.map((t: string) => ({ value: t, label: typeLabel(t) }))" />
                <Select v-model="level" placeholder="Todos os níveis" :options="levels.map((l: string) => ({ value: l, label: l }))" />
                <TextInput v-model="from" type="date" aria-label="De" />
                <TextInput v-model="to" type="date" aria-label="Até" />
            </div>

            <DataTable :columns="columns" :rows="rows" :loading="loading" clickable empty-title="Nenhum registro encontrado" :empty-icon="PhFileText" @row-click="(r) => openDetail(r.id)">
                <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-slate-500">{{ format.date(row.created_at, true) }}</span></template>
                <template #cell-level="{ row }">
                    <span class="flex items-center gap-1.5">
                        <Badge :tone="levelTone(row.level)" dot>{{ row.level ?? '—' }}</Badge>
                        <Badge v-if="row.type === 'exception'" tone="danger" :icon="PhBug">exceção</Badge>
                    </span>
                </template>
                <template #cell-message="{ row }"><span class="line-clamp-1 max-w-xl text-slate-900 dark:text-white" :title="row.message">{{ row.message }}</span></template>
                <template #cell-origin="{ row }"><span class="block max-w-xs truncate font-mono text-xs text-slate-500" :title="row.origin">{{ row.origin ?? '—' }}</span></template>
            </DataTable>

            <Pagination :page="page" :last-page="totalPages" :total="total" label="registros" @change="changePage" />
        </Card>

        <Drawer v-model="detailOpen" title="Detalhe do registro" :description="detail ? format.date(detail.created_at, true) : ''" size="lg">
            <div v-if="detailLoading" class="space-y-3"><Skeleton :lines="3" /><Skeleton height="10rem" rounded="rounded-xl" /></div>

            <div v-else-if="detail" class="space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="levelTone(detail.level)" size="md" dot>{{ detail.level ?? '—' }}</Badge>
                    <Badge v-if="detail.type === 'exception'" tone="danger" size="md" :icon="PhBug">exceção</Badge>
                </div>

                <p class="break-words text-sm font-medium text-slate-900 dark:text-white">{{ detail.message }}</p>

                <div v-if="detail.origin || detail.properties?.class">
                    <p class="mb-1 text-xs font-medium text-slate-500">Origem</p>
                    <CopyField :value="`${detail.properties?.class ?? detail.origin}${detail.properties?.line ? ':' + detail.properties.line : ''}`" />
                </div>

                <div v-for="block in blocks" :key="block.key">
                    <template v-if="hasEntries(detail[block.key])">
                        <p class="mb-1 text-xs font-medium text-slate-500">{{ block.label }}</p>
                        <pre :class="['max-h-96 overflow-auto rounded-xl p-4 font-mono text-[11px] leading-relaxed', block.key === 'exception' ? 'bg-red-950 text-red-200' : 'bg-slate-950 text-slate-300']">{{ pretty(detail[block.key]) }}</pre>
                    </template>
                </div>
            </div>

            <EmptyState v-else title="Não foi possível carregar este registro" :icon="PhWarningCircle" compact />
        </Drawer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { PhFileText, PhMagnifyingGlass, PhBug, PhWarningCircle } from '@phosphor-icons/vue';
import { useAdminStore } from '@/stores/admin';
import { Badge, Card, CopyField, DataTable, Drawer, EmptyState, PageHeader, Pagination, Select, Skeleton, TextInput, format } from '@/ui';
import type { BadgeTone, Column } from '@/ui';

const adminStore = useAdminStore();

const loading = computed(() => adminStore.loading);
const logsData = computed<any>(() => adminStore.logs);
const rows = computed<any[]>(() => logsData.value?.data ?? []);
const total = computed<number>(() => logsData.value?.total ?? 0);
const totalPages = computed<number>(() => logsData.value?.last_page ?? 1);

// Níveis e tipos vêm do backend: um nível novo aparece sem editar a tela.
const levels = computed<string[]>(() => logsData.value?.levels ?? []);
const types = computed<string[]>(() => logsData.value?.types ?? []);

const page = ref(1);
const search = ref('');
const type = ref('');
const level = ref('');
const from = ref('');
const to = ref('');

const detailOpen = ref(false);
const detailLoading = ref(false);
const detail = ref<any>(null);

const columns: Column[] = [
    { key: 'created_at', label: 'Quando' },
    { key: 'level', label: 'Nível' },
    { key: 'message', label: 'Mensagem' },
    { key: 'origin', label: 'Origem' },
];

const blocks = [
    { key: 'context', label: 'Contexto' },
    { key: 'exception', label: 'Exceção' },
    { key: 'tags', label: 'Tags' },
];

function fetchLogs() {
    adminStore.fetchLogs({
        page: page.value,
        search: search.value || undefined,
        type: type.value || undefined,
        level: level.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    });
}

onMounted(fetchLogs);

// Filtro novo volta à página 1. A busca espera a digitação parar: sem isso,
// escrever "pagamento" mandaria nove consultas à tabela que mais cresce.
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, type, level, from, to], ([s], [prevS]) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        fetchLogs();
    }, s !== prevS ? 400 : 0);
});

function changePage(p: number) {
    page.value = p;
    fetchLogs();
}

async function openDetail(id: string) {
    detail.value = null;
    detailLoading.value = true;
    detailOpen.value = true;
    try {
        detail.value = await adminStore.fetchLog(id);
    } catch {
        detail.value = null;
    } finally {
        detailLoading.value = false;
    }
}

function hasEntries(value: any): boolean {
    return !!value && (typeof value === 'string' ? value.length > 0 : typeof value === 'object' && Object.keys(value).length > 0);
}

function pretty(value: any): string {
    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

// `log` é o que o pacote grava de propósito; `exception` é o que o handler
// captura via report(). Um estorno que falha produz os dois.
function typeLabel(t: string | null): string {
    return t === 'exception' ? 'Exceções' : 'Logs';
}

function levelTone(l: string | null): BadgeTone {
    if (['emergency', 'alert', 'critical', 'error'].includes(l ?? '')) return 'danger';
    if (l === 'warning') return 'warning';
    if (l === 'notice' || l === 'info') return 'info';
    return 'neutral';
}
</script>
