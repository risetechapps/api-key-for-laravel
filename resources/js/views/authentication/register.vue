<template>
    <AuthShell :title="sentTo ? 'Confirme seu e-mail' : 'Criar conta'" :subtitle="sentTo ? '' : 'Comece em menos de um minuto.'">
        <Transition name="ui-slide" mode="out-in">
            <div v-if="sentTo" key="sent" class="space-y-6 text-center">
                <div class="relative mx-auto h-20 w-20">
                    <span class="absolute inset-0 animate-ping rounded-full bg-brand-400/30 [animation-duration:2s]" />
                    <span class="relative flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-xl shadow-brand-600/30"><PhEnvelopeOpen :size="36" weight="duotone" /></span>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Enviamos um link de confirmação para <strong class="text-slate-900 dark:text-white">{{ sentTo }}</strong>. Confirme o endereço para poder entrar.</p>
                <Button block size="lg" to="/login" :icon-right="PhArrowRight">Ir para o login</Button>
                <p class="text-xs text-slate-500">Não chegou? Confira o spam. Na tela de login dá para pedir um novo link.</p>
            </div>

            <form v-else key="form" class="space-y-5" @submit.prevent="handleRegister">
                <TextInput v-model="form.name" label="Nome completo" autocomplete="name" placeholder="Seu nome" required :icon="PhUser" :error="errors.name" />
                <TextInput v-model="form.email" label="E-mail" type="email" autocomplete="email" placeholder="seu@email.com" required :icon="PhEnvelope" :error="errors.email" />
                <div>
                    <TextInput v-model="form.password" label="Senha" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" placeholder="Mínimo de 8 caracteres" required :icon="PhLockKey" :error="errors.password">
                        <template #suffix>
                            <button type="button" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword">
                                <PhEyeSlash v-if="showPassword" :size="18" />
                                <PhEye v-else :size="18" />
                            </button>
                        </template>
                    </TextInput>
                    <div v-if="form.password" class="mt-2 flex items-center gap-2">
                        <div class="flex flex-1 gap-1">
                            <span v-for="i in 4" :key="i" :class="['h-1 flex-1 rounded-full transition-colors duration-300', i <= strength.score ? strength.bar : 'bg-slate-200 dark:bg-slate-700']" />
                        </div>
                        <span :class="['text-xs font-medium', strength.text]">{{ strength.label }}</span>
                    </div>
                </div>
                <TextInput v-model="form.password_confirmation" label="Confirmar senha" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required :icon="PhLockKeyOpen" :error="errors.password_confirmation" />

                <Checkbox v-model="form.terms">
                    Concordo com os <router-link to="/terms" target="_blank" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Termos de Serviço</router-link>
                    e a <router-link to="/privacy" target="_blank" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Política de Privacidade</router-link>
                </Checkbox>
                <p v-if="errors.terms" class="-mt-3 text-xs font-medium text-red-600">{{ errors.terms }}</p>

                <Button type="submit" block size="lg" :icon-right="PhArrowRight" :loading="authStore.loading">Criar conta</Button>
            </form>
        </Transition>

        <template v-if="!sentTo" #footer>
            Já tem uma conta?
            <router-link to="/login" class="ml-1 font-semibold text-brand-600 hover:underline dark:text-brand-400">Entrar</router-link>
        </template>
    </AuthShell>
</template>

<script setup>
import {computed, reactive, ref} from 'vue';
import {useRouter} from 'vue-router';
import {useAuthStore} from '@/stores/auth';
import { PhUser, PhEnvelope, PhEnvelopeOpen, PhLockKey, PhLockKeyOpen, PhEye, PhEyeSlash, PhArrowRight } from '@phosphor-icons/vue';
import { Button, Checkbox, TextInput } from '@/ui';
import AuthShell from '@/views/authentication/AuthShell.vue';

const router = useRouter();
const authStore = useAuthStore();

const showPassword = ref(false);
const sentTo = ref('');

// Força aproximada, só para orientar: a regra que vale é a do servidor.
const strength = computed(() => {
    const p = form.password;
    let score = 0;
    if (p.length >= 8) score++;
    if (p.length >= 12) score++;
    if (/[A-Z]/.test(p) && /[a-z]/.test(p)) score++;
    if (/\d/.test(p) && /[^A-Za-z0-9]/.test(p)) score++;
    return [
        { score: 1, label: 'Fraca', bar: 'bg-red-500', text: 'text-red-600' },
        { score: 1, label: 'Fraca', bar: 'bg-red-500', text: 'text-red-600' },
        { score: 2, label: 'Razoável', bar: 'bg-amber-500', text: 'text-amber-600' },
        { score: 3, label: 'Boa', bar: 'bg-emerald-500', text: 'text-emerald-600' },
        { score: 4, label: 'Forte', bar: 'bg-emerald-500', text: 'text-emerald-600' },
    ][score];
});

const form = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const errors = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: '',
});

async function handleRegister() {
    Object.keys(errors).forEach((key) => errors[key] = '');

    let hasError = false;

    if (!form.name) {
        errors.name = 'Nome é obrigatório';
        hasError = true;
    }

    if (!form.email) {
        errors.email = 'Email é obrigatório';
        hasError = true;
    }

    if (!form.password) {
        errors.password = 'Senha é obrigatória';
        hasError = true;
    } else if (form.password.length < 8) {
        errors.password = 'Senha deve ter no mínimo 8 caracteres';
        hasError = true;
    }

    if (form.password !== form.password_confirmation) {
        errors.password_confirmation = 'Senhas não conferem';
        hasError = true;
    }

    if (!form.terms) {
        errors.terms = 'Aceite os termos para continuar';
        hasError = true;
    }

    if (hasError) return;

    try {
        // A chave gerada no cadastro não é mostrada aqui. Quem se inscreve pode
        // usar só o painel e nunca tocar na API, e despejar um segredo
        // irrecuperável na primeira tela obriga todo mundo a decidir o que fazer
        // com ele antes mesmo de conhecer o produto. Quem for consumir a API
        // gera a chave no perfil, quando precisar.
        await authStore.register({
            name: form.name,
            email: form.email,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });

        // Login exige e-mail verificado, então o destino é a tela de login e não
        // o painel — a versão anterior mandava para /dashboard com uma sessão que
        // nunca existiu.
        sentTo.value = form.email;
    } catch (err) {
        // Erro 429 - Too Many Requests (Rate Limit)
        if (err.response?.status === 429) {
            errors.email = 'Muitas tentativas. Por favor, aguarde alguns minutos antes de tentar novamente.';
            return;
        }

        if (err.response?.data?.errors) {
            const apiErrors = err.response.data.errors;
            errors.name = apiErrors.name?.[0] || '';
            errors.email = apiErrors.email?.[0] || '';
            errors.password = apiErrors.password?.[0] || '';
            errors.password_confirmation = apiErrors.password_confirmation?.[0] || '';
        } else if (err.response?.data?.message) {
            errors.email = err.response.data.message;
        }
    }
}
</script>
