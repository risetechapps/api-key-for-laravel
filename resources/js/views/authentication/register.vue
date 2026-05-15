<template>
    <div
        class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-slate-50
         via-indigo-50/30 to-purple-50/30 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <router-link to="/" class="inline-flex items-center gap-3 justify-center">
                    <div
                        class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center
                        justify-center shadow-lg shadow-indigo-500/30">
                        <PhHexagon :size="28" weight="fill" class="text-white"/>
                    </div>
                    <span
                        class="text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text
                        text-transparent">
                        Api Key
                    </span>
                </router-link>
            </div>

            <Card class="shadow-xl shadow-slate-200/50 dark:shadow-none">
                <div class="text-center mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">
                        Criar conta
                    </h1>
                    <p class="text-slate-600 dark:text-slate-400">
                        Comece a usar a API gratuitamente
                    </p>
                </div>

                <form @submit.prevent="handleRegister" class="space-y-5">
                    <Input
                        v-model="form.name"
                        label="Nome completo"
                        type="text"
                        placeholder="Seu nome"
                        required
                        :icon="PhUser"
                        :error="errors.name"
                    />

                    <Input
                        v-model="form.email"
                        label="Email"
                        type="email"
                        placeholder="seu@email.com"
                        required
                        :icon="PhEnvelope"
                        :error="errors.email"
                    />

                    <Input
                        v-model="form.password"
                        label="Senha"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder="Mínimo 8 caracteres"
                        required
                        :icon="PhLockKey"
                        :error="errors.password"
                    >
                        <template #suffix>
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                            >
                                <PhEye v-if="!showPassword" :size="20"/>
                                <PhEyeSlash v-else :size="20"/>
                            </button>
                        </template>
                    </Input>

                    <Input
                        v-model="form.password_confirmation"
                        label="Confirmar senha"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder="Digite a senha novamente"
                        required
                        :icon="PhLockKeyOpen"
                        :error="errors.password_confirmation"
                    />

                    <div class="flex items-start gap-2">
                        <input
                            v-model="form.terms"
                            type="checkbox"
                            id="terms"
                            required
                            class="mt-1 w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700"
                        />
                        <label for="terms" class="text-sm text-slate-600 dark:text-slate-400">
                            Concordo com os
                            <a href="#" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Termos de
                                Serviço</a>
                            e
                            <a href="#" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Política de
                                Privacidade</a>
                        </label>
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        class="w-full"
                        :loading="authStore.loading"
                    >
                        Criar conta
                    </Button>
                </form>

                <div class="mt-8 text-center">
                    <p class="text-slate-600 dark:text-slate-400">
                        Já tem uma conta?
                        <router-link
                            to="/login"
                            class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300 transition-colors ml-1"
                        >
                            Entrar
                        </router-link>
                    </p>
                </div>
            </Card>
        </div>
    </div>
</template>

<script setup>
import {reactive, ref} from 'vue';
import {useRouter} from 'vue-router';
import {useAuthStore} from '@/stores/auth';
import {
    PhHexagon,
    PhUser,
    PhEnvelope,
    PhLockKey,
    PhLockKeyOpen,
    PhEye,
    PhEyeSlash,
} from '@phosphor-icons/vue';
import Card from "@/views/componentes/Card.vue";
import Button from "@/views/componentes/Button.vue";
import Input from "@/views/componentes/Input.vue";

const router = useRouter();
const authStore = useAuthStore();

const showPassword = ref(false);

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

    if (hasError) return;

    try {
        await authStore.register({
            name: form.name,
            email: form.email,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });
        router.push('/dashboard');
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
