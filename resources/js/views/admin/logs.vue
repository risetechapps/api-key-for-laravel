<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Logs</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Registros da aplicação, do mais recente ao mais antigo</p>
        </div>

        <!-- Filtros -->
        <Card>
            <div class="flex flex-col gap-3 sm:flex-row">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar na mensagem ou no contexto..."
                    class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    @input="debouncedFetch"
                />

                <select
                    v-model="type"
                    class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    @change="applyFilters"
                >
                    <option value="">Tudo</option>
                    <option v-for="t in types" :key="t" :value="t">{{ typeLabel(t) }}</option>
                </select>

                <select
                    v-model="level"
                    class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    @change="applyFilters"
                >
                    <option value="">Todos os níveis</option>
                    <option v-for="l in levels" :key="l" :value="l">{{ l }}</option>
                </select>

                <input
                    v-model="from"
                    type="date"
                    class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    @change="applyFilters"
                />
                <input
                    v-model="to"
                    type="date"
                    class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    @change="applyFilters"
                />
            </div>
        </Card>

        <Card>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Data/Hora</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Origem do registro</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Nível</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Mensagem</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Origem</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="loading" v-for="i in 6" :key="i">
                            <td v-for="j in 6" :key="j" class="py-3 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td colspan="6" class="py-10 text-center text-slate-500 dark:text-slate-400">Nenhum registro encontrado</td>
                        </tr>
                        <tr
                            v-else
                            v-for="log in rows"
                            :key="log.id"
                            class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                        >
                            <td class="py-3 px-4 text-sm text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                {{ formatDate(log.created_at) }}
                            </td>
                            <td class="py-3 px-4">
                                <span :class="typeClass(log.type)" class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium">
                                    {{ typeLabel(log.type) }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span :class="levelClass(log.level)" class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium uppercase">
                                    {{ log.level ?? '—' }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-900 dark:text-white max-w-md truncate" :title="log.message">
                                {{ log.message }}
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-500 dark:text-slate-400 font-mono max-w-xs truncate" :title="log.origin">
                                {{ log.origin ?? '—' }}
                            </td>
                            <td class="py-3 px-4 text-right">
                                <Button variant="outline" size="sm" @click="openDetail(log.id)">Detalhes</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="totalPages > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ total }} registros</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="page <= 1" @click="changePage(page - 1)">Anterior</Button>
                    <Button variant="outline" size="sm" :disabled="page >= totalPages" @click="changePage(page + 1)">Próximo</Button>
                </div>
            </div>
        </Card>

        <!-- Detalhe -->
        <Modal v-model="detailOpen" title="Detalhe do log">
            <div v-if="detailLoading" class="space-y-3 py-2">
                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                <div class="h-24 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
            </div>

            <div v-else-if="detail" class="space-y-4">
                <div class="flex items-center gap-3">
                    <span :class="levelClass(detail.level)" class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium uppercase">
                        {{ detail.level ?? '—' }}
                    </span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(detail.created_at) }}</span>
                </div>

                <p class="text-sm font-medium text-slate-900 dark:text-white break-words">{{ detail.message }}</p>

                <div v-if="detail.origin || detail.properties?.class">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Origem</p>
                    <p class="text-xs font-mono text-slate-700 dark:text-slate-300 break-all">
                        {{ detail.properties?.class }}<span v-if="detail.properties?.line">:{{ detail.properties.line }}</span>
                    </p>
                </div>

                <div v-if="hasEntries(detail.context)">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Contexto</p>
                    <pre class="text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3 overflow-x-auto text-slate-700 dark:text-slate-300">{{ pretty(detail.context) }}</pre>
                </div>

                <div v-if="detail.exception">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Exceção</p>
                    <pre class="text-xs bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 rounded-xl p-3 overflow-x-auto text-red-800 dark:text-red-300">{{ pretty(detail.exception) }}</pre>
                </div>

                <div v-if="hasEntries(detail.tags)">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Tags</p>
                    <pre class="text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3 overflow-x-auto text-slate-700 dark:text-slate-300">{{ pretty(detail.tags) }}</pre>
                </div>
            </div>

            <p v-else class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                Não foi possível carregar este registro.
            </p>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { format, parseISO, isValid } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useAdminStore } from '@/stores/admin';
import Card from '@/views/componentes/Card.vue';
import Button from '@/views/componentes/Button.vue';
import Modal from '@/views/componentes/Modal.vue';

const adminStore = useAdminStore();

const loading    = computed(() => adminStore.loading);
const logsData   = computed(() => adminStore.logs);
const rows       = computed(() => logsData.value?.data ?? []);
const total      = computed(() => logsData.value?.total ?? 0);
const totalPages = computed(() => logsData.value?.last_page ?? 1);

// Os níveis vêm do backend, e não de uma lista fixa aqui: se o pacote passar a
// gravar num nível novo, a tela acompanha sem precisar ser editada.
const levels = computed(() => logsData.value?.levels ?? []);
const types  = computed(() => logsData.value?.types ?? []);

const page   = ref(1);
const search = ref('');
const type   = ref('');
const level  = ref('');
const from   = ref('');
const to     = ref('');

const detailOpen    = ref(false);
const detailLoading = ref(false);
const detail        = ref<any>(null);

onMounted(() => fetch());

function fetch() {
    adminStore.fetchLogs({
        page: page.value,
        search: search.value || undefined,
        type: type.value || undefined,
        level: level.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    });
}

function applyFilters() {
    // Qualquer filtro novo reinicia a paginação: manter a página 7 depois de
    // trocar o filtro costuma cair fora do resultado e mostrar tela vazia.
    page.value = 1;
    fetch();
}

function changePage(p: number) {
    page.value = p;
    fetch();
}

// A busca dispara a cada tecla; sem o atraso, escrever "pagamento" manda nove
// consultas à tabela que mais cresce da instalação.
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function debouncedFetch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
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
    return !!value && typeof value === 'object' && Object.keys(value).length > 0;
}

function pretty(value: any): string {
    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

// `log` é o que o pacote grava de propósito; `exception` é o que o handler
// captura via report(). São origens diferentes e o operador precisa distinguir:
// um estorno que falha produz os dois, e só o par conta a história inteira.
function typeLabel(type: string | null): string {
    return type === 'exception' ? 'Exceção' : 'Log';
}

function typeClass(type: string | null): string {
    return type === 'exception'
        ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'
        : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400';
}

function levelClass(level: string | null): string {
    switch (level) {
        case 'emergency':
        case 'alert':
        case 'critical':
        case 'error':
            return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
        case 'warning':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
        case 'notice':
        case 'info':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400';
        default:
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400';
    }
}

function formatDate(date: string | null): string {
    if (!date) return '—';

    try {
        const parsed = parseISO(date);

        return isValid(parsed) ? format(parsed, "dd/MM/yyyy HH:mm:ss", { locale: ptBR }) : '—';
    } catch {
        return '—';
    }
}
</script>
