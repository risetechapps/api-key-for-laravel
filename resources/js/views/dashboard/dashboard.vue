<template>
    <div>
        <PageHeader :subtitle="hasPlan ? `Seu plano ${currentPlan.name} renova ${renewsIn}.` : 'Escolha um plano para liberar sua API key.'">
            <template #title>{{ greeting }}, <span class="bg-gradient-to-r from-brand-600 to-brand-400 bg-clip-text text-transparent">{{ firstName }}</span></template>
            <template #actions>
                <Button variant="outline" :icon="PhBookOpen" href="/#docs">Documentação</Button>
                <Button :icon="PhKey" to="/dashboard/api-keys">API Keys</Button>
            </template>
        </PageHeader>

        <!-- Métricas -->
        <div class="ui-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard style="--i: 0" label="Requisições hoje" :value="stats.today" :icon="PhLightning" :loading="loading" hint="desde a meia-noite" />
            <StatCard style="--i: 1" label="Neste período" :value="stats.used" :icon="PhChartLineUp" tone="success" :loading="loading" :hint="stats.limit ? `de ${stats.limit.toLocaleString('pt-BR')}` : 'sem limite'" />
            <StatCard style="--i: 2" label="Restantes" :value="stats.limit ? stats.remaining : '∞'" :icon="PhGauge" :tone="usagePercentage >= 90 ? 'danger' : usagePercentage >= 75 ? 'warning' : 'info'" :loading="loading" :hint="stats.limit ? `${Math.round(100 - usagePercentage)}% do plano livre` : 'plano ilimitado'" />
            <StatCard style="--i: 3" label="Limite do plano" :value="stats.limit || 'Ilimitado'" :icon="PhStack" tone="neutral" :loading="loading" :to="'/dashboard/plans'" hint="ver planos" />
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Gráfico -->
            <Card class="lg:col-span-2" title="Uso de requisições" :subtitle="`Últimos ${chartPeriod} dias`">
                <template #actions>
                    <Tabs v-model="chartPeriod" variant="pills" :animate="false" :tabs="[{ key: '7', label: '7d' }, { key: '30', label: '30d' }, { key: '90', label: '90d' }]" label="Período" />
                </template>
                <div class="-mx-2 h-72">
                    <Skeleton v-if="loading" height="100%" rounded="rounded-xl" />
                    <apexchart v-else type="area" height="288" :options="usageChart" :series="chartSeries" />
                </div>
            </Card>

            <!-- Plano -->
            <Card :padding="false" class="overflow-hidden">
                <div class="relative bg-gradient-to-br from-brand-600 to-brand-800 p-6 text-white">
                    <div aria-hidden="true" class="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
                    <p class="text-xs font-medium uppercase tracking-wider text-white/70">Plano atual</p>
                    <h3 class="mt-1 flex items-center gap-2 text-xl font-bold">
                        <PhCrown :size="20" weight="fill" class="text-amber-300" />
                        {{ hasPlan ? currentPlan.name : 'Sem plano ativo' }}
                    </h3>
                    <p class="mt-1 text-sm text-white/80 line-clamp-2">{{ hasPlan ? (currentPlan.description || 'Seu plano está ativo.') : 'Assine um plano para começar a usar a API.' }}</p>

                    <div v-if="hasPlan && stats.limit" class="mt-5">
                        <div class="mb-1.5 flex justify-between text-xs text-white/80">
                            <span>{{ stats.used.toLocaleString('pt-BR') }} usadas</span>
                            <span class="tabular-nums">{{ Math.round(usagePercentage) }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-white/20">
                            <div class="h-full rounded-full bg-white transition-[width] duration-700 ease-[cubic-bezier(0.22,1,0.36,1)]" :style="{ width: `${usagePercentage}%` }" />
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <ul v-if="planFeatures.length" class="space-y-2.5">
                        <li v-for="feature in planFeatures" :key="feature.key" class="flex items-center gap-2.5 text-sm text-slate-700 dark:text-slate-300">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-500/15"><PhCheck :size="11" weight="bold" class="text-emerald-600 dark:text-emerald-400" /></span>
                            {{ feature.name }}
                        </li>
                    </ul>
                    <p v-else-if="hasPlan" class="text-sm text-slate-500 dark:text-slate-400">Acesso à API com o limite do plano.</p>

                    <Button class="mt-6" block :variant="hasPlan ? 'outline' : 'primary'" :icon-right="PhArrowRight" to="/dashboard/plans">
                        {{ hasPlan ? 'Gerenciar plano' : 'Escolher plano' }}
                    </Button>
                </div>
            </Card>
        </div>

        <!-- Requisições recentes -->
        <Card class="mt-6" title="Requisições recentes" subtitle="Últimas chamadas à API">
            <template #actions>
                <Button variant="ghost" size="sm" :icon-right="PhArrowRight" to="/dashboard/requests">Ver todas</Button>
            </template>
            <DataTable
                :columns="columns"
                :rows="recentRequests"
                :loading="loading"
                empty-title="Nenhuma requisição ainda"
                empty-text="Quando sua aplicação chamar a API, as chamadas aparecem aqui."
                :empty-icon="PhPulse"
            >
                <template #cell-endpoint="{ row }"><span class="font-mono text-xs text-slate-900 dark:text-white">{{ row.endpoint }}</span></template>
                <template #cell-method="{ row }"><Badge :tone="methodTone(row.method)">{{ row.method }}</Badge></template>
                <template #cell-response_code="{ row }"><Badge :tone="statusTone(row.response_code)" dot>{{ row.response_code }}</Badge></template>
                <template #cell-requested_at="{ row }"><span class="text-slate-500 dark:text-slate-400">{{ format.relative(row.requested_at) }}</span></template>
            </DataTable>
        </Card>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import {
    PhLightning, PhChartLineUp, PhGauge, PhStack, PhCrown, PhCheck, PhArrowRight, PhBookOpen, PhKey, PhPulse,
} from '@phosphor-icons/vue';
import { Badge, Button, Card, DataTable, PageHeader, Skeleton, StatCard, Tabs, chartOptions, format } from '@/ui';
import type { BadgeTone, Column } from '@/ui';

const authStore = useAuthStore();
const dashboardStore = useDashboardStore();

const loading = ref(true);
const chartPeriod = ref('30');

const user = computed<any>(() => authStore.user);
const currentPlan = computed<any>(() => user.value?.active_plan?.plan);
const hasPlan = computed(() => !!currentPlan.value);
const usagePercentage = computed(() => Number(dashboardStore.usagePercentage) || 0);

const firstName = computed(() => {
    const first = String(user.value?.personal?.name || user.value?.name || '').split(' ')[0] ?? '';
    // Nome todo em maiúsculas vira "Mateus" na saudação.
    return first === first.toUpperCase() ? first.charAt(0) + first.slice(1).toLowerCase() : first;
});
const greeting = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite';
});

const renewsIn = computed(() => {
    const end = user.value?.active_plan?.dates?.end_date;
    return end ? format.relative(end) : 'breve';
});

const stats = computed(() => {
    const s: any = dashboardStore.stats || {};
    const limit = Number(s.total_requests_limit ?? 0);
    const remaining = Number(s.remaining_requests ?? 0);
    return {
        today: Number(s.today_requests ?? 0),
        limit,
        remaining,
        used: limit ? limit - remaining : Number(s.month_requests ?? 0),
    };
});

const planFeatures = computed<any[]>(() => currentPlan.value?.features ?? []);
const recentRequests = computed<any[]>(() => dashboardStore.requests.slice(0, 8));

const columns: Column[] = [
    { key: 'endpoint', label: 'Endpoint' },
    { key: 'method', label: 'Método' },
    { key: 'response_code', label: 'Status' },
    { key: 'requested_at', label: 'Quando', align: 'right' },
];

const chartSeries = computed(() => dashboardStore.chartSeries);
const usageChart = computed(() => chartOptions({
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0, stops: [0, 95, 100] } },
    xaxis: { categories: dashboardStore.chartCategories, tickAmount: 8, labels: { rotate: 0, hideOverlappingLabels: true } },
    yaxis: { labels: { formatter: (v: number) => Math.round(v).toLocaleString('pt-BR') } },
}));

function methodTone(method: string): BadgeTone {
    return ({ GET: 'success', POST: 'info', PUT: 'warning', PATCH: 'warning', DELETE: 'danger' } as Record<string, BadgeTone>)[method] ?? 'neutral';
}

function statusTone(code: number): BadgeTone {
    return code < 300 ? 'success' : code < 400 ? 'warning' : 'danger';
}

// Atualização em segundo plano. O contador do plano é agregado no servidor
// com cache curto: 30s dão a mesma leitura por uma fração da carga.
const POLL_MS = 30000;
let pollTimer: number | null = null;
let polling = false;

async function pollStats() {
    if (polling || document.hidden) return;
    polling = true;
    try {
        await dashboardStore.fetchLiveStats();
    } catch {
        // atualização de fundo: silenciosa
    } finally {
        polling = false;
    }
}

function startPolling() {
    stopPolling();
    pollTimer = window.setInterval(pollStats, POLL_MS);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function handleVisibility() {
    if (document.hidden) stopPolling();
    else {
        pollStats();
        startPolling();
    }
}

onMounted(async () => {
    try {
        await Promise.all([authStore.fetchProfile(), dashboardStore.fetchStats()]);
    } finally {
        loading.value = false;
    }
    startPolling();
    document.addEventListener('visibilitychange', handleVisibility);
});

onUnmounted(() => {
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibility);
});

watch(chartPeriod, (p) => dashboardStore.fetchStats(parseInt(p)));
</script>
