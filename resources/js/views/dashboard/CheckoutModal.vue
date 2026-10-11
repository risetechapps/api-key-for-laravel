<template>
    <Modal v-model="isOpen" title="Finalizar assinatura" description="Pagamento seguro, processado pelo gateway." size="md">
        <!-- Resumo do plano -->
        <div class="relative mb-5 overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 to-brand-800 p-5 text-white">
            <div aria-hidden="true" class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
            <div class="relative flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wider text-white/70">Plano</p>
                    <p class="mt-0.5 truncate text-lg font-bold">{{ plan?.name }}</p>
                    <p class="text-sm text-white/75 line-clamp-2">{{ plan?.description }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <template v-if="appliedCoupon">
                        <p class="text-sm text-white/60 line-through">{{ plan?.price }}</p>
                        <p class="text-2xl font-extrabold">{{ formatPrice(appliedCoupon.final_price) }}</p>
                    </template>
                    <p v-else class="text-2xl font-extrabold">{{ plan?.price }}</p>
                    <p class="text-xs text-white/70">por {{ billingLabel }}</p>
                </div>
            </div>
            <div v-if="appliedCoupon" class="relative mt-4 flex items-center justify-between border-t border-white/20 pt-3 text-sm">
                <span class="inline-flex items-center gap-2 font-medium"><PhTag :size="16" weight="fill" class="text-amber-300" /> Cupom {{ appliedCoupon.coupon }}</span>
                <span class="font-semibold">− {{ formatPrice(appliedCoupon.discount) }}</span>
            </div>
        </div>

        <!-- Cupom -->
        <div v-if="!isFree" class="mb-5">
            <form class="flex items-end gap-2" @submit.prevent="appliedCoupon ? removeCoupon() : applyCoupon()">
                <TextInput
                    v-model="couponCode"
                    class="flex-1"
                    label="Cupom de desconto"
                    placeholder="Tem um cupom?"
                    uppercase
                    mono
                    :icon="PhTag"
                    :disabled="couponState === 'loading' || !!appliedCoupon"
                    :error="couponState === 'invalid' ? 'Cupom inválido ou expirado.' : ''"
                />
                <Button v-if="!appliedCoupon" type="submit" variant="outline" :loading="couponState === 'loading'" :disabled="!couponCode.trim()">Aplicar</Button>
                <Button v-else variant="ghost" square :icon="PhX" aria-label="Remover cupom" @click="removeCoupon" />
            </form>
        </div>

        <!-- Erro do pagamento -->
        <Alert v-if="errorMessage" tone="danger" class="mb-5">{{ errorMessage }}</Alert>

        <!-- Free plan -->
        <div v-if="isFree" class="text-center py-2">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Confirme para ativar o plano gratuito sem custo algum.</p>
            <Button variant="primary" class="w-full" :loading="confirming" @click="confirmFree">Ativar Gratuitamente</Button>
        </div>

        <!-- PIX gerado: QR e espera pela confirmação -->
        <div v-else-if="pix" class="space-y-4 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-400">Pague {{ formatPrice(pix.amount) }} com PIX. A confirmação chega sozinha em alguns segundos.</p>
            <div class="mx-auto w-fit rounded-2xl bg-white p-3 shadow-inner ring-1 ring-slate-200 animate-pop">
                <img v-if="pix.qr_code_base64" :src="`data:image/png;base64,${pix.qr_code_base64}`" alt="QR code PIX" class="h-52 w-52" />
            </div>
            <CopyField v-if="pix.qr_code" :value="pix.qr_code" :wrap="false" />
            <p class="flex items-center justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" /><span class="relative inline-flex h-2 w-2 rounded-full bg-amber-500" /></span>
                Aguardando pagamento · expira às {{ pixExpiresAt }}
            </p>
            <a v-if="pix.ticket_url && (!isDriver || gateway === 'stripe')" :href="pix.ticket_url" target="_blank" rel="noopener" class="inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ isDriver ? 'Ver instruções do PIX' : 'Abrir no Mercado Pago' }}</a>
        </div>

        <!-- Paid plan -->
        <div v-else>
            <!-- Período de teste: sem cobrança agora -->
            <Alert v-if="trialEligible" tone="brand" :title="`${plan.trial_days} dias grátis`" class="mb-5">
                Cobramos só no fim do teste, se você não cancelar antes.
                <template #actions><Button size="sm" :loading="confirming" @click="startTrial">Começar teste</Button></template>
            </Alert>

            <!-- Forma de pagamento -->
            <Tabs
                v-if="paymentTabs.length > 1"
                class="mb-5"
                variant="pills"
                :model-value="paymentTab"
                :tabs="paymentTabs"
                :animate="false"
                label="Forma de pagamento"
                @update:model-value="setTab"
            />

            <!-- ── PIX tab ── -->
            <div v-if="paymentTab === 'pix'" class="space-y-4 text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><PhQrCode :size="28" weight="duotone" /></span>
                <p class="text-sm text-slate-500 dark:text-slate-400">Geramos um QR code válido por 30 minutos. O plano é ativado assim que o pagamento cair.</p>
                <Button block size="lg" :icon="PhQrCode" :loading="confirming" @click="generatePix">Gerar PIX</Button>
            </div>

            <!-- ── Saved card tab ── -->
            <div v-else-if="paymentTab === 'saved'">
                <div class="mb-4 space-y-2" role="radiogroup" aria-label="Cartão salvo">
                    <button
                        v-for="card in savedCards"
                        :key="card.id"
                        type="button"
                        role="radio"
                        :aria-checked="selectedCard?.id === card.id"
                        :class="[
                            'flex w-full items-center gap-3 rounded-2xl border p-3 text-left transition-[border-color,background-color,box-shadow] duration-150',
                            selectedCard?.id === card.id
                                ? 'border-brand-500 bg-brand-50/70 shadow-sm shadow-brand-500/10 dark:bg-brand-500/10'
                                : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600',
                        ]"
                        @click="onSelectCard(card)"
                    >
                        <span class="flex h-10 w-14 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-slate-700 to-slate-900 text-[10px] font-bold uppercase italic text-white">{{ card.brand }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-slate-900 dark:text-white">•••• {{ card.last_four }}</span>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ card.holder_name }} · {{ card.expiry_month }}/{{ String(card.expiry_year).slice(-2) }}</span>
                        </span>
                        <span :class="['flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors', selectedCard?.id === card.id ? 'border-brand-600 bg-brand-600' : 'border-slate-300 dark:border-slate-600']">
                            <PhCheck v-if="selectedCard?.id === card.id" :size="11" class="text-white" weight="bold" />
                        </span>
                    </button>
                </div>

                <div v-if="selectedCard" class="space-y-3">
                    <!-- Outros gateways: o cartão salvo é cobrado pelo servidor, sem CVV -->
                    <template v-if="selectedIsDriver">
                        <Button block size="lg" :icon="PhLockSimple" :loading="processingCard" @click="submitDriverSaved">
                            Pagar {{ formatPrice(numericPrice) }}
                        </Button>
                    </template>

                    <!-- Cartão já vinculado ao MP — CVV apenas -->
                    <template v-else-if="selectedCard.mp_card_id">
                        <TextInput v-model="savedCardForm.cvv" type="password" label="CVV" inputmode="numeric" maxlength="4" placeholder="•••" autocomplete="cc-csc" class="max-w-40" hint="Os 3 ou 4 dígitos no verso do cartão." />
                        <Button block size="lg" :icon="PhLockSimple" :loading="processingCard" :disabled="(savedCardForm.cvv?.length ?? 0) < 3" @click="submitSavedCard">
                            Pagar {{ formatPrice(numericPrice) }}
                        </Button>
                    </template>

                    <!-- Cartão ainda não vinculado — confirmação única via Brick -->
                    <template v-else>
                        <Alert tone="info">Confirme os dados do cartão final {{ selectedCard.last_four }} uma única vez. Nas próximas compras é só o CVV.</Alert>
                        <div v-if="brickLoading" class="space-y-3 py-1">
                            <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                                <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                            </div>
                        </div>
                        <div id="mp-brick-verify-container" :class="{ hidden: brickLoading }"></div>
                    </template>
                </div>
                <p v-else class="py-4 text-center text-sm text-slate-400 dark:text-slate-500">Selecione um cartão para continuar.</p>
            </div>

            <!-- ── New card tab: outros gateways ── -->
            <div v-else-if="isDriver" class="space-y-4">
                <GatewayCardFields ref="driverFields" />
                <Button variant="primary" class="w-full" :loading="processingCard" @click="submitDriverNew">
                    Pagar {{ formatPrice(numericPrice) }}
                </Button>
            </div>

            <!-- ── New card tab: MP Brick ── -->
            <div v-else>
                <div v-if="brickLoading" class="space-y-3 py-1">
                    <div class="h-4 w-32 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                    <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                        <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                    </div>
                    <div class="h-12 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse"></div>
                    <div class="h-12 bg-brand-200 dark:bg-brand-900/40 rounded-xl animate-pulse mt-2"></div>
                </div>
                <div id="mp-brick-container" :class="{ hidden: brickLoading }"></div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { PhTag, PhX, PhCreditCard, PhPlus, PhCheck, PhQrCode, PhLockSimple } from '@phosphor-icons/vue';
import axios from 'axios';
import { enableAutomaticBilling } from '@/utils/automaticBilling';
import GatewayCardFields from '@/views/dashboard/GatewayCardFields.vue';
import { getStripe, isDriverGateway } from '@/utils/gateways';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { primeDeviceSession } from '@/mercadopago-device';
import { Alert, Button, CopyField, Modal, Tabs, TextInput, toast } from '@/ui';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    plan:       { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'success']);

const authStore      = useAuthStore();
const dashboardStore = useDashboardStore();

const confirming     = ref(false);
const brickLoading   = ref(true);
const brickInstance  = ref(null);
const errorMessage   = ref('');
const processingCard = ref(false);

const paymentTab      = ref('new');
const selectedCard    = ref(null);
const savedCardForm   = ref({ number: '', cvv: '' });
const verifyBrickInst = ref(null);

const couponCode    = ref('');
const couponState   = ref('');
const appliedCoupon = ref(null);

const isOpen = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val),
});

// Gateway ativo: cada um tem o próprio formulário de cartão, e um cartão só
// pode ser cobrado pelo gateway que o guardou.
const gateway      = computed(() => authStore.user?.payment_gateway ?? 'mercadopago');
const isDriver     = computed(() => isDriverGateway(gateway.value));
// Só o gateway ativo cobra: cartões salvos em outro gateway ficam de fora.
const savedCards   = computed(() => (dashboardStore.savedCards ?? []).filter(
    (c) => (c.gateway ?? 'mercadopago') === gateway.value
));
const selectedIsDriver = computed(() => isDriverGateway(selectedCard.value?.gateway ?? 'mercadopago'));
const driverFields = ref(null);
const rawPrice     = computed(() => props.plan?.raw_price ?? 0);
const numericPrice = computed(() => appliedCoupon.value ? appliedCoupon.value.final_price : rawPrice.value);
const isFree       = computed(() => numericPrice.value <= 0);
const pixEnabled   = computed(() => !!authStore.user?.pix_enabled);

const paymentTabs = computed(() => [
    ...(savedCards.value.length ? [{ key: 'saved', label: 'Cartão salvo', icon: PhCreditCard }] : []),
    { key: 'new', label: 'Novo cartão', icon: PhPlus },
    ...(pixEnabled.value ? [{ key: 'pix', label: 'PIX', icon: PhQrCode }] : []),
]);

// Mesmas condições do servidor (CheckoutController::trialFor): plano com teste,
// conta que ainda não usou, e um jeito de cobrar no fim — cartão salvo ou PIX.
const trialEligible = computed(() =>
    (props.plan?.trial_days ?? 0) > 0
    && !!authStore.user?.trial_available
    && rawPrice.value > 0
    && (savedCards.value.some((c) => c.mp_card_id || c.automatic_billing) || pixEnabled.value)
);

const pix        = ref(null);
const pixCopied  = ref(false);
let pixTimer     = null;
const pixExpiresAt = computed(() => pix.value?.expires_at
    ? new Date(pix.value.expires_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
    : '');

async function startTrial() {
    confirming.value = true;
    errorMessage.value = '';
    try {
        const result = await dashboardStore.processCheckout(props.plan.id, {}, null);
        await handlePaymentResult(result);
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || errorMessage.value || 'Não foi possível iniciar o teste.';
    } finally {
        confirming.value = false;
    }
}

async function generatePix() {
    confirming.value = true;
    errorMessage.value = '';
    try {
        const result = await dashboardStore.processCheckout(
            props.plan.id,
            { payment_method: 'pix', payer: { email: authStore.user?.email?.toLowerCase() ?? '' } },
            appliedCoupon.value?.coupon ?? null,
        );
        await handlePaymentResult(result);
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || errorMessage.value || 'Não foi possível gerar o PIX.';
    } finally {
        confirming.value = false;
    }
}

function stopPixPolling() {
    if (pixTimer) clearInterval(pixTimer);
    pixTimer = null;
}

// Consulta o pagamento enquanto o QR está na tela. Para sozinho no desfecho,
// na expiração ou ao fechar o modal.
function startPixPolling() {
    stopPixPolling();
    pixTimer = setInterval(async () => {
        if (!pix.value) return stopPixPolling();

        if (pix.value.expires_at && new Date(pix.value.expires_at) < new Date()) {
            stopPixPolling();
            pix.value = null;
            errorMessage.value = 'O PIX expirou. Gere um novo para continuar.';
            return;
        }

        try {
            const r = await axios.get(`/dashboard/checkout/payments/${pix.value.payment_id}`);
            const status = r.data?.data?.status;
            if (status === 'approved') {
                stopPixPolling();
                pix.value = null;
                await handlePaymentResult({ status: 'approved' });
            } else if (status === 'rejected') {
                stopPixPolling();
                pix.value = null;
                errorMessage.value = 'O PIX não foi concluído. Gere um novo para tentar de novo.';
            }
        } catch {
            // Falha momentânea de rede: a próxima volta tenta de novo.
        }
    }, 5000);
}

async function copyPix() {
    try {
        await navigator.clipboard.writeText(pix.value.qr_code);
        pixCopied.value = true;
        setTimeout(() => (pixCopied.value = false), 2000);
    } catch {
        pixCopied.value = false;
    }
}

const billingLabel = computed(() => {
    const c = props.plan?.billing_cycle;
    if (c === 'weekly') return 'semana';
    if (c === 'monthly') return 'mês';
    if (c === 'yearly' || c === 'annually') return 'ano';
    return c || '';
});

watch(() => props.modelValue, async (open) => {
    if (open) {
        errorMessage.value  = '';
        couponCode.value    = '';
        couponState.value   = '';
        appliedCoupon.value = null;
        selectedCard.value  = null;
        savedCardForm.value = { number: '', cvv: '' };
        pix.value           = null;

        await dashboardStore.fetchSavedCards();

        if (!isFree.value) {
            paymentTab.value = savedCards.value.length ? 'saved' : 'new';
            if (paymentTab.value === 'new' && !isDriver.value) {
                brickLoading.value = true;
                await loadMpSdk();
                setTimeout(() => mountBrick('mp-brick-container'), 350);
            }
        }
    } else {
        destroyBrick();
        destroyVerifyBrick();
        // Fechar o modal não cancela o PIX: se ele for pago depois, o webhook
        // ativa o plano do mesmo jeito. Só para de consultar.
        stopPixPolling();
    }
});

async function setTab(tab) {
    if (paymentTab.value === tab) return;
    paymentTab.value = tab;
    selectedCard.value = null;
    destroyVerifyBrick();
    if (tab === 'pix') {
        destroyBrick();
        return;
    }
    if (tab === 'new' && isDriver.value) {
        return;
    }
    if (tab === 'new') {
        brickLoading.value = true;
        await loadMpSdk();
        setTimeout(() => mountBrick('mp-brick-container'), 350);
    } else {
        destroyBrick();
    }
}

async function onSelectCard(card) {
    selectedCard.value      = card;
    savedCardForm.value.cvv = '';
    destroyVerifyBrick();
    if (!card.mp_card_id && !isDriverGateway(card.gateway ?? 'mercadopago')) {
        brickLoading.value = true;
        await loadMpSdk();
        await nextTick();
        setTimeout(() => mountBrick('mp-brick-verify-container', true), 350);
    }
}

async function loadMpSdk() {
    // Junto do SDK: o identificador de dispositivo leva um instante para ficar
    // pronto e é pedido no momento do pagamento.
    primeDeviceSession();

    if (window.MercadoPago) return;
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://sdk.mercadopago.com/js/v2';
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
    });
}

async function mountBrick(containerId = 'mp-brick-container', isVerify = false) {
    if (!window.MercadoPago) return;
    const publicKey = authStore.user?.mp_public_key
        || document.querySelector('meta[name="mp-public-key"]')?.getAttribute('content')
        || '';
    if (!publicKey) return;

    const mp = new window.MercadoPago(publicKey, { locale: 'pt-BR' });
    const instance = await mp.bricks().create('cardPayment', containerId, {
        initialization: {
            amount: numericPrice.value,
            payer:  { email: authStore.user?.email?.toLowerCase() ?? '' },
        },
        customization: { paymentMethods: { minInstallments: 1 } },
        callbacks: {
            onReady: () => { brickLoading.value = false; },
            onSubmit: async (formData) => {
                errorMessage.value = '';
                try {
                    const result = await dashboardStore.processCheckout(
                        props.plan.id, formData, appliedCoupon.value?.coupon ?? null
                    );
                    await handlePaymentResult(result);
                } catch (err) {
                    const msg = err?.response?.data?.message || err?.message || 'Pagamento recusado.';
                    errorMessage.value = msg;
                    throw err;
                }
            },
            onError: (err) => console.error('MP Brick error:', err),
        },
    });

    if (isVerify) {
        verifyBrickInst.value = instance;
    } else {
        brickInstance.value = instance;
    }
}

function destroyVerifyBrick() {
    if (verifyBrickInst.value) {
        try { verifyBrickInst.value.unmount(); } catch (_) {}
        verifyBrickInst.value = null;
    }
}

function destroyBrick() {
    if (brickInstance.value) {
        try { brickInstance.value.unmount(); } catch (_) {}
        brickInstance.value = null;
    }
    brickLoading.value = true;
}

async function applyCoupon() {
    if (!couponCode.value.trim()) return;
    couponState.value = 'loading';
    errorMessage.value = '';
    try {
        appliedCoupon.value = await dashboardStore.validateCoupon(couponCode.value, props.plan.id);
        couponState.value   = 'valid';
        if (paymentTab.value === 'new') { destroyBrick(); brickLoading.value = true; setTimeout(() => mountBrick(), 100); }
    } catch (_) {
        appliedCoupon.value = null;
        couponState.value   = 'invalid';
    }
}

function removeCoupon() {
    appliedCoupon.value = null;
    couponCode.value    = '';
    couponState.value   = '';
    if (paymentTab.value === 'new') { destroyBrick(); brickLoading.value = true; setTimeout(() => mountBrick(), 100); }
}

async function confirmFree() {
    confirming.value = true;
    errorMessage.value = '';
    try {
        await dashboardStore.processCheckout(
            props.plan.id,
            { payer: { email: authStore.user?.email?.toLowerCase() ?? '' } },
            appliedCoupon.value?.coupon ?? null,
        );
        dashboardStore.clearIdempotencyKey(props.plan.id);
        await authStore.fetchProfile();
        isOpen.value = false;
        emit('success');
        toast.success('Plano ativado', { description: `Seu plano ${props.plan?.name ?? ''} já está valendo.` });
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || 'Erro ao ativar plano.';
    } finally {
        confirming.value = false;
    }
}

// ── Saved card flow ──────────────────────────────────────
function formatCardNumber(e) {
    const raw = e.target.value.replace(/\D/g, '').slice(0, 16);
    savedCardForm.value.number = raw.replace(/(.{4})/g, '$1 ').trim();
}

function detectBrand(number) {
    const n = number.replace(/\s/g, '');
    if (/^4/.test(n))                return 'visa';
    if (/^5[1-5]|^2[2-7]/.test(n)) return 'master';
    if (/^3[47]/.test(n))           return 'amex';
    if (/^6011|^65/.test(n))        return 'elo';
    if (/^2131|^1800|^35/.test(n)) return 'jcb';
    return selectedCard.value?.brand || 'outros';
}

async function submitSavedCard() {
    if (!selectedCard.value) return;

    processingCard.value = true;
    errorMessage.value   = '';

    try {
        const cpf   = (authStore.user?.profile?.cpf ?? '').replace(/\D/g, '');
        const payer = {
            email:          authStore.user?.email?.toLowerCase() ?? '',
            identification: cpf ? { type: 'CPF', number: cpf } : undefined,
        };

        if ((savedCardForm.value.cvv?.length ?? 0) < 3) { errorMessage.value = 'CVV inválido.'; return; }

        await loadMpSdk();
        const mpKey = authStore.user?.mp_public_key
            || document.querySelector('meta[name="mp-public-key"]')?.getAttribute('content')
            || '';
        const mp = new window.MercadoPago(mpKey, { locale: 'pt-BR' });

        let token;
        if (selectedCard.value.mp_card_id) {
            // Cartão salvo com mp_card_id — tokeniza CVV no frontend via JS SDK (secure)
            token = await mp.createCardToken({
                cardId:       selectedCard.value.mp_card_id,
                securityCode: savedCardForm.value.cvv,
            });
        } else {
            // Cartão sem mp_card_id — tokeniza número + CVV no frontend
            const cardNumber = savedCardForm.value.number.replace(/\s/g, '');
            if (cardNumber.length < 13) { errorMessage.value = 'Número do cartão inválido.'; return; }
            token = await mp.createCardToken({
                cardNumber,
                cardholderName:      selectedCard.value.holder_name,
                cardExpirationMonth: selectedCard.value.expiry_month,
                cardExpirationYear:  selectedCard.value.expiry_year,
                securityCode:        savedCardForm.value.cvv,
            });
        }

        // Cartão salvo que ainda não cobra sozinho: um segundo token, gerado
        // com o mesmo CVV, cria o perfil de Pagamentos Automáticos depois da
        // compra. Cada token serve uma vez só. Melhor esforço: sem ele a compra
        // segue igual, e a renovação pede o pagamento pelo painel.
        let profileToken = null;
        if (selectedCard.value.mp_card_id && !selectedCard.value.automatic_billing) {
            try {
                profileToken = await mp.createCardToken({
                    cardId:       selectedCard.value.mp_card_id,
                    securityCode: savedCardForm.value.cvv,
                });
            } catch (_) {
                profileToken = null;
            }
        }

        const result = await dashboardStore.processCheckout(
            props.plan.id,
            {
                token:             token.id,
                installments:      1,
                payment_method_id: selectedCard.value.brand || detectBrand(savedCardForm.value.number),
                saved_card_id:     selectedCard.value.id,
                profile_token:     profileToken?.id ?? undefined,
                payer,
            },
            appliedCoupon.value?.coupon ?? null
        );
        await handlePaymentResult(result);
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || err?.message || 'Pagamento recusado.';
    } finally {
        processingCard.value = false;
    }
}

// Outros gateways, cartão salvo: o servidor cobra pelo cartão guardado lá.
async function submitDriverSaved() {
    if (!selectedCard.value) return;
    processingCard.value = true;
    errorMessage.value   = '';
    try {
        const result = await dashboardStore.processCheckout(
            props.plan.id,
            { saved_card_id: selectedCard.value.id },
            appliedCoupon.value?.coupon ?? null
        );
        await handlePaymentResult(result);
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || err?.message || 'Pagamento recusado.';
    } finally {
        processingCard.value = false;
    }
}

// Outros gateways, cartão novo: tokeniza no navegador; o servidor salva e cobra.
async function submitDriverNew() {
    processingCard.value = true;
    errorMessage.value   = '';
    try {
        const { token, holder, cpf, extra } = await driverFields.value.tokenize({ forPayment: true });
        const result = await dashboardStore.processCheckout(
            props.plan.id,
            { card_token: token, holder_name: holder, cpf: cpf || undefined, extra },
            appliedCoupon.value?.coupon ?? null
        );
        await handlePaymentResult(result);
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || err?.message || 'Pagamento recusado.';
    } finally {
        processingCard.value = false;
    }
}

/**
 * O banco pediu autenticação (3DS no Stripe): abre o desafio e, concluído,
 * pergunta ao servidor como ficou o pagamento.
 */
async function completeClientAction(result) {
    const action = result.client_action;
    if (action?.type !== 'stripe_next_action') return result;

    const stripe = await getStripe(authStore.user?.payment_gateway_config?.publishable_key);
    const { error } = await stripe.handleNextAction({ clientSecret: action.client_secret });
    if (error) return { status: 'rejected', message: error.message || 'A autenticação do cartão não foi concluída.' };

    // O webhook pode chegar antes ou depois: a consulta pergunta ao Stripe.
    for (let i = 0; i < 5; i++) {
        try {
            const r = await axios.get(`/dashboard/checkout/payments/${result.payment_id}`);
            const status = r.data?.data?.status;
            if (status === 'approved') return { status: 'approved' };
            if (status === 'rejected') return { status: 'rejected', message: 'Pagamento recusado.' };
        } catch {
            // tenta de novo
        }
        await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    return { status: 'pending' };
}

async function handlePaymentResult(result) {
    if (result?.client_action) {
        result = await completeClientAction(result);
    }

    if (result?.status === 'pix') {
        pix.value = result;
        startPixPolling();
        return;
    }

    if (result?.status === 'trial') {
        await authStore.fetchProfile();
        isOpen.value = false;
        emit('success');
        toast.success('Teste iniciado', { description: result.message });
        return;
    }

    if (result?.status === 'approved') {
        // A compra concluiu: a próxima é uma cobrança nova e precisa de chave de
        // idempotência própria, senão o gateway devolveria este mesmo pagamento.
        dashboardStore.clearIdempotencyKey(props.plan.id);
        await authStore.fetchProfile();
        await dashboardStore.fetchSavedCards();
        isOpen.value = false;
        emit('success');
        toast.success('Pagamento aprovado', { description: `Seu plano ${props.plan?.name ?? ''} já está valendo.` });

        // Cartão salvo nesta compra sem renovação automática: o Brick gera um
        // token só, gasto no pagamento. Pede o CVV mais uma vez para ativar.
        const offer = result?.automatic_billing_offer;
        if (offer && authStore.user?.mp_automatic_payments) {
            const publicKey = authStore.user?.mp_public_key
                || document.querySelector('meta[name="mp-public-key"]')?.getAttribute('content')
                || '';

            if (await enableAutomaticBilling({ id: offer.card_id, mp_card_id: offer.mp_card_id, last_four: offer.last_four }, publicKey)) {
                await dashboardStore.fetchSavedCards();
            }
        }
    } else if (['pending', 'in_process'].includes(result?.status)) {
        isOpen.value = false;
        toast.info('Pagamento em análise', { description: 'Avisamos por e-mail assim que for aprovado.', duration: 7000 });
    } else {
        errorMessage.value = result?.message || 'Pagamento recusado.';
        throw new Error(errorMessage.value);
    }
}

function formatPrice(value) {
    return 'R$ ' + Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

onBeforeUnmount(() => { destroyBrick(); destroyVerifyBrick(); stopPixPolling(); });
</script>
