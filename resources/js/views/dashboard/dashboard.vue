<template>
    <div class="space-y-6">
        <!-- Stats Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <Card v-for="stat in stats" :key="stat.name" class="hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ stat.name }}</p>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ stat.value }}</p>
                    </div>
                    <div
                        class="w-12 h-12 rounded-xl flex items-center justify-center"
                        :class="stat.bgClass"
                    >
                        <component :is="stat.icon" :size="24" :class="stat.iconClass"/>
                    </div>
                </div>
                <div v-if="stat.change?.value" class="mt-4 flex items-center gap-1">
                    <PhTrendUp v-if="stat.change.trend === 'up'" :size="16" class="text-emerald-500"/>
                    <PhTrendDown v-else :size="16" class="text-red-500"/>
                    <span :class="stat.change.trend === 'up' ? 'text-emerald-500' : 'text-red-500'"
                          class="text-sm font-medium">
                        {{ stat.change.value }}
                    </span>
                </div>
            </Card>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Usage Chart -->
            <Card class="lg:col-span-2">
                <template #header>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Uso de Requisições</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Últimos 30 dias</p>
                        </div>
                        <select
                            v-model="chartPeriod"
                            class="text-sm border-slate-200 dark:border-slate-700 rounded-lg dark:bg-slate-800"
                        >
                            <option value="7">7 dias</option>
                            <option value="30">30 dias</option>
                            <option value="90">90 dias</option>
                        </select>
                    </div>
                </template>

                <div class="h-80">
                    <apexchart
                        type="area"
                        height="320"
                        :options="chartOptions"
                        :series="chartSeries"
                    />
                </div>
            </Card>

            <!-- Plan Status -->
            <Card>
                <template #header>
                    <div class="flex items-center gap-2">
                        <PhCrown :size="20" weight="fill" class="text-amber-500"/>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Plano Atual</h3>
                    </div>
                </template>

                <div class="space-y-6">
                    <div class="text-center">
                        <h4 class="text-xl font-bold text-slate-900 dark:text-white">
                            {{ currentPlan?.name || 'Gratuito' }}
                        </h4>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                            {{ currentPlan?.description || 'Plano básico para testes' }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Requisições usadas</span>
                            <span class="font-medium text-slate-900 dark:text-white">
                                {{ usedRequests }} / {{ totalRequests }}
                            </span>
                        </div>
                        <div class="h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :class="usageBarColor"
                                :style="{ width: `${usagePercentage}%` }"
                            />
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div v-for="feature in planFeatures" :key="feature.name" class="flex items-center gap-3">
                            <PhCheckCircle :size="20" weight="fill" class="text-emerald-500"/>
                            <span class="text-sm text-slate-700 dark:text-slate-300">{{ feature.name }}</span>
                        </div>
                    </div>

                    <router-link
                        to="/dashboard/plans"
                        class="block w-full text-center py-3 rounded-xl font-medium transition-colors"
                        :class="currentPlan?.name === 'Gratuito'
                            ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                            : 'bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 ' +
                             'text-slate-700 dark:text-slate-200'"
                    >
                        {{ currentPlan?.name === 'Gratuito' ? 'Fazer Upgrade' : 'Gerenciar Plano' }}
                    </router-link>
                </div>
            </Card>
        </div>

        <!-- Recent Requests -->
        <Card>
            <template #header>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Requisições Recentes</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Últimas chamadas à API</p>
                    </div>
                    <router-link
                        to="/dashboard/requests"
                        class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                    >
                        Ver todas →
                    </router-link>
                </div>
            </template>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-700">
                        <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase
                            tracking-wider py-3 px-4">Endpoint
                        </th>
                        <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase
                            tracking-wider py-3 px-4">Método
                        </th>
                        <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase
                            tracking-wider py-3 px-4">Status
                        </th>
                        <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase
                            tracking-wider py-3 px-4">Data
                        </th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <tr v-if="loading" v-for="i in 5" :key="i">
                        <td v-for="j in 4" :key="j" class="py-3 px-4">
                            <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                        </td>
                    </tr>

                    <tr
                        v-for="request in recentRequests"
                        :key="request.id"
                        class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                    >
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-sm text-slate-900 dark:text-white">
                                    {{ request.endpoint }}
                                </span>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                    :class="getMethodClass(request.method)">
                                    {{ request.method }}
                                </span>
                        </td>
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full"
                                        :class="getStatusColor(request.response_code)"
                                    />
                                <span class="text-sm text-slate-700 dark:text-slate-300">
                                    {{ request.response_code }}
                                </span>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                                <span class="text-sm text-slate-500 dark:text-slate-400">
                                    {{ formatDate(request.requested_at) }}
                                </span>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import {format} from 'date-fns';
import {ptBR} from 'date-fns/locale';
import {useAuthStore} from '@/stores/auth';
import {useDashboardStore} from '@/stores/dashboard';
import {
    PhArrowsClockwise,
    PhTrendUp,
    PhTrendDown,
    PhGauge,
    PhHardDrives,
    PhCrown,
    PhCheckCircle,
} from '@phosphor-icons/vue';
import Card from "@/views/componentes/Card.vue";

const authStore = useAuthStore();
const dashboardStore = useDashboardStore();

const loading = ref(true);
const chartPeriod = ref('30');

const user = computed(() => authStore.user);
const currentPlan = computed(() => user.value?.active_plan?.plan);
// Usa usage da estrutura do ProfileResource
const usedRequests = computed(() => user.value?.usage?.requests_used || user.value?.active_plan?.requests_used || 0);
const totalRequests = computed(() => user.value?.usage?.requests_limit || currentPlan.value?.request_limit || 0);
const usagePercentage = computed(() => dashboardStore.usagePercentage);

const usageBarColor = computed(() => {
    if (usagePercentage.value < 50) return 'bg-emerald-500';
    if (usagePercentage.value < 80) return 'bg-amber-500';
    return 'bg-red-500';
});

// Calcula variação percentual (se houver dados anteriores)
const calculateChange = (current, previous) => {
    if (!previous || previous === 0) return null;
    const change = ((current - previous) / previous) * 100;
    return {
        value: `${Math.abs(change).toFixed(1)}%`,
        trend: change >= 0 ? 'up' : 'down',
    };
};

const stats = computed(() => {
    const s = dashboardStore.stats || {};

    const today = s.today_requests ?? 0;
    const remaining = s.remaining_requests ?? 0;
    const total = s.total_requests_limit ?? 0;
    const used = total - remaining;

    return [
        {
            name: 'Requisições Hoje',
            value: today,
            icon: PhArrowsClockwise,
            bgClass: 'bg-indigo-100 dark:bg-indigo-900/30',
            iconClass: 'text-indigo-600 dark:text-indigo-400',
            // Só mostra variação se tiver dados reais
            change: today > 0 ? {value: 'Hoje', trend: 'up'} : null,
        },
        {
            name: 'Este Mês',
            value: used,
            icon: PhGauge,
            bgClass: 'bg-emerald-100 dark:bg-emerald-900/30',
            iconClass: 'text-emerald-600 dark:text-emerald-400',
        },
        {
            name: 'Cache Hit Rate',
            value: `${dashboardStore.stats.cache_hit_rate || 0}%`,
            icon: PhHardDrives,
            bgClass: 'bg-amber-100 dark:bg-amber-900/30',
            iconClass: 'text-amber-600 dark:text-amber-400',
        },
        {
            name: 'Restantes',
            value: remaining,
            icon: PhArrowsClockwise,
            bgClass: 'bg-purple-100 dark:bg-purple-900/30',
            iconClass: 'text-purple-600 dark:text-purple-400',
        },
    ];
});

const planFeatures = [
    {name: 'Módulo A', enabled: true},
    {name: 'Módulo B', enabled: true},
    {name: 'Módulo C', enabled: true},
];

const recentRequests = computed(() => dashboardStore.requests.slice(0, 10));

const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        toolbar: {show: false},
        font: {family: 'Inter, sans-serif'},
    },
    colors: ['#6366f1'],
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.7,
            opacityTo: 0.2,
            stops: [0, 90, 100],
        },
    },
    dataLabels: {enabled: false},
    stroke: {curve: 'smooth', width: 2},
    xaxis: {
        categories: chartCategories.value,
        labels: {
            style: {colors: '#94a3b8'},
        },
        axisBorder: {show: false},
        axisTicks: {show: false},
    },
    yaxis: {
        labels: {
            style: {colors: '#94a3b8'},
            formatter: (value) => Math.round(value),
        },
    },
    grid: {
        borderColor: '#e2e8f0',
        strokeDashArray: 4,
    },
    theme: {
        mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    },
}));

// Substituir o const chartSeries = ref([...]) hardcoded por:
const chartSeries = computed(() => dashboardStore.chartSeries);
const chartCategories = computed(() => dashboardStore.chartCategories);

onMounted(async () => {
    await Promise.all([
        authStore.fetchProfile(),
        dashboardStore.fetchStats(),
        // dashboardStore.fetchRequests(),
    ]);
    loading.value = false;
});

const formatDate = (date) => {
    return format(new Date(date), 'dd/MM/yyyy HH:mm', {locale: ptBR});
}

const getMethodClass = (method) => {
    const classes = {
        GET: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        POST: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        PUT: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        DELETE: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    };
    return classes[method] || classes.GET;
}

const getStatusColor = (code) => {
    if (code < 300) return 'bg-emerald-500';
    if (code < 400) return 'bg-amber-500';
    return 'bg-red-500';
}

watch(chartPeriod, (newPeriod) => {
    dashboardStore.fetchStats(parseInt(newPeriod));
});
</script>
