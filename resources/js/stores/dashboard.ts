//@ts-nocheck
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';
import {useAuthStore} from "@/stores/auth";

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

    // Dentro do useAuthStore
    async function fetchStats(days = 30) {
        loading.value = true;
        // Bug 2 fix: usar authStore em vez de "this"
        const authStore = useAuthStore();

        // Bug 1 fix: REMOVIDO o "return" solto que impedia a execução do try/catch
        try {
            const response = await axios.get('/dashboard/log');
            const logs = response.data?.data || [];

            // 1. Calcular o total de hoje com base nos logs
            const todayStr = new Date().toISOString().split('T')[0];
            const todayCount = logs.filter(log =>
                log.request?.requested_at?.startsWith(todayStr)
            ).length;

            // 2. Bug 2 fix: usar authStore.user em vez de this.user
            const used      = authStore.user?.usage?.requests_used       || 0;
            const limit     = authStore.user?.usage?.requests_limit      || 0;
            const remaining = authStore.user?.usage?.remaining_requests  || 0;

            // 3. Bug 3 fix: usar stats.value e requests.value (refs locais da store)
            stats.value = {
                today_requests:     todayCount,
                month_requests:     used,
                remaining_requests: remaining,
                total_requests_limit: limit,
                cache_hit_rate: 85,
            };

            requests.value = logs.map(log => ({
                id:            log.id,
                endpoint:      log.request?.endpoint,
                method:        log.request?.method,
                response_code: log.response?.code,
                requested_at:  log.request?.requested_at,
            }));

            const last30 = buildChartData(logs, days);
            chartCategories.value = last30.labels;
            chartSeries.value = [{ name: 'Requisições', data: last30.counts }];

        } catch (err) {
            console.error('Erro ao atualizar estatísticas:', err);
        } finally {
            loading.value = false;
        }
    }

    function buildChartData(logs, days) {
        const counts = {};
        for (let i = days - 1; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            counts[d.toISOString().split('T')[0]] = 0;
        }
        logs.forEach(log => {
            const date = log.request?.requested_at?.substring(0, 10);
            if (date && counts[date] !== undefined) counts[date]++;
        });
        const keys = Object.keys(counts).sort();
        return {
            labels: keys.map(k => { const [, m, d] = k.split('-'); return `${d}/${m}`; }),
            counts: keys.map(k => counts[k]),
        };
    }

    async function fetchRequests(params = {}) {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/log', { params: { per_page: 20, ...params } });
            const logs = response.data?.data || response.data || [];

            // Mapear igual ao fetchStats, senão os campos chegam undefined na view
            requests.value = logs.map(log => ({
                id:            log.id,
                endpoint:      log.request?.endpoint,
                method:        log.request?.method,
                response_code: log.response?.code,
                requested_at:  log.request?.requested_at,
            }));

            return response.data;
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
            billingHistory.value = response.data?.data || response.data || [];
            return response.data;
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
