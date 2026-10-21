<template>
    <AuthShell :title="twoFactor.challenge ? 'Verificação em duas etapas' : 'Bem-vindo de volta'" :subtitle="twoFactor.challenge ? '' : 'Entre com sua conta para continuar'">
        <div class="mb-5 space-y-3 empty:hidden">
            <Alert v-if="verifiedMessage" tone="success">{{ verifiedMessage }}</Alert>
            <Alert v-if="authStore.error || errorMessage" tone="danger">{{ authStore.error || errorMessage }}</Alert>
            <Alert v-if="resendMessage" tone="info">{{ resendMessage }}</Alert>
        </div>

        <Transition name="ui-slide" mode="out-in">
            <!-- Segundo passo: código do aplicativo autenticador -->
            <form v-if="twoFactor.challenge" key="2fa" class="space-y-5" @submit.prevent="handleTwoFactor">
                <div class="flex items-center gap-3 rounded-2xl bg-brand-50 p-4 dark:bg-brand-500/10">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white"><PhShieldCheck :size="20" weight="fill" /></span>
                    <p class="text-sm text-brand-900 dark:text-brand-100">{{ twoFactor.useRecovery ? 'Digite um dos seus códigos de recuperação.' : 'Digite o código de 6 dígitos do seu aplicativo autenticador.' }}</p>
                </div>
                <TextInput
                    v-model="twoFactor.code"
                    :label="twoFactor.useRecovery ? 'Código de recuperação' : 'Código'"
                    :placeholder="twoFactor.useRecovery ? 'XXXXX-XXXXX' : '000 000'"
                    :inputmode="twoFactor.useRecovery ? 'text' : 'numeric'"
                    autocomplete="one-time-code"
                    autofocus
                    mono
                    size="lg"
                    required
                    :error="twoFactor.error"
                />
                <Button type="submit" block size="lg" :loading="authStore.loading">Verificar</Button>
                <div class="flex justify-between text-sm">
                    <button type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="twoFactor.useRecovery = !twoFactor.useRecovery; twoFactor.code = ''">
                        {{ twoFactor.useRecovery ? 'Usar o aplicativo' : 'Usar código de recuperação' }}
                    </button>
                    <button type="button" class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-300" @click="twoFactor.challenge = ''">Voltar</button>
                </div>
            </form>

            <form v-else key="login" class="space-y-5" @submit.prevent="handleLogin">
                <TextInput v-model="form.email" label="E-mail" type="email" autocomplete="email" required :icon="PhEnvelope" :error="errors.email" />
                <TextInput v-model="form.password" label="Senha" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required :icon="PhLockKey" :error="errors.password">
                    <template #suffix>
                        <button type="button" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword">
                            <PhEyeSlash v-if="showPassword" :size="18" />
                            <PhEye v-else :size="18" />
                        </button>
                    </template>
                </TextInput>

                <div class="flex items-center justify-between">
                    <Checkbox v-model="form.remember" label="Lembrar de mim" />
                    <router-link to="/forgot-password" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Esqueceu a senha?</router-link>
                </div>

                <Button type="submit" block size="lg" :icon-right="PhArrowRight" :loading="authStore.loading">Entrar</Button>

                <p class="text-center text-xs text-slate-500 dark:text-slate-400">
                    Não recebeu o e-mail de confirmação?
                    <button type="button" class="font-semibold text-brand-600 hover:underline disabled:opacity-50 dark:text-brand-400" :disabled="resending" @click="resendVerification">Reenviar</button>
                </p>
            </form>
        </Transition>

        <template #footer>
            Ainda não tem conta?
            <router-link to="/register" class="ml-1 font-semibold text-brand-600 hover:underline dark:text-brand-400">Criar conta</router-link>
        </template>
    </AuthShell>
</template>

<script setup>
import {reactive, ref, onMounted} from 'vue';
import {useRouter, useRoute} from 'vue-router';
import {useAuthStore} from '@/stores/auth';
import axios from 'axios';
import { PhEnvelope, PhLockKey, PhEye, PhEyeSlash, PhArrowRight, PhShieldCheck } from '@phosphor-icons/vue';
import { Alert, Button, Checkbox, TextInput } from '@/ui';
import AuthShell from '@/views/authentication/AuthShell.vue';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const showPassword = ref(false);
const twoFactor = reactive({ challenge: '', code: '', useRecovery: false, error: '' });
const resending = ref(false);
const resendMessage = ref('');
const verifiedMessage = ref('');
const errorMessage = ref('');

onMounted(() => {
    if (route.query.verified === '1') {
        verifiedMessage.value = 'E-mail confirmado com sucesso! Agora você pode fazer login.';
    }
    if (route.query.error === 'invalid_link') {
        errorMessage.value = 'Link de verificação inválido ou expirado. Informe seu e-mail e clique em "Reenviar" abaixo.';
    }
    // Enviado pelo interceptor de 401 do axios: sem esta mensagem o usuário
    // apareceria na tela de login sem saber por que foi expulso.
    if (route.query.error === 'session_expired') {
        errorMessage.value = 'Sua sessão expirou. Entre novamente para continuar.';
    }
    if (route.query.email_changed === '1') {
        verifiedMessage.value = 'E-mail alterado. Entre com o novo endereço.';
    }
    if (route.query.error === 'email_taken') {
        errorMessage.value = 'O novo e-mail passou a ser usado por outra conta. A troca não foi feita.';
    }
    if (route.query.error === 'account_disabled') {
        errorMessage.value = 'Esta conta está suspensa. Entre em contato com o suporte.';
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
        const result = await authStore.login({
            email: form.email,
            password: form.password,
        });

        if (result?.two_factor) {
            twoFactor.challenge = result.challenge;
            twoFactor.code = '';
            twoFactor.error = '';
            return;
        }

        router.push('/dashboard');
    } catch (err) {
        console.error('Login error:', err);

        // Erro 429 - Too Many Requests (Rate Limit)
        if (err.response?.status === 429) {
            errors.email = 'Muitas tentativas. Por favor, aguarde alguns minutos antes de tentar novamente.';
            return;
        }

        const message = err.response?.data?.message || '';

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

}

async function handleTwoFactor() {
    twoFactor.error = '';
    try {
        await authStore.verifyTwoFactor({
            challenge: twoFactor.challenge,
            ...(twoFactor.useRecovery ? { recovery_code: twoFactor.code } : { code: twoFactor.code }),
        });
        router.push('/dashboard');
    } catch (err) {
        // Desafio vencido (410): volta para a senha.
        if (err.response?.status === 410) {
            twoFactor.challenge = '';
            errors.email = err.response?.data?.message || 'A verificação expirou. Entre novamente.';
            return;
        }
        twoFactor.error = err.response?.status === 429
            ? 'Muitas tentativas. Aguarde alguns minutos.'
            : (err.response?.data?.message || 'Código inválido');
        authStore.error = null;
    }
}

// A API responde igual exista ou não a conta, então a mensagem também não diz.
async function resendVerification() {
    errors.email = '';
    resendMessage.value = '';

    if (!form.email) {
        errors.email = 'Informe o e-mail da conta para reenviar a confirmação';
        return;
    }

    resending.value = true;
    try {
        const response = await axios.post('/email/resend', { email: form.email });
        resendMessage.value = response.data?.data?.message || 'Se houver uma conta pendente com este e-mail, enviaremos um novo link.';
    } catch (err) {
        errors.email = err.response?.status === 429
            ? 'Muitas tentativas. Aguarde alguns minutos.'
            : (err.response?.data?.errors?.email?.[0] || 'Não foi possível reenviar agora.');
    } finally {
        resending.value = false;
    }
}
</script>
