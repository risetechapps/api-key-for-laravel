<template>
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-slate-50 via-indigo-50/30 to-purple-50/30 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
        <div class="w-full max-w-md">
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
                <div v-if="!success">
                    <div class="text-center mb-8">
                        <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">
                            Nova senha
                        </h1>
                        <p class="text-slate-600 dark:text-slate-400">
                            Defina uma nova senha para sua conta
                        </p>
                    </div>

                    <form @submit.prevent="handleSubmit" class="space-y-5">
                        <Input
                            v-model="form.password"
                            label="Nova senha"
                            :type="showPassword ? 'text' : 'password'"
                            placeholder=""
                            required
                            :icon="PhLockKey"
                            :error="errors.password"
                        >
                            <template #suffix>
                                <button type="button" @click="showPassword = !showPassword"
                                    class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                    <PhEye v-if="!showPassword" :size="20" />
                                    <PhEyeSlash v-else :size="20" />
                                </button>
                            </template>
                        </Input>

                        <Input
                            v-model="form.password_confirmation"
                            label="Confirmar nova senha"
                            :type="showPassword ? 'text' : 'password'"
                            placeholder=""
                            required
                            :icon="PhLockKey"
                            :error="errors.password_confirmation"
                        />

                        <Button type="submit" variant="primary" size="lg" class="w-full" :loading="loading">
                            Redefinir senha
                        </Button>
                    </form>

                    <div v-if="errorMessage"
                         class="mt-4 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-sm">
                        {{ errorMessage }}
                    </div>
                </div>

                <div v-else class="text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                        <PhCheck :size="32" weight="bold" class="text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Senha redefinida!</h2>
                    <p class="text-slate-600 dark:text-slate-400 mb-6">
                        Sua senha foi alterada com sucesso.
                    </p>
                    <router-link to="/login">
                        <Button variant="primary" size="lg" class="w-full">Ir para o login</Button>
                    </router-link>
                </div>

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
import { ref, reactive, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import {
    PhHexagon,
    PhLockKey,
    PhEye,
    PhEyeSlash,
    PhCheck,
    PhArrowLeft,
} from '@phosphor-icons/vue';
import Card from '@/views/componentes/Card.vue';
import Button from '@/views/componentes/Button.vue';
import Input from '@/views/componentes/Input.vue';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const success = ref(false);
const showPassword = ref(false);
const errorMessage = ref('');

const form = reactive({
    password: '',
    password_confirmation: '',
});

const errors = reactive({
    password: '',
    password_confirmation: '',
});

const token = ref('');
const email = ref('');

onMounted(() => {
    token.value = route.query.token ?? '';
    email.value = route.query.email ?? '';

    if (!token.value || !email.value) {
        errorMessage.value = 'Link de recuperação inválido ou expirado.';
    }
});

async function handleSubmit() {
    errors.password = '';
    errors.password_confirmation = '';
    errorMessage.value = '';

    if (!form.password) {
        errors.password = 'Senha é obrigatória';
        return;
    }

    if (form.password.length < 8) {
        errors.password = 'A senha deve ter no mínimo 8 caracteres';
        return;
    }

    if (form.password !== form.password_confirmation) {
        errors.password_confirmation = 'As senhas não coincidem';
        return;
    }

    loading.value = true;

    try {
        await axios.post('/reset-password', {
            token: token.value,
            email: email.value,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });

        success.value = true;
    } catch (err) {
        const message = err.response?.data?.message || '';
        if (err.response?.data?.errors) {
            const apiErrors = err.response.data.errors;
            errors.password = apiErrors.password?.[0] || '';
        } else if (message) {
            errorMessage.value = message;
        } else {
            errorMessage.value = 'Erro ao redefinir a senha. Tente novamente.';
        }
    } finally {
        loading.value = false;
    }
}
</script>
