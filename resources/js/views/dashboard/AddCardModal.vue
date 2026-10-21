<template>
    <Modal v-model="isOpen" title="Adicionar cartão" description="Os dados do cartão vão direto para o Mercado Pago, sem passar pelo nosso servidor.">
        <form @submit.prevent="handleSubmit" class="space-y-4">

            <TextInput v-model="form.holder" label="Nome do titular" placeholder="Como está no cartão" uppercase autocomplete="cc-name" :error="errors.holder" />
            <TextInput v-model="form.cpf" label="CPF do titular" placeholder="000.000.000-00" inputmode="numeric" maxlength="14" :error="errors.cpf" @update:model-value="formatCpf" />

            <!-- Skeleton enquanto os Secure Fields carregam -->
            <template v-if="fieldsLoading">
                <div>
                    <div class="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">Número do cartão</div>
                    <div class="h-10 rounded-xl ui-shimmer"></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">Validade</div>
                        <div class="h-10 rounded-xl ui-shimmer"></div>
                    </div>
                    <div>
                        <div class="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">CVV</div>
                        <div class="h-10 rounded-xl ui-shimmer"></div>
                    </div>
                </div>
            </template>

            <!-- Número do cartão — Secure Field (iframe MP) -->
            <div :class="{ hidden: fieldsLoading }">
                <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Número do cartão
                </label>
                <div id="sf-card-number" class="sf-field" :class="{ 'sf-field--error': errors.number }"></div>
                <p v-if="errors.number" class="mt-1 text-xs text-red-500">{{ errors.number }}</p>
            </div>

            <!-- Validade + CVV — Secure Fields (iframes MP) -->
            <div class="grid grid-cols-2 gap-3" :class="{ hidden: fieldsLoading }">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Validade
                    </label>
                    <div id="sf-expiration-date" class="sf-field" :class="{ 'sf-field--error': errors.expiry }"></div>
                    <p v-if="errors.expiry" class="mt-1 text-xs text-red-500">{{ errors.expiry }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        CVV
                    </label>
                    <div id="sf-security-code" class="sf-field" :class="{ 'sf-field--error': errors.cvv }"></div>
                    <p v-if="errors.cvv" class="mt-1 text-xs text-red-500">{{ errors.cvv }}</p>
                </div>
            </div>

            <Alert tone="info">Para validar o cartão, cobramos R$ 5,00 e estornamos na hora, automaticamente.</Alert>

            <Alert v-if="errorMessage" tone="danger">{{ errorMessage }}</Alert>

            <Button type="submit" block size="lg" :icon="PhLockSimple" :loading="saving" :disabled="fieldsLoading">Validar e salvar cartão</Button>
        </form>
    </Modal>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { PhLockSimple } from '@phosphor-icons/vue';
import axios from 'axios';
import { Alert, Button, Modal, TextInput, toast } from '@/ui';
import { useAuthStore } from '@/stores/auth';
import { primeDeviceSession, getDeviceSessionId } from '@/mercadopago-device';

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
const paymentTypeId   = ref('');

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
    // Disparado junto do SDK para o identificador de dispositivo já estar
    // pronto quando o usuário clicar em salvar; pedido só no clique, ele
    // costuma não ter terminado a tempo.
    primeDeviceSession();

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
        if (!bin) { paymentMethodId.value = ''; paymentTypeId.value = ''; return; }
        try {
            const { results } = await mpInstance.getPaymentMethods({ bin });
            paymentMethodId.value = results?.[0]?.id ?? '';
            paymentTypeId.value   = results?.[0]?.payment_type_id ?? '';
        } catch (_) {
            paymentMethodId.value = '';
            paymentTypeId.value   = '';
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
    paymentTypeId.value   = '';
    fieldsLoading.value   = true;
}

function fieldClass(err) {
    return [
        'w-full px-4 py-2.5 rounded-xl border text-sm',
        'bg-white dark:bg-slate-900 text-slate-900 dark:text-white',
        'placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500',
        err ? 'border-red-400 dark:border-red-600' : 'border-slate-200 dark:border-slate-700',
    ];
}

function formatCpf(value) {
    let v = String(value ?? '').replace(/\D/g, '').substring(0, 11);
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

        // Segundo token, para o perfil de Pagamentos Automáticos: cada token
        // serve uma vez só, e o primeiro vai na cobrança de validação. É o que
        // deixa a renovação cobrar o cartão sem pedir o CVV de novo. Melhor
        // esforço: sem ele o cartão é salvo do mesmo jeito.
        let profileToken = null;
        try {
            profileToken = await mpInstance.fields.createCardToken({
                cardholderName:       form.value.holder,
                identificationType:   'CPF',
                identificationNumber: cpf,
            });
        } catch (_) {
            profileToken = null;
        }

        // Melhor esforço: se o script de segurança não respondeu, segue sem o
        // identificador em vez de impedir o cadastro do cartão.
        const deviceId = await getDeviceSessionId();

        await axios.post('dashboard/cards', {
            mp_token:          token.id,
            cpf:               form.value.cpf,
            payment_method_id: paymentMethodId.value,
            holder_name:       form.value.holder,
            brand:             paymentMethodId.value,
            device_id:         deviceId,
            profile_token:     profileToken?.id ?? undefined,
            payment_type_id:   paymentTypeId.value || undefined,
        });

        isOpen.value = false;
        emit('saved');
        form.value   = { holder: '', cpf: '' };
        errors.value = {};

        toast.success('Cartão adicionado', { description: `Final ${token.last_four_digits} validado. O estorno dos R$ 5,00 já foi pedido.` });

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
    height: 40px;
    padding: 0 14px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background-color: #ffffff;
    display: flex;
    align-items: center;
    overflow: hidden;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.05);
    transition: border-color 0.15s, box-shadow 0.15s;
}

.sf-field:hover {
    border-color: #cbd5e1;
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
