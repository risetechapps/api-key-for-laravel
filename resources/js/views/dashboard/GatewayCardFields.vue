<template>
    <div class="space-y-4">
        <TextInput v-model="form.holder" label="Nome do titular" placeholder="Como está no cartão" uppercase autocomplete="cc-name" :error="errors.holder" />
        <TextInput v-if="needsCpf" v-model="form.cpf" label="CPF do titular" placeholder="000.000.000-00" inputmode="numeric" maxlength="14" :error="errors.cpf" @update:model-value="formatCpf" />

        <Field v-if="isStripe" label="Cartão" :error="errors.card">
            <div :class="['rounded-xl border bg-white px-3.5 py-3 transition-colors dark:bg-slate-900', errors.card ? 'border-red-400' : 'border-slate-300 dark:border-slate-700']">
                <div ref="stripeMount" class="min-h-[1.25rem]" />
            </div>
        </Field>

        <template v-else>
            <TextInput v-model="form.number" label="Número do cartão" placeholder="0000 0000 0000 0000" inputmode="numeric" maxlength="23" autocomplete="cc-number" mono :icon="PhCreditCard" :error="errors.number" @update:model-value="formatNumber" />
            <div class="grid grid-cols-2 gap-3">
                <TextInput v-model="form.expiry" label="Validade" placeholder="MM/AA" inputmode="numeric" maxlength="5" autocomplete="cc-exp" mono :error="errors.expiry" @update:model-value="formatExpiry" />
                <TextInput v-model="form.cvv" label="CVV" type="password" placeholder="•••" inputmode="numeric" maxlength="4" autocomplete="cc-csc" mono :error="errors.cvv" @update:model-value="(v) => (form.cvv = String(v).replace(/\D/g, ''))" />
            </div>
        </template>

    </div>
</template>

<script setup>
// Campos de cartão dos gateways além do Mercado Pago. O componente só valida e
// tokeniza no gateway ativo: quem o usa decide o que fazer com o resultado
// (salvar o cartão ou pagar).
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { PhCreditCard } from '@phosphor-icons/vue';
import { Field, TextInput } from '@/ui';
import { useAuthStore } from '@/stores/auth';
import { isDark } from '@/theme';
import { getStripe, tokenizeCard } from '@/utils/gateways';

const authStore = useAuthStore();

const gateway  = computed(() => authStore.user?.payment_gateway ?? 'mercadopago');
const config   = computed(() => authStore.user?.payment_gateway_config ?? {});
const isStripe = computed(() => gateway.value === 'stripe');
// O Stripe não pede CPF para o cartão; os gateways brasileiros, sim.
const needsCpf = computed(() => !isStripe.value);


const blank  = () => ({ holder: '', cpf: authStore.user?.profile?.cpf ?? '', number: '', expiry: '', cvv: '' });
const form   = ref(blank());
const errors = ref({});

// Stripe Elements
const stripeMount = ref(null);
let stripe = null;
let cardElement = null;

function elementStyle() {
    return {
        base: {
            fontSize: '15px',
            fontFamily: 'ui-sans-serif, system-ui, sans-serif',
            color: isDark.value ? '#f1f5f9' : '#0f172a',
            '::placeholder': { color: isDark.value ? '#64748b' : '#94a3b8' },
        },
        invalid: { color: '#ef4444' },
    };
}

async function mountStripe() {
    if (!isStripe.value || cardElement) return;
    try {
        stripe = await getStripe(config.value.publishable_key);
        await nextTick();
        if (!stripeMount.value) return;
        cardElement = stripe.elements().create('card', { hidePostalCode: true, style: elementStyle() });
        cardElement.on('change', (e) => {
            errors.value = { ...errors.value, card: e.error?.message ?? '' };
        });
        cardElement.mount(stripeMount.value);
    } catch (err) {
        errors.value = { ...errors.value, card: err?.message ?? 'Não foi possível carregar o pagamento com cartão.' };
    }
}

onMounted(mountStripe);
watch(isStripe, (v) => v && mountStripe());
watch(isDark, () => cardElement?.update({ style: elementStyle() }));
onBeforeUnmount(() => {
    cardElement?.destroy();
    cardElement = null;
});

function formatCpf(value) {
    let v = String(value ?? '').replace(/\D/g, '').substring(0, 11);
    if (v.length > 9)      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
    else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
    else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    form.value.cpf = v;
}

function formatNumber(value) {
    form.value.number = String(value ?? '').replace(/\D/g, '').substring(0, 19).replace(/(.{4})/g, '$1 ').trim();
}

function formatExpiry(value) {
    const v = String(value ?? '').replace(/\D/g, '').substring(0, 4);
    form.value.expiry = v.length > 2 ? `${v.slice(0, 2)}/${v.slice(2)}` : v;
}

/**
 * Stripe: confirma um SetupIntent com o cartão do Elements. O id dele é o
 * "token" que o servidor recebe; o 3DS, se o banco pedir, abre aqui.
 */
async function tokenizeStripe(holder) {
    if (!cardElement || !stripe) throw new Error('Pagamento com cartão indisponível no momento.');

    const intent = await axios.post('dashboard/cards/setup-intent');
    const clientSecret = intent.data?.data?.client_secret;

    const { setupIntent, error } = await stripe.confirmCardSetup(clientSecret, {
        payment_method: { card: cardElement, billing_details: { name: holder } },
    });

    if (error || !setupIntent?.id) {
        errors.value = { ...errors.value, card: error?.message ?? '' };
        throw new Error(error?.message || 'O cartão não foi aprovado na validação.');
    }

    return setupIntent.id;
}

/**
 * Valida e tokeniza. Lança Error com mensagem para a tela quando algo está
 * errado; os erros por campo ficam em `errors`.
 *
 * @param {{ forPayment?: boolean }} options `forPayment`: o resultado vai pagar agora.
 * @returns {Promise<{ token: string, holder: string, cpf: string, extra: object }>}
 */
async function tokenize({ forPayment = false } = {}) {
    errors.value = {};

    const holder = form.value.holder.trim();
    const cpf    = form.value.cpf.replace(/\D/g, '');
    const e = {};

    if (!holder)                          e.holder = 'Informe o nome do titular.';
    if (needsCpf.value && cpf.length !== 11) e.cpf = 'Informe um CPF válido.';

    if (isStripe.value) {
        if (Object.keys(e).length) {
            errors.value = e;
            throw new Error('Verifique os dados do cartão.');
        }
        return { token: await tokenizeStripe(holder), holder, cpf, extra: {} };
    }

    const number = form.value.number.replace(/\D/g, '');
    const [mm, yy] = form.value.expiry.split('/');
    const month = Number(mm);
    const year  = Number(yy);

    if (number.length < 13)                           e.number = 'Número do cartão inválido.';
    if (!(month >= 1 && month <= 12) || !(year >= 0)) e.expiry = 'Validade inválida.';
    if (form.value.cvv.length < 3)                    e.cvv    = 'CVV inválido.';

    if (Object.keys(e).length) {
        errors.value = e;
        throw new Error('Verifique os dados do cartão.');
    }

    const { token, extra } = await tokenizeCard(
        gateway.value,
        config.value,
        authStore.user?.pagarme_public_key || '',
        { number, holder, cpf, month, year: year < 100 ? 2000 + year : year, cvv: form.value.cvv },
        forPayment,
    );

    // O CVV não fica na memória da tela depois de virar token.
    form.value.cvv = '';

    return { token, holder, cpf, extra };
}

function reset() {
    form.value = blank();
    errors.value = {};
    cardElement?.clear();
}

defineExpose({ tokenize, reset });
</script>
