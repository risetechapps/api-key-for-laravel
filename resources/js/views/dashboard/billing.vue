<template>
    <div class="space-y-6">
        <!-- Billing Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card v-for="stat in summaryStats" :key="stat.name">
                <div class="flex items-center gap-4">
                    <div
                        class="w-12 h-12 rounded-xl flex items-center justify-center"
                        :class="stat.bgClass"
                    >
                        <component :is="stat.icon" :size="24" :class="stat.iconClass" />
                    </div>
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ stat.name }}</p>
                        <p class="text-xl font-bold text-slate-900 dark:text-white">{{ stat.value }}</p>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Subscription History -->
        <Card title="Histórico de Assinaturas" subtitle="Suas assinaturas anteriores">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Plano</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Período</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Status</th>
                            <th class="text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-4 px-4">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <!-- Loading -->
                        <tr v-if="loading" v-for="i in 3" :key="`loading-${i}`">
                            <td v-for="j in 4" :key="`loading-col-${j}`" class="py-4 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>

                        <!-- Empty -->
                        <tr v-else-if="billingHistory.length === 0">
                            <td colspan="4" class="py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                                        <PhReceipt :size="32" class="text-slate-400" />
                                    </div>
                                    <p class="text-slate-500 dark:text-slate-400">Nenhuma assinatura encontrada</p>
                                </div>
                            </td>
                        </tr>

                        <!-- Data -->
                        <tr
                            v-else
                            v-for="subscription in billingHistory"
                            :key="subscription.id"
                            class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                        >
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-10 h-10 rounded-lg flex items-center justify-center"
                                        :class="getPlanColor(subscription.plan?.name)"
                                    >
                                        <PhCrown :size="20" weight="fill" class="text-white" />
                                    </div>
                                    <span class="font-medium text-slate-900 dark:text-white">{{ subscription.plan?.name }}</span>
                                </div>
                            </td>
                            <td class="py-4 px-4">
                                <span class="text-sm text-slate-700 dark:text-slate-300">
                                    {{ formatDate(subscription.dates?.start_date) }} - {{ formatDate(subscription.dates?.end_date) }}
                                </span>
                            </td>
                            <td class="py-4 px-4">
                                <span
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium"
                                    :class="getStatusClass(subscription.status?.active)"
                                >
                                    {{ subscription.status?.active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="py-4 px-4 text-right">
                                <span class="font-medium text-slate-900 dark:text-white">
                                    {{ subscription.plan?.price ?? formatPrice(subscription.plan?.raw_price) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Payment Methods -->
        <Card title="Métodos de Pagamento" subtitle="Gerencie seus cartões">
            <!-- Saved cards -->
            <div v-if="savedCards.length > 0" class="space-y-3 mb-5">
                <div
                    v-for="card in savedCards"
                    :key="card.id"
                    class="flex items-center justify-between p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50"
                >
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
                            <PhCreditCard :size="20" class="text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white capitalize">
                                {{ card.brand }} •••• {{ card.last_four }}
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ card.holder_name }} · {{ card.expiry_month }}/{{ card.expiry_year }}
                            </p>
                        </div>
                    </div>
                    <button
                        @click="removeCard(card.id)"
                        class="p-2 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                        title="Remover cartão"
                    >
                        <PhTrash :size="16" />
                    </button>
                </div>
            </div>

            <!-- Empty / Add button -->
            <div v-if="savedCards.length === 0" class="flex flex-col items-center justify-center py-8 text-center mb-4">
                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                    <PhCreditCard :size="32" class="text-slate-400" />
                </div>
                <h3 class="text-lg font-medium text-slate-900 dark:text-white mb-2">Nenhum cartão cadastrado</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 max-w-md mb-2">
                    Adicione um cartão para facilitar futuras assinaturas. O cartão será validado com segurança.
                </p>
            </div>

            <Button variant="outline" @click="showAddCard = true">
                <PhPlus :size="18" />
                Adicionar cartão
            </Button>
        </Card>

        <!-- Add Card Modal -->
        <AddCardModal v-model="showAddCard" @saved="onCardSaved" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { format, parseISO, isValid } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import {
    PhReceipt,
    PhCreditCard,
    PhPlus,
    PhCrown,
    PhCalendar,
    PhCoins,
    PhTrash,
} from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import Card from "@/views/componentes/Card.vue";
import Button from "@/views/componentes/Button.vue";
import AddCardModal from "@/views/dashboard/AddCardModal.vue";

const authStore      = useAuthStore();
const dashboardStore = useDashboardStore();

const loading     = ref(true);
const showAddCard = ref(false);

const user           = computed(() => authStore.user);
const billingHistory = computed(() => dashboardStore.billingHistory);
const savedCards     = computed(() => dashboardStore.savedCards);

const activePlan     = computed(() => user.value?.active_plan);
const activePlanInfo = computed(() => activePlan.value?.plan);

const summaryStats = computed(() => [
    {
        name: 'Plano Atual',
        value: activePlanInfo.value?.name || 'Gratuito',
        icon: PhCrown,
        bgClass: 'bg-indigo-100 dark:bg-indigo-900/30',
        iconClass: 'text-indigo-600 dark:text-indigo-400',
    },
    {
        name: 'Próxima Cobrança',
        value: formatDate(activePlan.value?.dates?.end_date) || 'N/A',
        icon: PhCalendar,
        bgClass: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconClass: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        name: 'Total Gasto',
        value: `R$ ${totalSpent.value.toFixed(2).replace('.', ',')}`,
        icon: PhCoins,
        bgClass: 'bg-amber-100 dark:bg-amber-900/30',
        iconClass: 'text-amber-600 dark:text-amber-400',
    },
]);

const totalSpent = computed(() =>
    billingHistory.value.reduce((acc, sub) => acc + (sub.plan?.raw_price || 0), 0)
);

onMounted(async () => {
    await Promise.all([
        authStore.fetchProfile(),
        dashboardStore.fetchBillingHistory(),
        dashboardStore.fetchSavedCards(),
    ]);
    loading.value = false;
});

function formatDate(date) {
    if (!date) return '—';
    try {
        const d = typeof date === 'string' ? parseISO(date) : new Date(date);
        return isValid(d) ? format(d, 'dd/MM/yyyy', { locale: ptBR }) : '—';
    } catch {
        return '—';
    }
}

function formatPrice(value) {
    if (!value && value !== 0) return '-';
    return 'R$ ' + Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

function getPlanColor(planName) {
    if (planName?.toLowerCase().includes('pro'))      return 'bg-purple-500';
    if (planName?.toLowerCase().includes('business')) return 'bg-emerald-500';
    return 'bg-indigo-500';
}

function getStatusClass(active) {
    return active
        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
        : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-400';
}

async function removeCard(id) {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Remover cartão?',
        text: 'Tem certeza que deseja remover este cartão?',
        showCancelButton: true,
        confirmButtonText: 'Sim, remover',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    });

    if (result.isConfirmed) {
        await dashboardStore.deleteSavedCard(id);
    }
}

async function onCardSaved() {
    await dashboardStore.fetchSavedCards();
}
</script>
