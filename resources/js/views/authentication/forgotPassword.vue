<template>
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-slate-50 via-indigo-50/30 to-purple-50/30 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <router-link to="/" class="inline-flex items-center gap-3 justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                        <PhHexagon :size="28" weight="fill" class="text-white" />
                    </div>
                    <span class="text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                        Api Key
                    </span>
                </router-link>
            </div>

            <Card class="shadow-xl shadow-slate-200/50 dark:shadow-none">
                <div v-if="!success" class="text-center mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">
                        Recuperar senha
                    </h1>
                    <p class="text-slate-600 dark:text-slate-400">
                        Digite seu email para receber as instruções
                    </p>
                </div>

                <div v-else class="text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                        <PhCheck :size="32" weight="bold" class="text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Email enviado!</h2>
                    <p class="text-slate-600 dark:text-slate-400">
                        Verifique sua caixa de entrada para redefinir sua senha.
                    </p>
                </div>

                <form v-if="!success" @submit.prevent="handleSubmit" class="space-y-5">
                    <Input
                        v-model="email"
                        label="Email"
                        type="email"
                        placeholder="seu@email.com"
                        required
                        :icon="PhEnvelope"
                        :error="error"
                    />

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        class="w-full"
                        :loading="loading"
                    >
                        Enviar instruções
                    </Button>
                </form>

                <div class="mt-8 text-center">
                    <router-link
                        to="/login"
                        class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                    >
                        <PhArrowLeft :size="16" />
                        Voltar para o login
                    </router-link>
                </div>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import {
    PhHexagon,
    PhEnvelope,
    PhCheck,
    PhArrowLeft,
} from '@phosphor-icons/vue';
import Card from "../componentes/Card.vue";
import Button from "../componentes/Button.vue";
import Input from "../componentes/Input.vue";

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
        // Endpoint pode variar conforme o package
        await axios.post('/forgot-password', { email: email.value });
        success.value = true;
    } catch (err) {
        error.value = err.response?.data?.message || 'Erro ao enviar email';
    } finally {
        loading.value = false;
    }
}
</script>
