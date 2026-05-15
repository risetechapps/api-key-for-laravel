<template>
    <div
        class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gradient-to-br
        from-slate-50 via-indigo-50/30 to-purple-50/30 dark:from-slate-900 dark:via-slate-900
        dark:to-slate-900">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <router-link to="/" class="inline-flex items-center gap-3 justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex
                     items-center justify-center shadow-lg shadow-indigo-500/30">
                        <PhHexagon :size="28" weight="fill" class="text-white"/>
                    </div>
                    <span class="text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text
                    text-transparent">
                        Api Key
                    </span>
                </router-link>
            </div>

            <Card class="shadow-xl shadow-slate-200/50 dark:shadow-none">
                <div class="text-center mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">
                        Bem-vindo de volta
                    </h1>
                    <p class="text-slate-600 dark:text-slate-400">
                        Entre com sua conta para continuar
                    </p>
                </div>

                <form @submit.prevent="handleLogin" class="space-y-5">
                    <Input
                        v-model="form.email"
                        label="Email"
                        type="email"
                        placeholder=""
                        required
                        :icon="PhEnvelope"
                        :error="errors.email"
                    />

                    <Input
                        v-model="form.password"
                        label="Senha"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder=""
                        required
                        :icon="PhLockKey"
                        :error="errors.password"
                    >
                        <template #suffix>
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300
                                transition-colors">
                                <PhEye v-if="!showPassword" :size="20"/>
                                <PhEyeSlash v-else :size="20"/>
                            </button>
                        </template>
                    </Input>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500
                                dark:border-slate-600 dark:bg-slate-700"
                            />
                            <span class="text-sm text-slate-600 dark:text-slate-400">Lembrar de mim</span>
                        </label>

                        <router-link
                            to="/forgot-password"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400
                            dark:hover:text-indigo-300 transition-colors">
                            Esqueceu a senha?
                        </router-link>
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        class="w-full"
                        :loading="authStore.loading">
                        Entrar
                    </Button>
                </form>

                <div v-if="verifiedMessage"
                     class="mt-4 p-4 rounded-xl bg-green-50 dark:bg-green-900/20 text-green-700
                     dark:text-green-400 text-sm">
                    {{ verifiedMessage }}
                </div>

                <div v-if="authStore.error || errorMessage"
                     class="mt-4 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-600
                     dark:text-red-400 text-sm">
                    {{ authStore.error || errorMessage }}
                </div>

                <div class="mt-8 text-center">
                    <p class="text-slate-600 dark:text-slate-400">
                        Ainda não tem conta?
                        <router-link
                            to="/register"
                            class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400
                            dark:hover:text-indigo-300 transition-colors ml-1">
                            Criar conta
                        </router-link>
                    </p>
                </div>
            </Card>
        </div>
    </div>
</template>

<script setup>
import {reactive, ref, onMounted} from 'vue';
import {useRouter, useRoute} from 'vue-router';
import {useAuthStore} from '@/stores/auth';
import {
    PhHexagon,
    PhEnvelope,
    PhLockKey,
    PhEye,
    PhEyeSlash,
} from '@phosphor-icons/vue';
import Card from "@/views/componentes/Card.vue";
import Button from "@/views/componentes/Button.vue";
import Input from "@/views/componentes/Input.vue";

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const showPassword = ref(false);
const verifiedMessage = ref('');
const errorMessage = ref('');

onMounted(() => {
    if (route.query.verified === '1') {
        verifiedMessage.value = 'E-mail confirmado com sucesso! Agora você pode fazer login.';
    }
    if (route.query.error === 'invalid_link') {
        errorMessage.value = 'Link de verificação inválido ou expirado. Faça login para reenviar o e-mail.';
    }
});

const form = reactive({
    email: '',
    password: '',
    remember: false,
});

const errors = reactive({
    email: '',
    password: '',
});

async function handleLogin() {
    errors.email = '';
    errors.password = '';

    if (!form.email) {
        errors.email = 'Email é obrigatório';
        return;
    }

    if (!form.password) {
        errors.password = 'Senha é obrigatória';
        return;
    }

    try {
        await authStore.login({
            email: form.email,
            password: form.password,
        });
        router.push('/dashboard');
    } catch (err) {
        console.error('Login error:', err);

        // Erro 429 - Too Many Requests (Rate Limit)
        if (err.response?.status === 429) {
            errors.email = 'Muitas tentativas. Por favor, aguarde alguns minutos antes de tentar novamente.';
            return;
        }

        // Traduzir mensagens conhecidas
        const message = err.response?.data?.message || '';
        // const translatedMessage = translateErrorMessage(message);

        if (err.response?.data?.errors) {
            const apiErrors = err.response.data.errors;
            errors.email = apiErrors.email?.[0] || '';
            errors.password = apiErrors.password?.[0] || '';
        } else if (message) {
            errors.email = message;
        } else {
            errors.email = 'Erro ao conectar com o servidor';
        }
    }

    function translateErrorMessage(msg) {
        const translations = {
            'Account not verified, please check your email inbox.': 'Conta não verificada, por favor verifique sua caixa de e-mail.',
            'User not found': 'Usuário não encontrado',
            'Incorrect username or password': 'Usuário ou senha incorretos',
        };
        return translations[msg] || msg;
    }
}
</script>
