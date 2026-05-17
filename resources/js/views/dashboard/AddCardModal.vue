<template>
    <Modal v-model="isOpen" title="Adicionar Cartão">
        <form @submit.prevent="handleSubmit" class="space-y-4">

            <!-- Nome do titular -->
            <div>
                <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                    Nome do titular
                </label>
                <input
                    v-model="form.holder"
                    type="text"
                    placeholder="Como está no cartão"
                    :class="fieldClass(errors.holder)"
                    @input="form.holder = form.holder.toUpperCase()"
                />
                <p v-if="errors.holder" class="mt-1 text-xs text-red-500">{{ errors.holder }}</p>
            </div>

            <!-- CPF do titular -->
            <div>
                <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                    CPF do titular
                </label>
                <input
                    v-model="form.cpf"
                    type="text"
                    inputmode="numeric"
                    placeholder="000.000.000-00"
                    maxlength="14"
                    :class="fieldClass(errors.cpf)"
                    @input="formatCpf"
                />
                <p v-if="errors.cpf" class="mt-1 text-xs text-red-500">{{ errors.cpf }}</p>
            </div>

            <!-- Skeleton enquanto os Secure Fields carregam -->
            <template v-if="fieldsLoading">
                <div>
                    <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Número do cartão</div>
                    <div class="h-[42px] rounded-xl bg-slate-100 dark:bg-slate-800 animate-pulse"></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Validade</div>
                        <div class="h-[42px] rounded-xl bg-slate-100 dark:bg-slate-800 animate-pulse"></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">CVV</div>
                        <div class="h-[42px] rounded-xl bg-slate-100 dark:bg-slate-800 animate-pulse"></div>
                    </div>
                </div>
            </template>

            <!-- Número do cartão — Secure Field (iframe MP) -->
            <div :class="{ hidden: fieldsLoading }">
                <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                    Número do cartão
                </label>
                <div id="sf-card-number" class="sf-field" :class="{ 'sf-field--error': errors.number }"></div>
                <p v-if="errors.number" class="mt-1 text-xs text-red-500">{{ errors.number }}</p>
            </div>

            <!-- Validade + CVV — Secure Fields (iframes MP) -->
            <div class="grid grid-cols-2 gap-3" :class="{ hidden: fieldsLoading }">
                <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                        Validade
                    </label>
                    <div id="sf-expiration-date" class="sf-field" :class="{ 'sf-field--error': errors.expiry }"></div>
                    <p v-if="errors.expiry" class="mt-1 text-xs text-red-500">{{ errors.expiry }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                        CVV
                    </label>
                    <div id="sf-security-code" class="sf-field" :class="{ 'sf-field--error': errors.cvv }"></div>
                    <p v-if="errors.cvv" class="mt-1 text-xs text-red-500">{{ errors.cvv }}</p>
                </div>
            </div>

            <!-- Aviso sobre cobrança de validação -->
            <div class="flex items-start gap-3 p-3 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800/30">
                <PhInfo :size="18" weight="fill" class="text-indigo-500 mt-0.5 flex-shrink-0" />
                <p class="text-xs text-indigo-700 dark:text-indigo-300 leading-relaxed">
                    Para validar o cartão, será realizada uma cobrança de <strong>R$ 5,00</strong> que é
                    <strong>estornada automaticamente</strong> após a confirmação.
                </p>
            </div>

            <!-- Erro geral -->
            <div v-if="errorMessage" class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 flex items-start gap-3">
                <PhWarningCircle :size="18" weight="fill" class="text-red-500 mt-0.5 flex-shrink-0" />
                <p class="text-sm text-red-700 dark:text-red-400">{{ errorMessage }}</p>
            </div>

            <Button type="submit" variant="primary" class="w-full" :loading="saving" :disabled="fieldsLoading">
                Validar e Salvar Cartão
            </Button>
        </form>
    </Modal>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { PhWarningCircle, PhInfo } from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import axios from 'axios';
import Modal from '@/views/componentes/Modal.vue';
import Button from '@/views/componentes/Button.vue';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();
const emit      = defineEmits(['update:modelValue', 'saved']);

const props = defineProps({
    modelValue: { type: Boolean, default: false },
});

const isOpen = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val),
});

const saving        = ref(false);
const fieldsLoading = ref(true);
const errorMessage  = ref('');
const form          = ref({ holder: '', cpf: '' });
const errors        = ref({});

// Instâncias dos Secure Fields — fora do reativo para não ser proxiado
let mpInstance       = null;
let sfCardNumber     = null;
let sfExpirationDate = null;
let sfSecurityCode   = null;

const paymentMethodId = ref('');

watch(() => isOpen.value, async (open) => {
    if (open) {
        errorMessage.value  = '';
        errors.value        = {};
        form.value          = { holder: '', cpf: '' };
        fieldsLoading.value = true;
        await nextTick();
        await new Promise(r => setTimeout(r, 120)); // aguarda animação do modal
        await initSecureFields();
    } else {
        destroyFields();
    }
});

async function loadMpSdk() {
    if (window.MercadoPago) return;
    return new Promise((resolve, reject) => {
        const s   = document.createElement('script');
        s.src     = 'https://sdk.mercadopago.com/js/v2';
        s.onload  = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
    });
}

async function initSecureFields() {
    const publicKey = authStore.user?.mp_public_key
        || document.querySelector('meta[name="mp-public-key"]')?.getAttribute('content')
        || '';

    if (!publicKey) {
        errorMessage.value  = 'Chave pública do Mercado Pago não configurada. Defina MP_PUBLIC_KEY no .env.';
        fieldsLoading.value = false;
        return;
    }

    await loadMpSdk();

    mpInstance = new window.MercadoPago(publicKey, { locale: 'pt-BR' });

    const isDark = document.documentElement.classList.contains('dark');
    const style  = {
        fontFamily:       '"Inter", ui-sans-serif, system-ui, sans-serif',
        fontSize:         '14px',
        color:            isDark ? '#f8fafc' : '#0f172a',
        placeholderColor: isDark ? '#64748b' : '#94a3b8',
    };

    let readyCount = 0;
    const onReady  = () => { if (++readyCount >= 3) fieldsLoading.value = false; };

    sfCardNumber = mpInstance.fields.create('cardNumber', { placeholder: '0000 0000 0000 0000', style });
    sfCardNumber.on('ready', onReady);
    sfCardNumber.on('binChange', async ({ bin }) => {
        if (!bin) { paymentMethodId.value = ''; return; }
        try {
            const { results } = await mpInstance.getPaymentMethods({ bin });
            paymentMethodId.value = results?.[0]?.id ?? '';
        } catch (_) {
            paymentMethodId.value = '';
        }
    });
    sfCardNumber.mount('sf-card-number');

    sfExpirationDate = mpInstance.fields.create('expirationDate', { placeholder: 'MM/AA', style });
    sfExpirationDate.on('ready', onReady);
    sfExpirationDate.mount('sf-expiration-date');

    sfSecurityCode = mpInstance.fields.create('securityCode', { placeholder: '000', style });
    sfSecurityCode.on('ready', onReady);
    sfSecurityCode.mount('sf-security-code');
}

function destroyFields() {
    try { sfCardNumber?.unmount();     } catch (_) {}
    try { sfExpirationDate?.unmount(); } catch (_) {}
    try { sfSecurityCode?.unmount();   } catch (_) {}
    sfCardNumber      = null;
    sfExpirationDate  = null;
    sfSecurityCode    = null;
    mpInstance        = null;
    paymentMethodId.value = '';
    fieldsLoading.value   = true;
}

function fieldClass(err) {
    return [
        'w-full px-4 py-2.5 rounded-xl border text-sm',
        'bg-white dark:bg-slate-900 text-slate-900 dark:text-white',
        'placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500',
        err ? 'border-red-400 dark:border-red-600' : 'border-slate-200 dark:border-slate-700',
    ];
}

function formatCpf(e) {
    let v = e.target.value.replace(/\D/g, '').substring(0, 11);
    if (v.length > 9)      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
    else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
    else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    form.value.cpf = v;
}

async function handleSubmit() {
    errorMessage.value = '';
    errors.value       = {};

    const e   = {};
    const cpf = form.value.cpf.replace(/\D/g, '');
    if (!form.value.holder.trim()) e.holder = 'Informe o nome do titular.';
    if (!cpf || cpf.length !== 11) e.cpf    = 'Informe um CPF válido.';
    if (Object.keys(e).length) { errors.value = e; return; }

    if (!paymentMethodId.value) {
        errorMessage.value = 'Digite o número do cartão para identificar a bandeira.';
        return;
    }

    saving.value = true;
    try {
        const token = await mpInstance.fields.createCardToken({
            cardholderName:       form.value.holder,
            identificationType:   'CPF',
            identificationNumber: cpf,
        });

        await axios.post('dashboard/cards', {
            mp_token:          token.id,
            cpf:               form.value.cpf,
            payment_method_id: paymentMethodId.value,
            holder_name:       form.value.holder,
            brand:             paymentMethodId.value,
        });

        isOpen.value = false;
        emit('saved');
        form.value   = { holder: '', cpf: '' };
        errors.value = {};

        await Swal.fire({
            icon:               'success',
            title:              'Cartão adicionado!',
            text:               `Cartão final ${token.last_four_digits} validado. O estorno de R$ 5,00 será processado em breve.`,
            confirmButtonText:  'Ok',
            confirmButtonColor: '#6366f1',
        });

    } catch (err) {
        if (Array.isArray(err)) {
            const fe = {};
            err.forEach(({ field }) => {
                if (field === 'cardNumber')     fe.number = 'Número do cartão inválido.';
                if (field === 'expirationDate') fe.expiry = 'Validade inválida.';
                if (field === 'securityCode')   fe.cvv    = 'CVV inválido.';
            });
            if (Object.keys(fe).length) errors.value = fe;
            else errorMessage.value = 'Verifique os dados do cartão.';
        } else {
            errorMessage.value = err?.response?.data?.message
                || err?.message
                || 'Erro ao validar o cartão. Verifique os dados.';
        }
    } finally {
        saving.value = false;
    }
}

onBeforeUnmount(() => destroyFields());
</script>

<style>
.sf-field {
    width: 100%;
    height: 42px;
    padding: 0 16px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background-color: #ffffff;
    display: flex;
    align-items: center;
    overflow: hidden;
    transition: border-color 0.15s;
}

.dark .sf-field {
    border-color: #334155;
    background-color: #0f172a;
}

.sf-field--error {
    border-color: #f87171 !important;
}

.dark .sf-field--error {
    border-color: #dc2626 !important;
}

.sf-field iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: transparent;
}
</style>
