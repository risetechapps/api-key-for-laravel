<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Estornos</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Gerencie estornos de pagamentos via Mercado Pago</p>
        </div>

        <Card>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Usuário</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Plano</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Valor</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Período</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Status</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Payment ID</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="loading" v-for="i in 4" :key="i">
                            <td v-for="j in 7" :key="j" class="py-3 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td colspan="7" class="py-10 text-center text-slate-500 dark:text-slate-400">Nenhuma compra com pagamento registrado</td>
                        </tr>
                        <tr v-else v-for="sub in rows" :key="sub.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="py-3 px-4">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ sub.user?.name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ sub.user?.email }}</p>
                            </td>
                            <td class="py-3 px-4 text-sm font-medium text-slate-900 dark:text-white">{{ sub.plan?.name }}</td>
                            <td class="py-3 px-4 text-sm text-slate-700 dark:text-slate-300">{{ sub.payment_amount }}</td>
                            <td class="py-3 px-4 text-sm text-slate-700 dark:text-slate-300">
                                {{ formatDate(sub.start_date) }} - {{ formatDate(sub.end_date) }}
                            </td>
                            <td class="py-3 px-4">
                                <span :class="sub.active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'"
                                      class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium">
                                    {{ sub.active ? 'Ativo' : 'Cancelado' }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ sub.payment_id }}</span>
                            </td>
                            <td class="py-3 px-4">
                                <Button
                                    v-if="sub.active"
                                    variant="outline"
                                    size="sm"
                                    :loading="processingId === sub.id"
                                    @click="refund(sub)"
                                    class="text-red-500 border-red-200 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/20"
                                >
                                    <PhArrowCounterClockwise :size="14" />
                                    Estornar
                                </Button>
                                <span v-else class="text-xs text-slate-400">Estornado</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ total }} registros</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="page <= 1" @click="changePage(page - 1)">Anterior</Button>
                    <Button variant="outline" size="sm" :disabled="page >= totalPages" @click="changePage(page + 1)">Próximo</Button>
                </div>
            </div>
        </Card>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { format, parseISO, isValid } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { PhArrowCounterClockwise } from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import { useAdminStore } from '@/stores/admin';
import Card from '@/views/componentes/Card.vue';
import Button from '@/views/componentes/Button.vue';

const adminStore  = useAdminStore();
const loading     = computed(() => adminStore.loading);
const refundsData = computed(() => adminStore.refunds);
const rows        = computed(() => refundsData.value?.data ?? []);
const total       = computed(() => refundsData.value?.total ?? 0);
const totalPages  = computed(() => refundsData.value?.last_page ?? 1);

const page         = ref(1);
const processingId = ref<string | null>(null);

onMounted(() => adminStore.fetchRefunds({ page: page.value }));

function changePage(p: number) {
    page.value = p;
    adminStore.fetchRefunds({ page: p });
}

async function refund(sub: any) {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Confirmar estorno?',
        html: `Isso irá estornar o pagamento <strong>#${sub.payment_id}</strong> do usuário <strong>${sub.user?.name}</strong> e cancelar a assinatura.`,
        showCancelButton: true,
        confirmButtonText: 'Sim, estornar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    });

    if (!result.isConfirmed) return;

    processingId.value = sub.id;
    try {
        await adminStore.processRefund(sub.id);
        await Swal.fire({
            icon: 'success',
            title: 'Estorno realizado!',
            text: 'O pagamento foi estornado e a assinatura cancelada.',
            confirmButtonText: 'Ok',
            confirmButtonColor: '#6366f1',
        });
    } catch (err: any) {
        await Swal.fire({
            icon: 'error',
            title: 'Erro no estorno',
            text: err?.response?.data?.message || 'Não foi possível processar o estorno.',
            confirmButtonText: 'Ok',
        });
    } finally {
        processingId.value = null;
    }
}

function formatDate(date: string) {
    if (!date) return '—';
    try {
        const d = typeof date === 'string' ? parseISO(date) : new Date(date);
        return isValid(d) ? format(d, 'dd/MM/yyyy', { locale: ptBR }) : '—';
    } catch {
        return '—';
    }
}
</script>
