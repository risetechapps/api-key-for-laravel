<template>
    <AuthShell :title="success ? 'Verifique seu e-mail' : 'Recuperar senha'" :subtitle="success ? '' : 'Enviamos um link para você criar uma senha nova.'">
        <Transition name="ui-slide" mode="out-in">
            <div v-if="success" key="ok" class="space-y-6 text-center">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 animate-pop dark:bg-emerald-500/15 dark:text-emerald-300"><PhCheck :size="30" weight="bold" /></span>
                <p class="text-sm text-slate-600 dark:text-slate-400">Se houver uma conta com <strong class="text-slate-900 dark:text-white">{{ email }}</strong>, o link para redefinir a senha chega em instantes.</p>
            </div>
            <form v-else key="form" class="space-y-5" @submit.prevent="handleSubmit">
                <TextInput v-model="email" label="E-mail" type="email" autocomplete="email" placeholder="seu@email.com" required :icon="PhEnvelope" :error="error" />
                <Button type="submit" block size="lg" :loading="loading">Enviar link</Button>
            </form>
        </Transition>
        <template #footer>
            <router-link to="/login" class="inline-flex items-center gap-2 font-medium text-slate-600 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400"><PhArrowLeft :size="16" /> Voltar para o login</router-link>
        </template>
    </AuthShell>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { PhEnvelope, PhCheck, PhArrowLeft } from '@phosphor-icons/vue';
import { Button, TextInput } from '@/ui';
import AuthShell from '@/views/authentication/AuthShell.vue';

const email = ref('');
const loading = ref(false);
const error = ref('');
const success = ref(false);

async function handleSubmit() {
    if (!email.value) {
        error.value = 'Email é obrigatório';
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        await axios.post('/forgot-password', { email: email.value });
        success.value = true;
    } catch (err) {
        error.value = err.response?.status === 429
            ? 'Muitas tentativas. Aguarde alguns minutos antes de tentar de novo.'
            : (err.response?.data?.errors?.email?.[0] || err.response?.data?.message || 'Erro ao enviar email');
    } finally {
        loading.value = false;
    }
}
</script>
