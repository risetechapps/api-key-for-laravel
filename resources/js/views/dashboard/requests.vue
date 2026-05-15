<template>
    <div class="space-y-6">

        <!-- Tabs -->
        <div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl w-fit">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                @click="activeTab = tab.key"
                :class="[
                    'px-5 py-2 rounded-lg text-sm font-medium transition-all duration-200',
                    activeTab === tab.key
                        ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white',
                ]"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- ============================================================ -->
        <!-- TAB: HISTÓRICO                                               -->
        <!-- ============================================================ -->
        <template v-if="activeTab === 'history'">
            <!-- Filters -->
            <Card>
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1">
                        <div class="relative">
                            <PhMagnifyingGlass class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" :size="20"/>
                            <input
                                v-model="filters.search"
                                type="text"
                                placeholder="Buscar por endpoint..."
                                class="w-full pl-10 pr-4 py-3 rounded-xl border-2 border-slate-200
                                    dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900
                                    dark:text-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20
                                     transition-all"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <select
                            v-model="filters.method"
                            class="px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white
                             dark:bg-slate-800 text-slate-900 dark:text-white focus:border-indigo-500 transition-all">
                            <option value="">Todos métodos</option>
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="DELETE">DELETE</option>
                        </select>

                        <select
                            v-model="filters.status"
                            class="px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700
                             bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-indigo-500 transition-all">
                            <option value="">Todos status</option>
                            <option value="success">Sucesso (2xx)</option>
                            <option value="error">Erro (4xx/5xx)</option>
                        </select>

                        <button
                            @click="resetFilters"
                            class="p-3 rounded-xl border-2 border-slate-200 dark:border-slate-700
                                   hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                        >
                            <PhX :size="18" class="text-slate-500"/>
                        </button>
                    </div>
                </div>
            </Card>

            <!-- Requests Table -->
            <Card>
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full">
                        <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Data/Hora</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Endpoint</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Método</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Status</th>
                            <th class="text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Ações</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="loading" v-for="i in 5" :key="`loading-${i}`">
                            <td v-for="j in 5" :key="`loading-col-${j}`" class="py-4 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>

                        <tr v-else-if="filteredRequests.length === 0">
                            <td colspan="5" class="py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                                        <PhMagnifyingGlass :size="32" class="text-slate-400"/>
                                    </div>
                                    <p class="text-slate-500 dark:text-slate-400">Nenhuma requisição encontrada</p>
                                </div>
                            </td>
                        </tr>

                        <tr
                            v-else
                            v-for="request in paginatedRequests"
                            :key="request.id"
                            class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                        >
                            <td class="py-4 px-4">
                                <span class="text-sm text-slate-700 dark:text-slate-300">{{ formatDate(request.requested_at) }}</span>
                            </td>
                            <td class="py-4 px-4">
                                <span class="font-mono text-sm text-slate-900 dark:text-white">{{ request.endpoint }}</span>
                            </td>
                            <td class="py-4 px-4">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold" :class="getMethodClass(request.method)">
                                    {{ request.method }}
                                </span>
                            </td>
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="getStatusColor(request.response_code)" />
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ request.response_code }}</span>
                                </div>
                            </td>
                            <td class="py-4 px-4 text-right">
                                <button @click="showDetails(request)" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300 text-sm font-medium">
                                    Detalhes
                                </button>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards -->
                <div class="sm:hidden space-y-3">
                    <div v-if="loading" v-for="i in 5" :key="`m-loading-${i}`" class="h-20 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"/>
                    <p v-else-if="filteredRequests.length === 0" class="py-8 text-center text-slate-500 dark:text-slate-400">Nenhuma requisição encontrada</p>
                    <div v-else v-for="request in paginatedRequests" :key="`m-${request.id}`"
                         class="p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold" :class="getMethodClass(request.method)">{{ request.method }}</span>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full" :class="getStatusColor(request.response_code)"/>
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ request.response_code }}</span>
                            </div>
                        </div>
                        <p class="font-mono text-sm text-slate-900 dark:text-white break-all">{{ request.endpoint }}</p>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(request.requested_at) }}</span>
                            <button @click="showDetails(request)" class="text-indigo-600 dark:text-indigo-400 text-sm font-medium">Detalhes</button>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="filteredRequests.length > 0" class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        Mostrando {{ startItem }} a {{ endItem }} de {{ filteredRequests.length }} resultados
                    </p>
                    <div class="flex items-center gap-2">
                        <button @click="currentPage--" :disabled="currentPage === 1"
                                class="p-2 rounded-lg border border-slate-200 dark:border-slate-700 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <PhCaretLeft :size="18" class="text-slate-600 dark:text-slate-400"/>
                        </button>
                        <span class="text-sm text-slate-600 dark:text-slate-400 px-3">{{ currentPage }} / {{ totalPages }}</span>
                        <button @click="currentPage++" :disabled="currentPage === totalPages"
                                class="p-2 rounded-lg border border-slate-200 dark:border-slate-700 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <PhCaretRight :size="18" class="text-slate-600 dark:text-slate-400"/>
                        </button>
                    </div>
                </div>
            </Card>
        </template>

        <!-- Request Details Modal -->
        <Modal v-model="detailsModal" title="Detalhes da Requisição">
            <div v-if="selectedRequest" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Método</p>
                        <span class="inline-flex mt-1 px-2.5 py-1 rounded-lg text-xs font-semibold" :class="getMethodClass(selectedRequest.method)">
                            {{ selectedRequest.method }}
                        </span>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Status</p>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="w-2.5 h-2.5 rounded-full" :class="getStatusColor(selectedRequest.response_code)" />
                            <span class="font-medium text-slate-900 dark:text-white">{{ selectedRequest.response_code }}</span>
                        </div>
                    </div>
                    <div class="col-span-2">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Endpoint</p>
                        <p class="font-mono text-sm text-slate-900 dark:text-white mt-1 break-all">{{ selectedRequest.endpoint }}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Data/Hora</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ formatDateLong(selectedRequest.requested_at) }}</p>
                    </div>
                </div>
            </div>
            <template #footer>
                <button @click="detailsModal = false" class="px-4 py-2 rounded-lg border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                    Fechar
                </button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import {
    PhMagnifyingGlass, PhX, PhCaretLeft, PhCaretRight,
    PhIdentificationCard, PhBuildings, PhMapPin,
    PhCloud, PhCalendarBlank, PhGlobe, PhBank,
    PhFlag, PhMapTrifold, PhCity,
} from '@phosphor-icons/vue';
import Card from '@/views/componentes/Card.vue';
import Modal from '@/views/componentes/Modal.vue';

const authStore      = useAuthStore();
const dashboardStore = useDashboardStore();

const activeTab = ref('test');
const tabs = [
    { key: 'history', label: 'Histórico'  },
];

const loading      = ref(true);
const currentPage  = ref(1);
const itemsPerPage = 10;
const detailsModal = ref(false);
const selectedRequest = ref(null);

const filters = reactive({ search: '', method: '', status: '' });

const requests = computed(() => dashboardStore.requests);

const filteredRequests = computed(() =>
    requests.value.filter(req => {
        const matchesSearch  = !filters.search  || req.endpoint?.toLowerCase().includes(filters.search.toLowerCase());
        const matchesMethod  = !filters.method  || req.method === filters.method;
        const matchesStatus  = !filters.status  ||
            (filters.status === 'success' && req.response_code < 300) ||
            (filters.status === 'error'   && req.response_code >= 400);
        return matchesSearch && matchesMethod && matchesStatus;
    })
);

const paginatedRequests = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredRequests.value.slice(start, start + itemsPerPage);
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredRequests.value.length / itemsPerPage)));
const startItem  = computed(() => (currentPage.value - 1) * itemsPerPage + 1);
const endItem    = computed(() => Math.min(currentPage.value * itemsPerPage, filteredRequests.value.length));

onMounted(async () => {
    await Promise.all([authStore.fetchProfile(), dashboardStore.fetchRequests()]);
    loading.value = false;
});

const formatDate     = d => format(new Date(d.replace(' ', 'T')), 'dd/MM/yyyy HH:mm', { locale: ptBR });
const formatDateLong = d => format(new Date(d.replace(' ', 'T')), "dd 'de' MMMM 'de' yyyy 'às' HH:mm:ss", { locale: ptBR });

const getMethodClass = method => ({
    GET:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    POST:   'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    PUT:    'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    DELETE: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}[method] || 'bg-emerald-100 text-emerald-700');

const getStatusColor = code => code < 300 ? 'bg-emerald-500' : code < 400 ? 'bg-amber-500' : 'bg-red-500';

const showDetails = request => { selectedRequest.value = request; detailsModal.value = true; };

const resetFilters = () => { filters.search = ''; filters.method = ''; filters.status = ''; currentPage.value = 1; };
</script>
