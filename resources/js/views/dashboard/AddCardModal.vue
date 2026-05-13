<template>
    <Modal v-model="isOpen" title="Adicionar Cartão">
        <form @submit.prevent="handleSubmit" class="space-y-4">
            <!-- Número do cartão -->
            <div>
                <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                    Número do cartão
                </label>
                <div class="relative">
                    <input
                        v-model="form.number"
                        type="text"
                        inputmode="numeric"
                        placeholder="0000 0000 0000 0000"
                        maxlength="19"
                        :class="fieldClass(errors.number)"
                        @input="formatCardNumber"
                    />
                    <div class="absolute right-3 top-1/2 -translate-y-1/2">
                        <component :is="brandIcon" v-if="brandIcon" class="w-8 h-5 text-slate-400" />
                        <PhCreditCard v-else :size="20" class="text-slate-400" />
                    </div>
                </div>
                <p v-if="errors.number" class="mt-1 text-xs text-red-500">{{ errors.number }}</p>
            </div>

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

            <!-- Validade + CVV -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                        Validade
                    </label>
                    <input
                        v-model="form.expiry"
                        type="text"
                        inputmode="numeric"
                        placeholder="MM/AA"
                        maxlength="5"
                        :class="fieldClass(errors.expiry)"
                        @input="formatExpiry"
                    />
                    <p v-if="errors.expiry" class="mt-1 text-xs text-red-500">{{ errors.expiry }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">
                        CVV
                    </label>
                    <input
                        v-model="form.cvv"
                        type="text"
                        inputmode="numeric"
                        placeholder="000"
                        maxlength="4"
                        :class="fieldClass(errors.cvv)"
                    />
                    <p v-if="errors.cvv" class="mt-1 text-xs text-red-500">{{ errors.cvv }}</p>
                </div>
            </div>

            <!-- Erro geral -->
            <div v-if="errorMessage" class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 flex items-start gap-3">
                <PhWarningCircle :size="18" weight="fill" class="text-red-500 mt-0.5 flex-shrink-0" />
                <p class="text-sm text-red-700 dark:text-red-400">{{ errorMessage }}</p>
            </div>

            <Button type="submit" variant="primary" class="w-full" :loading="saving">
                Validar e Salvar Cartão
            </Button>
        </form>
    </Modal>
</template>

<script setup>
import { ref, computed } from 'vue';
import { PhCreditCard, PhWarningCircle } from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import axios from 'axios';
import Modal from '@/views/componentes/Modal.vue';
import Button from '@/views/componentes/Button.vue';

const emit = defineEmits(['update:modelValue', 'saved']);

const props = defineProps({
    modelValue: { type: Boolean, default: false },
});

const isOpen = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val),
});

const saving       = ref(false);
const errorMessage = ref('');

const form = ref({ number: '', holder: '', expiry: '', cvv: '' });
const errors = ref({});

const cardBrand = computed(() => {
    const n = form.value.number.replace(/\s/g, '');
    if (/^4/.test(n))              return 'visa';
    if (/^5[1-5]/.test(n))        return 'master';
    if (/^3[47]/.test(n))         return 'amex';
    if (/^6(?:011|5)/.test(n))    return 'elo';
    if (/^(?:2131|1800|35)/.test(n)) return 'jcb';
    return null;
});

const brandIcon = computed(() => null); // Could map to SVG icons if available

function fieldClass(err) {
    return [
        'w-full px-4 py-2.5 rounded-xl border text-sm',
        'bg-white dark:bg-slate-900 text-slate-900 dark:text-white',
        'placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500',
        err
            ? 'border-red-400 dark:border-red-600'
            : 'border-slate-200 dark:border-slate-700',
    ];
}

function formatCardNumber(e) {
    let v = e.target.value.replace(/\D/g, '').substring(0, 16);
    form.value.number = v.replace(/(.{4})/g, '$1 ').trim();
}

function formatExpiry(e) {
    let v = e.target.value.replace(/\D/g, '').substring(0, 4);
    if (v.length >= 3) v = v.substring(0, 2) + '/' + v.substring(2);
    form.value.expiry = v;
}

function validate() {
    const e = {};
    const num = form.value.number.replace(/\s/g, '');

    if (!num || num.length < 13 || !luhn(num)) {
        e.number = 'Número de cartão inválido.';
    }
    if (!form.value.holder.trim()) {
        e.holder = 'Informe o nome do titular.';
    }
    const [mm, yy] = (form.value.expiry || '').split('/');
    if (!mm || !yy || mm.length !== 2 || yy.length !== 2) {
        e.expiry = 'Validade inválida.';
    } else {
        const month = parseInt(mm, 10);
        const year  = 2000 + parseInt(yy, 10);
        const now   = new Date();
        const currentYear  = now.getFullYear();
        const currentMonth = now.getMonth() + 1; // 1-indexed
        if (month < 1 || month > 12 || year < currentYear || (year === currentYear && month < currentMonth)) {
            e.expiry = 'Cartão expirado.';
        }
    }
    if (!form.value.cvv || form.value.cvv.length < 3) {
        e.cvv = 'CVV inválido.';
    }
    errors.value = e;
    return Object.keys(e).length === 0;
}

function luhn(num) {
    let sum = 0;
    let alt = false;
    for (let i = num.length - 1; i >= 0; i--) {
        let n = parseInt(num[i]);
        if (alt) { n *= 2; if (n > 9) n -= 9; }
        sum += n;
        alt = !alt;
    }
    return sum % 10 === 0;
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

async function handleSubmit() {
    errorMessage.value = '';
    if (!validate()) return;

    const publicKey = import.meta.env.VITE_MP_PUBLIC_KEY;
    if (!publicKey) {
        errorMessage.value = 'Chave pública do Mercado Pago não configurada.';
        return;
    }

    saving.value = true;
    try {
        await loadMpSdk();
        const mp = new window.MercadoPago(publicKey, { locale: 'pt-BR' });

        const [mm, yy] = form.value.expiry.split('/');
        const num = form.value.number.replace(/\s/g, '');

        const token = await mp.createCardToken({
            cardNumber:          num,
            cardholderName:      form.value.holder,
            cardExpirationMonth: mm,
            cardExpirationYear:  '20' + yy,
            securityCode:        form.value.cvv,
        });

        // Token criado com sucesso → cartão válido. Salva metadados localmente.
        // O link com MP Customer ocorre automaticamente na primeira compra.
        await axios.post('dashboard/cards', {
            holder_name:  form.value.holder,
            last_four:    token.last_four_digits,
            brand:        cardBrand.value || 'outros',
            expiry_month: token.expiration_month?.toString().padStart(2, '0') ?? mm,
            expiry_year:  token.expiration_year?.toString() ?? ('20' + yy),
        });

        isOpen.value = false;
        emit('saved');

        await Swal.fire({
            icon: 'success',
            title: 'Cartão adicionado!',
            text: `Cartão final ${token.last_four_digits} validado e salvo com sucesso.`,
            confirmButtonText: 'Ok',
            confirmButtonColor: '#6366f1',
        });

        // Limpa o formulário
        form.value  = { number: '', holder: '', expiry: '', cvv: '' };
        errors.value = {};

    } catch (err) {
        const msg = err?.message || err?.cause?.message || 'Erro ao validar o cartão. Verifique os dados.';
        errorMessage.value = msg;
    } finally {
        saving.value = false;
    }
}
</script>
