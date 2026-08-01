import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export interface DashboardStats {
    today_requests: number;
    month_requests: number;
    remaining_requests: number;
    total_requests_limit: number;
}

export interface RequestLogEntry {
    id: string;
    endpoint?: string;
    method?: string;
    response_code?: number;
    requested_at?: string;
}

export interface Plan {
    id: string;
    name: string;
    description?: string;
    price?: string;
    raw_price?: number;
    request_limit?: number;
    features?: Array<{ key: string; name: string; description?: string; icon?: string }>;
}

export interface Subscription {
    id: string;
    status?: { active: boolean; label: string };
    payment?: { amount: number | null; credit_applied: number | null; payment_id: string | null };
    cancellation?: { cancelled: boolean; cancelled_at: string | null };
    dates?: { start_date?: string; end_date?: string };
    plan?: Plan;
}

/** Motivos pelos quais o estorno pode ser recusado, espelhando RefundDecision. */
export type RefundReason =
    | 'eligible'
    | 'refund_disabled'
    | 'nothing_to_refund'
    | 'already_refunded'
    | 'window_expired'
    | 'usage_exceeded';

export interface RefundPreview {
    eligible: boolean;
    reason: RefundReason;
    message: string;
    amount: number;
    /** Null quando há estorno: o acesso termina no ato, não no vencimento. */
    access_until: string | null;
}

export interface CancelResult {
    cancelled_at: string | null;
    access_until: string | null;
    message: string;
    refunded: boolean;
    refunded_amount?: number;
    refund_refused_reason?: RefundReason;
}

export interface SavedCard {
    id: number;
    brand: string;
    last_four: string;
    holder_name: string;
    expiry_month: string;
    expiry_year: string;
    is_default: boolean;
}

interface StatsPayload {
    today?: number;
    used?: number;
    remaining?: number;
    limit?: number;
    series?: Array<{ date: string; total: number }>;
}

/**
 * Desembrulha o envelope padrão da API: { success: true, data: ... }.
 * Cai para o corpo cru quando a resposta não vem embrulhada.
 */
function unwrap<T>(response: any): T {
    return (response?.data?.data ?? response?.data) as T;
}

export const useDashboardStore = defineStore('dashboard', () => {
    const stats = ref<DashboardStats>({
        today_requests: 0,
        month_requests: 0,
        remaining_requests: 0,
        total_requests_limit: 0,
    });
    const requests = ref<RequestLogEntry[]>([]);
    const plans = ref<Plan[]>([]);
    const currentPlan = ref<Plan | null>(null);
    const billingHistory = ref<Subscription[]>([]);
    const savedCards = ref<SavedCard[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const chartSeries = ref<Array<{ name: string; data: number[] }>>([{ name: 'Requisições', data: [] }]);
    const chartCategories = ref<string[]>([]);

    const usagePercentage = computed(() => {
        if (stats.value.total_requests_limit === 0) return 0;
        return Math.round(((stats.value.total_requests_limit - stats.value.remaining_requests) / stats.value.total_requests_limit) * 100);
    });

    // Contadores, série do gráfico e primeira página do log.
    //
    // A versão anterior baixava /dashboard/log inteiro e contava as linhas no
    // browser — uma leitura da tabela completa por carregamento de página. Agora
    // a agregação vem pronta do servidor e o log chega paginado.
    async function fetchStats(days = 30): Promise<void> {
        loading.value = true;

        try {
            const [statsResponse, logResponse] = await Promise.all([
                axios.get('/dashboard/stats', { params: { days } }),
                axios.get('/dashboard/log', { params: { per_page: 20 } }),
            ]);

            const s = unwrap<StatsPayload>(statsResponse) ?? {};

            stats.value = {
                today_requests:       s.today ?? 0,
                month_requests:       s.used ?? 0,
                remaining_requests:   s.remaining ?? 0,
                total_requests_limit: s.limit ?? 0,
            };

            requests.value = mapLogs(logResponse.data?.data?.data ?? []);

            const series = s.series ?? [];
            chartCategories.value = series.map((p) => {
                const [, m, d] = p.date.split('-');
                return `${d}/${m}`;
            });
            chartSeries.value = [{ name: 'Requisições', data: series.map((p) => p.total) }];

        } catch (err) {
            console.error('Erro ao atualizar estatísticas:', err);
        } finally {
            loading.value = false;
        }
    }

    function mapLogs(logs: any[]): RequestLogEntry[] {
        return logs.map((log) => ({
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
    async function fetchLiveStats(): Promise<void> {
        const response = await axios.get('/dashboard/stats');
        const s = unwrap<StatsPayload>(response) ?? {};

        stats.value = {
            ...stats.value,
            today_requests:       s.today ?? 0,
            month_requests:       s.used ?? 0,
            remaining_requests:   s.remaining ?? 0,
            total_requests_limit: s.limit ?? 0,
        };
    }

    async function fetchRequests(params: Record<string, unknown> = {}) {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/log', { params: { per_page: 20, ...params } });
            const payload = response.data?.data ?? {};

            requests.value = mapLogs(payload.data ?? []);

            return payload;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao carregar requisições';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function fetchPlans(): Promise<Plan[]> {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/plans');
            plans.value = unwrap<Plan[]>(response) ?? [];
            return plans.value;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao carregar planos';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    // Ativa um plano gratuito. O campo é `plan`, não `plan_id`: mandava a chave
    // errada e a rota respondia 422 em toda chamada. Plano com preço não passa
    // por aqui — é o processCheckout, que cobra antes de assinar.
    async function subscribeToPlan(planId: string) {
        loading.value = true;
        try {
            const response = await axios.post('/dashboard/signature', { plan: planId });
            const data = unwrap<{ plan?: Plan }>(response);
            currentPlan.value = data?.plan ?? null;
            return data;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao assinar plano';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    // O que acontece se cancelar agora, consultado ANTES da confirmação para a
    // tela não prometer o que não vai cumprir: com estorno concedido o acesso
    // termina no ato, sem estorno ele segue até o fim do período pago.
    async function fetchRefundPreview() {
        const response = await axios.get('/dashboard/signature/refund-preview');
        return unwrap<RefundPreview>(response);
    }

    // Para a renovação automática. Só revoga quando há estorno — devolver o
    // dinheiro e manter o período seria entregá-lo de graça. A resposta diz qual
    // dos dois aconteceu.
    async function cancelSubscription() {
        loading.value = true;
        try {
            const response = await axios.post('/dashboard/signature/cancel');
            return unwrap<CancelResult>(response);
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao cancelar assinatura';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    // Desfaz o cancelamento enquanto o período corre.
    async function resumeSubscription() {
        loading.value = true;
        try {
            const response = await axios.post('/dashboard/signature/resume');
            return unwrap<{ renews_on: string; message: string }>(response);
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao reativar renovação';
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
                // Gerada uma vez por tentativa de compra e reenviada em todo retry:
                // é o que permite ao gateway reconhecer o reenvio do formulário como
                // a mesma cobrança em vez de criar uma segunda.
                idempotency_key: idempotencyKeyFor(planId),
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
            return unwrap(response);
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao processar pagamento';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    // Uma chave por plano, mantida enquanto a página do checkout viver. Reenviar o
    // formulário reaproveita a mesma; trocar de plano gera outra.
    const idempotencyKeys = new Map<string, string>();

    function idempotencyKeyFor(planId: string): string {
        const existing = idempotencyKeys.get(planId);
        if (existing) return existing;

        const key = typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : `${planId}-${Date.now()}-${Math.random().toString(36).slice(2)}`;

        idempotencyKeys.set(planId, key);
        return key;
    }

    /** Chamado após uma compra concluir, para que a próxima seja uma cobrança nova. */
    function clearIdempotencyKey(planId: string): void {
        idempotencyKeys.delete(planId);
    }

    async function validateCoupon(code: string, planId: string) {
        const response = await axios.post('/dashboard/checkout/coupon', { code, plan_id: planId });
        return unwrap<{ coupon: string; discount: number; credit: number; original_price: number; final_price: number }>(response);
    }

    async function fetchBillingHistory() {
        loading.value = true;
        try {
            const response = await axios.get('/dashboard/history');
            const payload = response.data?.data ?? {};
            billingHistory.value = payload.data ?? [];
            return payload;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Erro ao carregar histórico';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function fetchSavedCards(): Promise<void> {
        try {
            const response = await axios.get('/dashboard/cards');
            savedCards.value = unwrap<SavedCard[]>(response) ?? [];
        } catch {
            savedCards.value = [];
        }
    }

    async function deleteSavedCard(id: number): Promise<void> {
        await axios.delete(`/dashboard/cards/${id}`);
        savedCards.value = savedCards.value.filter((c) => c.id !== id);
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
        fetchRefundPreview,
        cancelSubscription,
        resumeSubscription,
        processCheckout,
        clearIdempotencyKey,
        validateCoupon,
        fetchBillingHistory,
        fetchSavedCards,
        deleteSavedCard,
    };
});
