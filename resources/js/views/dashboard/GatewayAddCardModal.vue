<template>
    <Modal v-model="isOpen" title="Adicionar cartão" :description="description">
        <form id="gateway-card-form" class="space-y-4" @submit.prevent="handleSubmit">
            <GatewayCardFields ref="fields" />
            <Alert v-if="errorMessage" tone="danger">{{ errorMessage }}</Alert>
        </form>
        <template #footer>
            <Button variant="ghost" @click="isOpen = false">Cancelar</Button>
            <Button type="submit" form="gateway-card-form" :icon="PhLockSimple" :loading="saving">Salvar cartão</Button>
        </template>
    </Modal>
</template>

<script setup>
// Cadastro de cartão nos gateways além do Mercado Pago.
import { ref, computed, watch } from 'vue';
import { PhLockSimple } from '@phosphor-icons/vue';
import axios from 'axios';
import { Alert, Button, Modal, toast } from '@/ui';
import { useAuthStore } from '@/stores/auth';
import GatewayCardFields from '@/views/dashboard/GatewayCardFields.vue';

const emit  = defineEmits(['update:modelValue', 'saved']);
const props = defineProps({ modelValue: { type: Boolean, default: false } });

const authStore = useAuthStore();

const isOpen = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val),
});

// A Efí não valida o cartão sem cobrar: ele é conferido na primeira cobrança.
const description = computed(() => authStore.user?.payment_gateway === 'efi'
    ? 'O cartão fica salvo para a renovação automática, sem digitar o CVV de novo.'
    : 'Validamos o cartão sem cobrança. Depois disso a renovação é cobrada nele automaticamente.');

const fields       = ref(null);
const saving       = ref(false);
const errorMessage = ref('');

watch(isOpen, (open) => {
    if (open) {
        errorMessage.value = '';
        fields.value?.reset();
    }
});

async function handleSubmit() {
    errorMessage.value = '';
    saving.value = true;

    try {
        const { token, holder, cpf, extra } = await fields.value.tokenize();

        const response = await axios.post('dashboard/cards', { card_token: token, holder_name: holder, cpf: cpf || null, extra });
        const card = response.data?.data;

        isOpen.value = false;
        emit('saved');

        toast.success('Cartão adicionado', { description: `Final ${card?.last_four ?? ''} salvo.` });
    } catch (err) {
        errorMessage.value = err?.response?.data?.message || err?.message || 'Erro ao salvar o cartão.';
    } finally {
        saving.value = false;
    }
}
</script>
