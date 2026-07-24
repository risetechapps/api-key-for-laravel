//@ts-nocheck
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export const useDashboardStore = defineStore('dashboard', () => {
    const stats = ref({
        today_requests: 0,
        month_requests: 0,
        remaining_requests: 0,
        total_requests_limit: 0,
        cache_hit_rate: 0,
    });
    const requests = ref([]);
    const plans = ref([]);
    const currentPlan = ref(null);
    const billingHistory = ref([]);
    const savedCards = ref([]);
    const loading = ref(false);
    const error = ref(null);

    const chartSeries = ref([{ name: 'Requisições', data: [] }]);
    const chartCategories = ref([]);

    const usagePercentage = computed(() => {
        if (stats.value.total_requests_limit === 0) return 0;
        return Math.round(((stats.value.total_requests_limit - stats.value.remaining_requests) / stats.value.total_requests_limit) * 100);
    });

    // Contadores, série do gráfico e primeira página do log.
    //
    // A versão anterior baixava /dashboard/log inteiro e contava as linhas no
    // browser — uma leitura da tabela completa por carregamento de página. Agora
    // a agregação vem pronta do servidor e o log chega paginado.
    async function fetchStats(days = 30) {
        loading.value = true;

        try {
            const [statsResponse, logResponse] = await Promise.all([
                axios.get('/dashboard/stats', { params: { days } }),
                axios.get('/dashboard/log', { params: { per_page: 20 } }),
            ]);

            const s = statsResponse.data?.data ?? statsResponse.data ?? {};

            stats.value = {
                today_requests:       s.today ?? 0,
                month_requests:       s.used ?? 0,
                remaining_requests:   s.remaining ?? 0,
                total_requests_limit: s.limit ?? 0,
                cache_hit_rate: 85,
            };

            requests.value = mapLogs(logResponse.data?.data?.data ?? []);

            const series = s.series ?? [];
            chartCategories.value = series.map(p => {
                const [, m, d] = p.date.split('-');
                return `${d}/${m}`;
            });
            chartSeries.value = [{ name: 'Requisições', data: series.map(p => p.total) }];

        } catch (err) {
            console.error('Erro ao atualizar estatísticas:', err);
        } finally {
            loading.value = false;
        }
    }

    function mapLogs(logs) {
        return logs.map(log => ({
            id:            log.id,
            endpoint:      log.request?.endpoint,
            method:        log.request?.method,
            response_code: log.response?.code,
            requested_at:  log.request?.requested_at,
        }));
    }

    // Stats leves para polling em tempo real. Bate no /dashboard/stats (COUNT +
    // contador do plano), nao carrega a lista de logs e nao mexe no loading
    // (para nao piscar skeleton durante o auto-refresh).
    async function fetchLiveStats() {
        const { data } = await axios.get('/dashboard/stats');
        const s = data?.data ?? data ?? {};

        stats.value = {
            ...stats.value,
            today_requests:       s.today ?? 0,
            month_requests:       s.used ?? 0,
            remaining_requests:   s.remaining ?? 0,
            total_requests_limit: s.limit ?? 0,
        };
    }

    async function fetchRequests(params = {}) {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/log', { params: { per_page: 20, ...params } });
            const payload = response.data?.data ?? {};

            requests.value = mapLogs(payload.data ?? []);

            return payload;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao carregar requisições';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function fetchPlans() {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/plans');
            // A API retorna { success: true, data: [...] }
            plans.value = response.data?.data || response.data || [];
            return response.data;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao carregar planos';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function subscribeToPlan(planId) {
        loading.value = true;
        try {
            const response = await axios.post('/dashboard/signature', { plan_id: planId });
            // A API retorna { success: true, data: {...} }
            const responseData = response.data?.data || response.data;
            currentPlan.value = responseData?.plan;
            return response.data;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao assinar plano';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function processCheckout(planId: string, formData: any, couponCode: string | null = null) {
        loading.value = true;
        try {
            const response = await axios.post('/dashboard/checkout/process', {
                plan_id: planId,
                coupon_code: couponCode ?? undefined,
                ...formData,
                additional_info: {
                    items: [
                        {
                            id:          planId,
                            title:       formData?.description ?? 'Assinatura de plano',
                            quantity:    1,
                            unit_price:  formData?.transaction_amount ?? 0,
                        },
                    ],
                },
            });
            return response.data?.data || response.data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao processar pagamento';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function validateCoupon(code: string, planId: string) {
        try {
            const response = await axios.post('/dashboard/checkout/coupon', { code, plan_id: planId });
            return response.data?.data || response.data;
        } catch (err: any) {
            throw err;
        }
    }

    async function fetchBillingHistory() {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/history');
            const payload = response.data?.data ?? {};
            billingHistory.value = payload.data ?? [];
            return payload;
        } catch (err) {
            error.value = err.response?.data?.message || 'Erro ao carregar histórico';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function fetchSavedCards() {
        try {
            const response = await axios.get('dashboard/cards');
            savedCards.value = response.data?.data || response.data || [];
        } catch (_) {
            savedCards.value = [];
        }
    }

    async function deleteSavedCard(id: number) {
        await axios.delete(`dashboard/cards/${id}`);
        savedCards.value = savedCards.value.filter((c: any) => c.id !== id);
    }

    async function testRequest(feature: string, params: Record<string, string> = {}) {
        const response = await axios.post('/dashboard/test-request', { feature, params });
        return response.data;
    }

    return {
        stats,
        requests,
        plans,
        currentPlan,
        billingHistory,
        savedCards,
        loading,
        error,
        chartSeries,
        chartCategories,
        usagePercentage,
        fetchStats,
        fetchLiveStats,
        fetchRequests,
        fetchPlans,
        subscribeToPlan,
        processCheckout,
        validateCoupon,
        fetchBillingHistory,
        fetchSavedCards,
        deleteSavedCard,
        testRequest,
    };
});
