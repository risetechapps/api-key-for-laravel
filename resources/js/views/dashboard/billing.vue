<template>
    <div>
        <PageHeader title="Faturamento" subtitle="Assinatura, cartões, excedente e recibos" :icon="PhReceipt" />

        <!-- Resumo -->
        <div class="ui-stagger grid grid-cols-1 gap-4 md:grid-cols-3">
            <StatCard style="--i: 0" label="Plano atual" :value="activePlanInfo?.name || 'Sem plano'" :icon="PhCrown" :loading="loading" :to="'/dashboard/plans'" hint="ver planos" />
            <StatCard style="--i: 1" :label="isCancelled ? 'Acesso até' : 'Próxima cobrança'" :value="format.date(activePlan?.dates?.end_date)" :icon="PhCalendarBlank" :tone="isCancelled ? 'warning' : 'success'" :loading="loading" :hint="isCancelled ? 'renovação cancelada' : 'renovação automática'" />
            <StatCard style="--i: 2" label="Total pago" :value="totalSpent" format="money" :digits="2" :icon="PhCoins" tone="info" :loading="loading" :hint="`${billingHistory.length} assinatura(s)`" />
        </div>

        <!-- Avisos -->
        <div class="mt-6 space-y-3">
            <Alert v-if="renewal?.required" tone="warning" title="Sua assinatura precisa ser renovada">
                <template v-if="renewal.reason === 'cvv_required'">Por segurança, a renovação no cartão precisa da sua confirmação.</template>
                <template v-else-if="renewal.reason === 'pix'">Como você paga com PIX, a renovação é feita por aqui.</template>
                <template v-else-if="renewal.reason === 'card_out_of_use'">O cartão salvo não é mais aceito para pagamento. Cadastre um cartão novo para a renovação automática.</template>
                <template v-else>Não há cartão para a renovação automática.</template>
                Seu acesso continua até {{ format.date(renewal.access_until) }}.
                <template #actions><Button size="sm" @click="showRenewCheckout = true">Renovar agora</Button></template>
            </Alert>

            <Alert v-else-if="renewalRetry" tone="danger" title="A renovação não foi aprovada">
                <template v-if="renewalRetry.next_attempt_at">Vamos tentar de novo em {{ format.date(renewalRetry.next_attempt_at) }}. Cadastre outro cartão para tentar agora.</template>
                <template v-else>As tentativas automáticas acabaram. Cadastre um cartão para tentarmos mais uma vez.</template>
                <template #actions><Button size="sm" variant="danger" :icon="PhPlus" @click="showAddCard = true">Cadastrar cartão</Button></template>
            </Alert>

            <Alert v-if="activePlan?.trial" tone="brand" title="Período de teste">
                Até {{ format.date(activePlan?.dates?.end_date) }}. Se não cancelar, a assinatura é cobrada nessa data.
            </Alert>

            <Alert v-if="activePlan?.overage?.requests > 0" tone="warning" title="Você passou do limite do plano">
                {{ activePlan.overage.requests.toLocaleString('pt-BR') }} requisições além do limite neste período:
                {{ format.money(activePlan.overage.estimated_amount) }} a cobrar no fim do período.
            </Alert>
        </div>

        <Tabs v-model="tab" class="mt-8" :tabs="tabs" label="Seções do faturamento">
            <template #default="{ active }">
                <!-- Visão geral -->
                <div v-if="active === 'overview'" class="space-y-6">
                    <Card v-if="activePlan" title="Assinatura" subtitle="Renovação automática do plano">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', isCancelled ? 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300']">
                                    <component :is="isCancelled ? PhPauseCircle : PhArrowsClockwise" :size="20" weight="duotone" />
                                </span>
                                <div>
                                    <p class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ isCancelled ? 'Renovação cancelada' : 'Renovação ativa' }}
                                        <Badge :tone="isCancelled ? 'warning' : 'success'" dot :pulse="!isCancelled">{{ isCancelled ? 'Cancelada' : 'Ativa' }}</Badge>
                                    </p>
                                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                                        <template v-if="isCancelled">Seu acesso continua até {{ format.date(activePlan?.dates?.end_date) }} e nenhuma nova cobrança será feita.</template>
                                        <template v-else>Seu plano será renovado automaticamente em {{ format.date(activePlan?.dates?.end_date) }}.</template>
                                    </p>
                                </div>
                            </div>
                            <Button :variant="isCancelled ? 'primary' : 'outline'" :loading="subscriptionBusy" @click="isCancelled ? resumeSubscription() : cancelSubscription()">
                                {{ isCancelled ? 'Reativar renovação' : 'Cancelar renovação' }}
                            </Button>
                        </div>
                    </Card>

                    <Card v-else>
                        <EmptyState title="Você ainda não tem um plano" description="Assine um plano para liberar sua API key e acompanhar o faturamento aqui." :icon="PhCrown">
                            <Button to="/dashboard/plans" :icon-right="PhArrowRight">Ver planos</Button>
                        </EmptyState>
                    </Card>

                    <Card v-if="overage?.unpaid?.length" title="Excedente a pagar" subtitle="Requisições além do limite em períodos já encerrados">
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            <li v-for="item in overage.unpaid" :key="item.id" class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ item.plan }} · {{ item.requests.toLocaleString('pt-BR') }} requisições</p>
                                    <p class="text-xs text-slate-500">Período encerrado em {{ format.date(item.period_end) }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ format.money(item.amount) }}</span>
                                    <Button size="sm" variant="outline" :icon="PhCreditCard" @click="openOverageCard(item)">Pagar com cartão</Button>
                                    <Button v-if="overage.pix_enabled" size="sm" :icon="PhQrCode" :loading="pixLoadingId === item.id" @click="payOverageWithPix(item)">Pagar com PIX</Button>
                                </div>
                            </li>
                        </ul>
                    </Card>

                    <Card v-if="overage?.available" title="Excedente" subtitle="Continuar usando a API depois do limite do plano, pagando por requisição">
                        <div class="space-y-5">
                            <Switch
                                v-model="overageForm.enabled"
                                label="Permitir excedente"
                                :description="`No limite do plano, as requisições continuam a ${format.money(overage.price_per_request)} cada, cobradas no fim do período. Desligado, a API responde 429 no limite.`"
                            />
                            <Transition name="ui-fade">
                                <TextInput
                                    v-if="overageForm.enabled"
                                    v-model="overageForm.spend_cap"
                                    class="max-w-xs"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    label="Teto de gasto por período (R$)"
                                    placeholder="Sem teto próprio"
                                    :hint="overage.plan_max_requests ? `O plano permite até ${overage.plan_max_requests} requisições além do limite. Vazio: vale só o teto do plano.` : 'Vazio: vale só o teto do plano.'"
                                />
                            </Transition>
                            <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    <template v-if="overage.current?.requests > 0">Neste período: {{ overage.current.requests }} requisições além do limite ({{ format.money(overage.current.amount) }}).</template>
                                </p>
                                <Button size="sm" :loading="overageSaving" @click="saveOverage">Salvar</Button>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Cartões -->
                <Card v-else-if="active === 'cards'" title="Cartões" subtitle="Usados na renovação e no excedente">
                    <template #actions>
                        <Button size="sm" variant="outline" :icon="PhPlus" @click="showAddCard = true">Adicionar cartão</Button>
                    </template>

                    <TransitionGroup v-if="savedCards.length" name="ui-list" tag="div" class="relative grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="card in savedCards"
                            :key="card.id"
                            :class="['group relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 p-5 text-white shadow-lg ring-1 ring-white/10 transition-[transform,opacity] duration-300 hover:-translate-y-0.5', card.usable === false ? 'opacity-60 grayscale' : '']"
                        >
                            <div aria-hidden="true" class="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-brand-500/30 blur-2xl" />
                            <div class="relative flex items-start justify-between">
                                <PhCreditCard :size="26" weight="duotone" class="text-white/80" />
                                <div class="flex items-center gap-2">
                                    <Badge v-if="card.usable === false" tone="neutral" class="!bg-white/15 !text-white !ring-white/20">Fora de uso</Badge>
                                    <Badge v-else-if="card.is_default" tone="brand" class="!bg-white/15 !text-white !ring-white/20">Padrão</Badge>
                                    <button type="button" class="rounded-lg p-1.5 text-white/60 opacity-100 transition hover:bg-white/10 hover:text-white sm:opacity-0 sm:group-hover:opacity-100" aria-label="Remover cartão" @click="removeCard(card)">
                                        <PhTrash :size="16" />
                                    </button>
                                </div>
                            </div>
                            <p class="relative mt-6 font-mono text-lg tracking-[0.2em]">•••• •••• •••• {{ card.last_four }}</p>
                            <div class="relative mt-4 flex items-end justify-between text-xs">
                                <div>
                                    <p class="uppercase text-white/50">Titular</p>
                                    <p class="mt-0.5 max-w-40 truncate font-medium">{{ card.holder_name }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="uppercase text-white/50">Validade</p>
                                    <p class="mt-0.5 font-medium">{{ card.expiry_month }}/{{ String(card.expiry_year).slice(-2) }}</p>
                                </div>
                                <p class="text-sm font-bold uppercase italic tracking-wide">{{ card.brand }}</p>
                            </div>
                            <div class="relative mt-4 border-t border-white/10 pt-3 text-xs">
                                <span v-if="card.usable === false" class="text-white/70">Não é mais aceito para pagamento. Adicione um cartão novo.</span>
                                <span v-else-if="card.automatic_billing" class="inline-flex items-center gap-1.5 text-emerald-300"><PhCheckCircle :size="14" weight="fill" /> Renovação automática ativa</span>
                                <button v-else-if="automaticPaymentsOn && card.mp_card_id && (card.gateway ?? 'mercadopago') === 'mercadopago'" type="button" class="inline-flex items-center gap-1.5 font-semibold text-white hover:underline" @click="activateAutomaticBilling(card)">
                                    <PhLightning :size="14" weight="fill" class="text-amber-300" /> Ativar renovação automática
                                </button>
                                <span v-else class="text-white/50">Pede o CVV na renovação</span>
                            </div>
                        </div>
                    </TransitionGroup>

                    <EmptyState v-else title="Nenhum cartão cadastrado" description="Adicione um cartão para a renovação automática. Ele é validado com segurança." :icon="PhCreditCard">
                        <Button :icon="PhPlus" @click="showAddCard = true">Adicionar cartão</Button>
                    </EmptyState>
                </Card>

                <!-- Histórico -->
                <div v-else class="grid gap-6 lg:grid-cols-2">
                    <Card title="Assinaturas" subtitle="Todas as suas assinaturas">
                        <DataTable :columns="historyColumns" :rows="billingHistory" :loading="loading" empty-title="Nenhuma assinatura ainda" :empty-icon="PhReceipt">
                            <template #cell-plan="{ row }"><span class="font-medium text-slate-900 dark:text-white">{{ row.plan?.name }}</span></template>
                            <template #cell-period="{ row }"><span class="whitespace-nowrap text-slate-500">{{ format.date(row.dates?.start_date) }} – {{ format.date(row.dates?.end_date) }}</span></template>
                            <template #cell-status="{ row }"><Badge :tone="row.status?.active ? 'success' : 'neutral'" dot>{{ row.status?.active ? 'Ativa' : 'Encerrada' }}</Badge></template>
                            <template #cell-amount="{ row }"><span class="tabular-nums">{{ format.money(row.payment?.amount ?? row.plan?.raw_price) }}</span></template>
                        </DataTable>
                    </Card>

                    <Card title="Recibos" subtitle="Comprovantes para imprimir ou salvar em PDF">
                        <TransitionGroup v-if="receipts.length" name="ui-list" tag="ul" class="relative divide-y divide-slate-100 dark:divide-slate-800">
                            <li v-for="r in receipts" :key="r.id + r.kind" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"><PhFileText :size="18" /></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ r.description }}</p>
                                        <p class="text-xs text-slate-500">{{ format.date(r.paid_at) }}<Badge v-if="r.refunded" tone="danger" class="ml-2">Estornado</Badge></p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ format.money(r.amount) }}</span>
                                    <Button variant="ghost" size="sm" square :icon="PhArrowSquareOut" aria-label="Abrir recibo" @click="openReceipt(r)" />
                                </div>
                            </li>
                        </TransitionGroup>
                        <EmptyState v-else compact title="Nenhum pagamento ainda" :icon="PhFileText" />
                    </Card>
                </div>
            </template>
        </Tabs>

        <GatewayAddCardModal v-if="isDriver" v-model="showAddCard" @saved="onCardSaved" />
        <AddCardModal v-else v-model="showAddCard" @saved="onCardSaved" />

        <CheckoutModal v-if="renewal?.plan" v-model="showRenewCheckout" :plan="renewal.plan" @success="onRenewed" />

        <!-- Excedente no cartão: escolhe um cartão salvo (e o CVV, quando o cartão pede) -->
        <Modal v-model="showOverageCard" title="Pagar excedente com cartão" :description="overageItem ? `${format.money(overageItem.amount)} · ${overageItem.requests.toLocaleString('pt-BR')} requisições além do limite` : ''" size="sm">
            <div v-if="chargeableCards.length" class="space-y-3" role="radiogroup" aria-label="Cartão">
                <button
                    v-for="card in chargeableCards"
                    :key="card.id"
                    type="button"
                    role="radio"
                    :aria-checked="overageCardId === card.id"
                    :class="[
                        'flex w-full items-center gap-3 rounded-2xl border p-3 text-left transition-[border-color,background-color] duration-150',
                        overageCardId === card.id ? 'border-brand-500 bg-brand-50/70 dark:bg-brand-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700',
                    ]"
                    @click="selectOverageCard(card)"
                >
                    <span class="flex h-9 w-12 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-slate-700 to-slate-900 text-[10px] font-bold uppercase italic text-white">{{ card.brand }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">•••• {{ card.last_four }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ card.holder_name }} · {{ card.expiry_month }}/{{ String(card.expiry_year).slice(-2) }}</span>
                    </span>
                    <span :class="['flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2', overageCardId === card.id ? 'border-brand-600 bg-brand-600' : 'border-slate-300 dark:border-slate-600']">
                        <PhCheck v-if="overageCardId === card.id" :size="11" weight="bold" class="text-white" />
                    </span>
                </button>
                <TextInput v-if="overageNeedsCvv" v-model="overageCvv" type="password" label="CVV" inputmode="numeric" maxlength="4" placeholder="•••" autocomplete="cc-csc" class="max-w-40" hint="Este cartão pede o código de segurança." />
                <Alert v-if="overageCardError" tone="danger">{{ overageCardError }}</Alert>
            </div>
            <EmptyState v-else compact title="Nenhum cartão salvo" description="Adicione um cartão para pagar o excedente com ele." :icon="PhCreditCard">
                <Button size="sm" :icon="PhPlus" @click="showOverageCard = false; showAddCard = true">Adicionar cartão</Button>
            </EmptyState>
            <template v-if="chargeableCards.length" #footer>
                <Button variant="ghost" @click="showOverageCard = false">Cancelar</Button>
                <Button :icon="PhLockSimple" :loading="overagePaying" :disabled="!overageCardId || (overageNeedsCvv && overageCvv.length < 3)" @click="payOverageWithCard">Pagar {{ overageItem ? format.money(overageItem.amount) : '' }}</Button>
            </template>
        </Modal>

        <!-- QR do PIX do excedente, gerado aqui no painel -->
        <Modal v-model="showPixModal" title="Pagar excedente com PIX" :description="pixCharge ? `Pague ${format.money(pixCharge.amount)}. A confirmação chega sozinha em alguns segundos.` : ''" size="sm">
            <div v-if="pixCharge" class="space-y-4 text-center">
                <div class="mx-auto w-fit rounded-2xl bg-white p-3 shadow-inner ring-1 ring-slate-200">
                    <img v-if="pixCharge.qr_code_base64" :src="`data:image/png;base64,${pixCharge.qr_code_base64}`" alt="QR code PIX" class="h-52 w-52" />
                </div>
                <div v-if="pixCharge.qr_code" class="flex gap-2">
                    <input :value="pixCharge.qr_code" readonly class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800" />
                    <Button variant="outline" size="sm" :icon="pixCopied ? PhCheck : PhCopy" @click="copyPix">{{ pixCopied ? 'Copiado' : 'Copiar' }}</Button>
                </div>
                <p class="flex items-center justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" /><span class="relative inline-flex h-2 w-2 rounded-full bg-amber-500" /></span>
                    Aguardando pagamento
                </p>
            </div>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
    PhReceipt, PhCreditCard, PhPlus, PhCrown, PhCalendarBlank, PhCoins, PhTrash, PhArrowsClockwise, PhPauseCircle,
    PhArrowRight, PhQrCode, PhCheckCircle, PhLightning, PhFileText, PhArrowSquareOut, PhCheck, PhCopy, PhChartPieSlice, PhLockSimple,
} from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { createMpCvvToken, enableAutomaticBilling } from '@/utils/automaticBilling';
import { Alert, Badge, Button, Card, DataTable, EmptyState, Modal, PageHeader, StatCard, Switch, Tabs, TextInput, confirm, errorMessage, format, toast } from '@/ui';
import type { Column, TabItem } from '@/ui';
import AddCardModal from '@/views/dashboard/AddCardModal.vue';
import GatewayAddCardModal from '@/views/dashboard/GatewayAddCardModal.vue';
import { isDriverGateway } from '@/utils/gateways';
import CheckoutModal from '@/views/dashboard/CheckoutModal.vue';

const authStore = useAuthStore();
const dashboardStore = useDashboardStore();

const loading = ref(true);
const tab = ref('overview');
const showAddCard = ref(false);
const subscriptionBusy = ref(false);

const user = computed<any>(() => authStore.user);
const billingHistory = computed<any[]>(() => dashboardStore.billingHistory);
const savedCards = computed<any[]>(() => dashboardStore.savedCards);

const activePlan = computed<any>(() => user.value?.active_plan);
const activePlanInfo = computed<any>(() => activePlan.value?.plan);
const isCancelled = computed(() => activePlan.value?.cancellation?.cancelled === true);
const receipts = ref<any[]>([]);
const overage = ref<any>(null);
const renewal = ref<any>(null);
const showRenewCheckout = ref(false);
const showPixModal = ref(false);
const pixCharge = ref<any>(null);
const pixCopied = ref(false);
const pixLoadingId = ref<string | null>(null);
let pixTimer: ReturnType<typeof setInterval> | null = null;

const tabs = computed<TabItem[]>(() => [
    { key: 'overview', label: 'Visão geral', icon: PhChartPieSlice },
    { key: 'cards', label: 'Cartões', icon: PhCreditCard, count: savedCards.value.length || null },
    { key: 'history', label: 'Histórico e recibos', icon: PhReceipt },
]);

const historyColumns: Column[] = [
    { key: 'plan', label: 'Plano' },
    { key: 'period', label: 'Período' },
    { key: 'status', label: 'Status' },
    { key: 'amount', label: 'Valor', align: 'right' },
];

const renewalRetry = computed(() => {
    const r = activePlan.value?.renewal;
    return r?.attempts > 0 ? r : null;
});

// Soma o que foi cobrado, não o preço de tabela: cupom e crédito de troca fazem
// o valor pago divergir do preço do plano.
const totalSpent = computed(() => billingHistory.value.reduce((acc, sub) => acc + Number(sub.payment?.amount ?? sub.plan?.raw_price ?? 0), 0));

const automaticPaymentsOn = computed(() => !!user.value?.mp_automatic_payments);
const isDriver = computed(() => isDriverGateway(user.value?.payment_gateway));

async function loadRenewal() {
    try {
        const r = await axios.get('/dashboard/billing/renewal');
        renewal.value = r.data?.data ?? null;
    } catch {
        renewal.value = null;
    }
}

async function loadReceipts() {
    try {
        const r = await axios.get('/dashboard/receipts');
        receipts.value = r.data?.data ?? [];
    } catch {
        receipts.value = [];
    }
}

const overageForm = ref<{ enabled: boolean; spend_cap: string | number }>({ enabled: false, spend_cap: '' });
const overageSaving = ref(false);

async function loadOverage() {
    try {
        const r = await axios.get('/dashboard/billing/overage');
        overage.value = r.data?.data ?? null;
        overageForm.value = { enabled: !!overage.value?.enabled, spend_cap: overage.value?.spend_cap ?? '' };
    } catch {
        overage.value = null;
    }
}

async function saveOverage() {
    overageSaving.value = true;
    try {
        const r = await axios.put('/dashboard/billing/overage', {
            enabled: overageForm.value.enabled,
            spend_cap: overageForm.value.enabled && overageForm.value.spend_cap !== '' ? Number(overageForm.value.spend_cap) : null,
        });
        overage.value = r.data?.data ?? overage.value;
        toast.success(overageForm.value.enabled ? 'Excedente ligado' : 'Excedente desligado', {
            description: overageForm.value.enabled
                ? 'Depois do limite do plano, as requisições continuam e são cobradas no fim do período.'
                : 'No limite do plano a API passa a responder 429.',
        });
    } catch (err) {
        toast.error('Não foi possível salvar', { description: errorMessage(err) });
    } finally {
        overageSaving.value = false;
    }
}

async function onRenewed() {
    await Promise.all([authStore.fetchProfile(), dashboardStore.fetchBillingHistory(), loadRenewal(), loadReceipts()]);
}

function stopPixPolling() {
    if (pixTimer) clearInterval(pixTimer);
    pixTimer = null;
}

// Excedente no cartão
const showOverageCard = ref(false);
const overageItem = ref<any>(null);
const overageCardId = ref<number | null>(null);
const overageCvv = ref('');
const overageForceCvv = ref(false);
const overagePaying = ref(false);
const overageCardError = ref('');
let overageAttemptKey = '';

// Cartões que o servidor consegue cobrar: os dos outros gateways e os do
// Mercado Pago vinculados ao cliente.
// Só os cartões do meio de pagamento atual (`usable`) podem ser cobrados.
const chargeableCards = computed(() => savedCards.value.filter((c) => c.usable !== false && (c.mp_card_id || ((c.gateway ?? 'mercadopago') !== 'mercadopago' && c.automatic_billing))));
const overageCard = computed(() => chargeableCards.value.find((c) => c.id === overageCardId.value) ?? null);
// Mercado Pago sem renovação automática: sem o CVV, a conta recusa.
const overageNeedsCvv = computed(() => {
    const c = overageCard.value;
    return !!c && (c.gateway ?? 'mercadopago') === 'mercadopago' && (!c.automatic_billing || overageForceCvv.value);
});

function selectOverageCard(card: any) {
    overageCardId.value = card.id;
    overageCvv.value = '';
    overageForceCvv.value = false;
    overageCardError.value = '';
}

function openOverageCard(item: any) {
    overageItem.value = item;
    overageCardError.value = '';
    overageCvv.value = '';
    overageForceCvv.value = false;
    overageAttemptKey = crypto.randomUUID();
    const preferred = chargeableCards.value.find((c) => c.is_default) ?? chargeableCards.value[0];
    overageCardId.value = preferred?.id ?? null;
    showOverageCard.value = true;
}

async function payOverageWithCard() {
    const card = overageCard.value;
    if (!card || !overageItem.value) return;

    overagePaying.value = true;
    overageCardError.value = '';
    try {
        let token: string | undefined;
        if (overageNeedsCvv.value) {
            const publicKey = user.value?.mp_public_key || document.querySelector('meta[name="mp-public-key"]')?.getAttribute('content') || '';
            token = await createMpCvvToken(publicKey, card.mp_card_id, overageCvv.value.replace(/\D/g, ''));
        }

        const r = await axios.post(`/dashboard/billing/overage/${overageItem.value.id}/card`, {
            card_id: card.id,
            token,
            idempotency_key: overageAttemptKey,
        });

        showOverageCard.value = false;
        await Promise.all([loadOverage(), loadReceipts()]);
        if (r.data?.data?.status === 'pending') toast.info('Pagamento em análise', { description: 'Avisamos assim que for aprovado.' });
        else toast.success('Excedente pago', { description: 'O recibo já está em Histórico e recibos.' });
    } catch (err: any) {
        const data = err?.response?.data;
        // Recusa ou falha: a próxima tentativa é outra cobrança.
        overageAttemptKey = crypto.randomUUID();
        if (data?.status === 'cvv_required') overageForceCvv.value = true;
        overageCardError.value = data?.message || err?.message || 'Pagamento recusado.';
    } finally {
        overagePaying.value = false;
        overageCvv.value = '';
    }
}

async function payOverageWithPix(item: any) {
    pixLoadingId.value = item.id;
    try {
        const r = await axios.post(`/dashboard/billing/overage/${item.id}/pix`);
        pixCharge.value = r.data?.data;
        showPixModal.value = true;
        stopPixPolling();
        pixTimer = setInterval(async () => {
            try {
                const s = await axios.get(`/dashboard/checkout/payments/${pixCharge.value.payment_id}`);
                const status = s.data?.data?.status;
                if (status === 'approved' || status === 'rejected') {
                    stopPixPolling();
                    showPixModal.value = false;
                    await Promise.all([loadOverage(), loadReceipts()]);
                    if (status === 'approved') toast.success('Excedente pago', { description: 'O recibo já está em Histórico e recibos.' });
                    else toast.error('O PIX não foi concluído', { description: 'Gere um novo para tentar de novo.' });
                }
            } catch {
                // falha momentânea: a próxima volta tenta de novo
            }
        }, 5000);
    } catch (err) {
        toast.error('Não foi possível gerar o PIX', { description: errorMessage(err) });
    } finally {
        pixLoadingId.value = null;
    }
}

async function copyPix() {
    try {
        await navigator.clipboard.writeText(pixCharge.value.qr_code);
        pixCopied.value = true;
        setTimeout(() => (pixCopied.value = false), 2000);
    } catch {
        toast.error('Não foi possível copiar', { description: 'Selecione o código e copie manualmente.' });
    }
}

watch(showPixModal, (open) => {
    if (!open) stopPixPolling();
});
onBeforeUnmount(stopPixPolling);

// O recibo exige o token, que um link comum não envia: busca o HTML e abre
// numa aba nova, onde dá para imprimir ou salvar em PDF.
async function openReceipt(receipt: any) {
    const tabRef = window.open('', '_blank');
    try {
        const r = await axios.get(`/dashboard/receipts/${receipt.id}`, { params: { kind: receipt.kind }, responseType: 'text', headers: { Accept: 'text/html' } });
        const url = URL.createObjectURL(new Blob([r.data], { type: 'text/html' }));
        if (tabRef) tabRef.location.href = url;
        else window.location.href = url;
    } catch (err) {
        tabRef?.close();
        toast.error('Não foi possível abrir o recibo', { description: errorMessage(err) });
    }
}

async function activateAutomaticBilling(card: any) {
    const publicKey = user.value?.mp_public_key || document.querySelector('meta[name="mp-public-key"]')?.getAttribute('content') || '';
    if (await enableAutomaticBilling(card, publicKey)) await dashboardStore.fetchSavedCards();
}

async function removeCard(card: any) {
    const ok = await confirm({
        title: `Remover o cartão final ${card.last_four}?`,
        description: 'Ele deixa de ser usado na renovação. Você pode cadastrá-lo de novo quando quiser.',
        tone: 'danger',
        confirmText: 'Remover cartão',
        onConfirm: () => dashboardStore.deleteSavedCard(card.id),
    });
    if (ok) toast.success('Cartão removido');
}

async function onCardSaved() {
    await dashboardStore.fetchSavedCards();
}

async function cancelSubscription() {
    const accessUntil = format.date(activePlan.value?.dates?.end_date);

    // Consulta o desfecho antes de perguntar: com estorno o acesso acaba na
    // hora, sem estorno segue até o vencimento.
    let preview: any = null;
    try {
        preview = await dashboardStore.fetchRefundPreview();
    } catch {
        preview = null;
    }

    const willRefund = preview?.eligible === true;
    let result: any = null;

    const ok = await confirm({
        title: willRefund ? 'Cancelar e receber o estorno?' : 'Cancelar a renovação?',
        description: willRefund
            ? `Vamos devolver ${format.money(preview.amount)} no cartão usado na compra.\n\nO acesso é encerrado imediatamente, porque o valor está sendo devolvido: sua API key para de responder assim que você confirmar.`
            : `Sua assinatura não é interrompida agora. O acesso continua até ${accessUntil} e nenhuma nova cobrança será feita.${preview && !preview.eligible && preview.reason !== 'refund_disabled' && preview.message ? `\n\n${preview.message}` : ''}`,
        tone: willRefund ? 'danger' : 'warning',
        confirmText: willRefund ? 'Cancelar e estornar' : 'Cancelar renovação',
        cancelText: 'Manter assinatura',
        onConfirm: async () => {
            result = await dashboardStore.cancelSubscription();
            await authStore.fetchProfile();
        },
    });

    if (!ok) return;

    // O desfecho real vem da resposta, e não do preview: o estorno pode ter
    // sido recusado pelo gateway entre a consulta e a confirmação.
    toast.success(result?.refunded ? 'Assinatura cancelada e estornada' : 'Renovação cancelada', {
        description: result?.message ?? (result?.refunded ? 'O valor foi devolvido e o acesso encerrado.' : `Seu acesso continua até ${accessUntil}.`),
    });
}

async function resumeSubscription() {
    subscriptionBusy.value = true;
    try {
        const data = await dashboardStore.resumeSubscription();
        await authStore.fetchProfile();
        toast.success('Renovação reativada', { description: data?.message ?? 'Sua assinatura será renovada normalmente.' });
    } catch (err) {
        toast.error('Não foi possível reativar', { description: errorMessage(err) });
    } finally {
        subscriptionBusy.value = false;
    }
}

onMounted(async () => {
    try {
        await Promise.all([
            authStore.fetchProfile(),
            dashboardStore.fetchBillingHistory(),
            dashboardStore.fetchSavedCards(),
            loadReceipts(),
            loadOverage(),
            loadRenewal(),
        ]);
    } finally {
        loading.value = false;
    }
});
</script>
