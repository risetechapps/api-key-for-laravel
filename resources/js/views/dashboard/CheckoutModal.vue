<template>
    <Modal v-model="isOpen" title="Finalizar Assinatura">
        <!-- Plan summary -->
        <div class="mb-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ plan?.name }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ plan?.description }}</p>
                </div>
                <div class="text-right">
                    <template v-if="appliedCoupon">
                        <p class="text-sm text-slate-400 line-through">{{ plan?.price }}</p>
                        <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400">{{ formatPrice(appliedCoupon.final_price) }}</p>
                    </template>
                    <p v-else class="text-xl font-bold text-slate-900 dark:text-white">{{ plan?.price }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">/{{ billingLabel }}</p>
                </div>
            </div>
            <div v-if="appliedCoupon" class="mt-3 pt-3 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between">
                <div class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
                    <PhTag :size="16" weight="fill" />
                    <span class="text-sm font-medium">Cupom {{ appliedCoupon.coupon }}</span>
                </div>
                <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">- {{ formatPrice(appliedCoupon.discount) }}</span>
            </div>
        </div>

        <!-- Coupon field -->
        <div v-if="!isFree" class="mb-4">
            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Cupom de desconto</label>
            <div class="flex gap-2">
                <input
                    v-model="couponCode"
                    type="text"
                    placeholder="Digite seu cupom"
                    :disabled="couponState === 'loading' || !!appliedCoupon"
                    class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed uppercase"
                    @keydown.enter.prevent="appliedCoupon ? removeCoupon() : applyCoupon()"
                />
                <Button v-if="!appliedCoupon" variant="outline" size="sm" :loading="couponState === 'loading'" :disabled="!couponCode.trim()" @click="applyCoupon">Aplicar</Button>
                <button v-else @click="removeCoupon" class="px-3 py-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <PhX :size="18" />
                </button>
            </div>
            <p v-if="couponState === 'invalid'" class="mt-1.5 text-xs text-red-500 flex items-center gap-1"><PhWarningCircle :size="14" /> Cupom inválido ou expirado.</p>
            <p v-if="couponState === 'valid'" class="mt-1.5 text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-1"><PhCheckCircle :size="14" weight="fill" /> Cupom aplicado!</p>
        </div>

        <!-- Payment error -->
        <div v-if="errorMessage" class="mb-4 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 flex items-start gap-3">
            <PhWarningCircle :size="18" weight="fill" class="text-red-500 mt-0.5 shrink-0" />
            <p class="text-sm text-red-700 dark:text-red-400">{{ errorMessage }}</p>
        </div>

        <!-- Free plan -->
        <div v-if="isFree" class="text-center py-2">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Confirme para ativar o plano gratuito sem custo algum.</p>
            <Button variant="primary" class="w-full" :loading="confirming" @click="confirmFree">Ativar Gratuitamente</Button>
        </div>

        <!-- Paid plan -->
        <div v-else>
            <!-- Tabs — only when user has saved cards -->
            <div v-if="savedCards.length" class="flex mb-5 border-b border-slate-200 dark:border-slate-700">
                <button
                    @click="setTab('saved')"
                    :class="[
                        'flex-1 py-2.5 text-sm font-semibold border-b-2 transition-colors',
                        paymentTab === 'saved'
                            ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-white',
                    ]"
                >
                    <span class="flex items-center justify-center gap-2"><PhCreditCard :size="15" /> Cartão Salvo</span>
                </button>
                <button
                    @click="setTab('new')"
                    :class="[
                        'flex-1 py-2.5 text-sm font-semibold border-b-2 transition-colors',
                        paymentTab === 'new'
                            ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-white',
                    ]"
                >
                    <span class="flex items-center justify-center gap-2"><PhPlus :size="15" /> Novo Cartão</span>
                </button>
            </div>

            <!-- ── Saved card tab ── -->
            <div v-if="paymentTab === 'saved'">
                <div class="space-y-2 mb-4">
                    <div
                        v-for="card in savedCards"
                        :key="card.id"
                        @click="onSelectCard(card)"
                        :class="[
                            'flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition-all',
                            selectedCard?.id === card.id
                                ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 ring-1 ring-indigo-500'
                                : 'border-slate-200 dark:border-slate-700 hover:border-indigo-300 dark:hover:border-indigo-700',
                        ]"
                    >
                        <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center shrink-0">
                            <PhCreditCard :size="20" class="text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900 dark:text-white capitalize">{{ card.brand }} •••• {{ card.last_four }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ card.holder_name }} · {{ card.expiry_month }}/{{ card.expiry_year }}</p>
                        </div>
                        <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-colors"
                             :class="selectedCard?.id === card.id ? 'border-indigo-500 bg-indigo-500' : 'border-slate-300 dark:border-slate-600'">
                            <PhCheck v-if="selectedCard?.id === card.id" :size="11" class="text-white" weight="bold" />
                        </div>
                    </div>
                </div>

                <div v-if="selectedCard" class="space-y-3">
                    <!-- Cartão já vinculado ao MP — CVV apenas -->
                    <template v-if="selectedCard.mp_card_id">
                        <div>
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">CVV</label>
                            <input
                                v-model="savedCardForm.cvv"
                                type="tel"
                                inputmode="numeric"
                                maxlength="4"
                                placeholder="•••"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                        </div>
                        <Button variant="primary" class="w-full" :loading="processingCard" :disabled="(savedCardForm.cvv?.length ?? 0) < 3" @click="submitSavedCard">
                            Pagar {{ formatPrice(numericPrice) }}
                        </Button>
                    </template>

                    <!-- Cartão ainda não vinculado — confirmação única via Brick -->
                    <template v-else>
                        <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-700 dark:text-amber-400">
                            Confirme os dados do cartão <strong>****{{ selectedCard.last_four }}</strong> uma única vez. Nas próximas compras será apenas 1 clique.
                        </div>
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
                <p v-else class="text-center text-sm text-slate-400 dark:text-slate-500 py-4">Selecione um cartão acima para continuar.</p>
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
                    <div class="h-12 bg-indigo-200 dark:bg-indigo-900/40 rounded-xl animate-pulse mt-2"></div>
                </div>
                <div id="mp-brick-container" :class="{ hidden: brickLoading }"></div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { PhCheckCircle, PhTag, PhX, PhWarningCircle, PhCreditCard, PhPlus, PhCheck } from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import Modal from '@/views/componentes/Modal.vue';
import Button from '@/views/componentes/Button.vue';

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

const savedCards   = computed(() => dashboardStore.savedCards);
const rawPrice     = computed(() => props.plan?.raw_price ?? 0);
const numericPrice = computed(() => appliedCoupon.value ? appliedCoupon.value.final_price : rawPrice.value);
const isFree       = computed(() => numericPrice.value <= 0);

const billingLabel = computed(() => {
    const c = props.plan?.billing_cycle;
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

        await dashboardStore.fetchSavedCards();

        if (!isFree.value) {
            paymentTab.value = savedCards.value.length ? 'saved' : 'new';
            if (paymentTab.value === 'new') {
                brickLoading.value = true;
                await loadMpSdk();
                setTimeout(() => mountBrick('mp-brick-container'), 350);
            }
        }
    } else {
        destroyBrick();
        destroyVerifyBrick();
    }
});

async function setTab(tab) {
    if (paymentTab.value === tab) return;
    paymentTab.value = tab;
    selectedCard.value = null;
    destroyVerifyBrick();
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
    if (!card.mp_card_id) {
        brickLoading.value = true;
        await loadMpSdk();
        await nextTick();
        setTimeout(() => mountBrick('mp-brick-verify-container', true), 350);
    }
}

async function loadMpSdk() {
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
        await authStore.fetchProfile();
        isOpen.value = false;
        emit('success');
        await Swal.fire({ icon: 'success', title: 'Plano ativado!', html: `Seu plano <strong>${props.plan?.name}</strong> foi ativado com sucesso.`, confirmButtonText: 'Continuar', confirmButtonColor: '#6366f1' });
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

        const result = await dashboardStore.processCheckout(
            props.plan.id,
            {
                token:             token.id,
                installments:      1,
                payment_method_id: selectedCard.value.brand || detectBrand(savedCardForm.value.number),
                saved_card_id:     selectedCard.value.id,
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

async function handlePaymentResult(result) {
    if (result?.status === 'approved') {
        await authStore.fetchProfile();
        await dashboardStore.fetchSavedCards();
        isOpen.value = false;
        emit('success');
        await Swal.fire({ icon: 'success', title: 'Pagamento aprovado!', html: `Seu plano <strong>${props.plan?.name}</strong> foi ativado com sucesso.`, confirmButtonText: 'Continuar', confirmButtonColor: '#6366f1' });
    } else if (['pending', 'in_process'].includes(result?.status)) {
        isOpen.value = false;
        await Swal.fire({ icon: 'warning', title: 'Pagamento em análise', text: 'Seu pagamento está sendo processado. Você será notificado assim que aprovado.', confirmButtonText: 'Ok', confirmButtonColor: '#6366f1' });
    } else {
        errorMessage.value = result?.message || 'Pagamento recusado.';
        throw new Error(errorMessage.value);
    }
}

function formatPrice(value) {
    return 'R$ ' + Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

onBeforeUnmount(() => { destroyBrick(); destroyVerifyBrick(); });
</script>
