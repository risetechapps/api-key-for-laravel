<template>
    <div>
        <PageHeader title="Métricas" subtitle="Receita recorrente, assinantes, cancelamentos e conversão" :icon="PhChartLineUp" />

        <div class="ui-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard v-for="(tile, i) in tiles" :key="tile.label" :style="{ '--i': i }" v-bind="tile" :loading="loading" />
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Receita por mês" subtitle="Assinaturas pagas, sem estornos">
                <div class="-mx-2 h-80">
                    <Skeleton v-if="loading" height="100%" rounded="rounded-xl" />
                    <apexchart v-else type="bar" height="320" :options="chart" :series="series" />
                </div>
            </Card>

            <Card title="Por plano" subtitle="Assinantes ativos e MRR">
                <div v-if="loading" class="space-y-3"><Skeleton v-for="i in 4" :key="i" height="2.5rem" /></div>
                <ul v-else-if="m?.by_plan?.length" class="space-y-4">
                    <li v-for="row in m.by_plan" :key="row.plan">
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-900 dark:text-white">{{ row.plan }} <span class="font-normal text-slate-500">· {{ row.subscribers }}</span></span>
                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ format.money(row.mrr) }}</span>
                        </div>
                        <Progress :value="row.mrr" :max="maxPlanMrr" tone="brand" size="sm" />
                    </li>
                </ul>
                <EmptyState v-else compact title="Sem assinantes pagos" :icon="PhUsers" />
            </Card>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { PhChartLineUp, PhCurrencyCircleDollar, PhUsers, PhUserPlus, PhUserMinus, PhCoins, PhArrowCounterClockwise, PhFunnel, PhArrowsClockwise } from '@phosphor-icons/vue';
import { Card, EmptyState, PageHeader, Progress, Skeleton, StatCard, chartOptions, errorMessage, format, toast } from '@/ui';

const m = ref<any>(null);
const loading = ref(true);

onMounted(async () => {
    try {
        const r = await axios.get('/dashboard/admin/metrics');
        m.value = r.data?.data;
    } catch (err) {
        toast.error('Não foi possível carregar as métricas', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
});

const tiles = computed(() => {
    const d = m.value ?? {};
    return [
        { label: 'MRR', value: Number(d.mrr ?? 0), format: 'money' as const, digits: 2, icon: PhCurrencyCircleDollar, hint: `ARR ${format.money(d.arr)}` },
        { label: 'Assinantes pagos', value: Number(d.active_subscribers ?? 0), icon: PhUsers, tone: 'info' as const, hint: `${d.active_trials ?? 0} em teste` },
        { label: 'Novos (30 dias)', value: Number(d.new_subscribers_30d ?? 0), icon: PhUserPlus, tone: 'success' as const },
        { label: 'Cancelamento (30 dias)', value: Number(d.churn_rate_30d ?? 0), format: 'percent' as const, digits: 1, icon: PhUserMinus, tone: 'warning' as const, hint: `${d.churned_30d ?? 0} contas saíram` },
        { label: 'Receita (30 dias)', value: Number(d.revenue_30d ?? 0), format: 'money' as const, digits: 2, icon: PhCoins, tone: 'success' as const },
        { label: 'Estornos (30 dias)', value: Number(d.refunds_30d ?? 0), format: 'money' as const, digits: 2, icon: PhArrowCounterClockwise, tone: 'danger' as const },
        { label: 'Conversão de teste (90 dias)', value: Number(d.trial_conversion_90d?.rate ?? 0), format: 'percent' as const, digits: 1, icon: PhFunnel, tone: 'brand' as const, hint: `${d.trial_conversion_90d?.converted ?? 0} de ${d.trial_conversion_90d?.trials ?? 0}` },
        { label: 'Renovações em nova tentativa', value: Number(d.renewals_in_retry ?? 0), icon: PhArrowsClockwise, tone: 'warning' as const, to: '/dashboard/admin/delinquency', hint: 'ver inadimplência' },
    ];
});

const maxPlanMrr = computed(() => Math.max(1, ...((m.value?.by_plan ?? []).map((r: any) => Number(r.mrr) || 0))));

const series = computed(() => [{ name: 'Receita', data: (m.value?.revenue_by_month ?? []).map((r: any) => r.revenue) }]);

const chart = computed(() => chartOptions({
    plotOptions: { bar: { borderRadius: 6, columnWidth: '50%' } },
    fill: { type: 'gradient', gradient: { type: 'vertical', shadeIntensity: 0.3, opacityFrom: 1, opacityTo: 0.75, stops: [0, 100] } },
    xaxis: { categories: (m.value?.revenue_by_month ?? []).map((r: any) => r.month.split('-').reverse().join('/')) },
    yaxis: { labels: { formatter: (v: number) => format.money(v) } },
    tooltip: { y: { formatter: (v: number) => format.money(v) } },
}));
</script>
