<template>
    <AuthShell :title="success ? 'Senha redefinida' : 'Criar nova senha'" :subtitle="success ? '' : 'Escolha uma senha que você não usa em outro lugar.'">
        <Transition name="ui-slide" mode="out-in">
            <div v-if="success" key="ok" class="space-y-6 text-center">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 animate-pop dark:bg-emerald-500/15 dark:text-emerald-300"><PhCheck :size="30" weight="bold" /></span>
                <p class="text-sm text-slate-600 dark:text-slate-400">Sua senha foi alterada. Entre com a senha nova.</p>
                <Button block size="lg" to="/login">Ir para o login</Button>
            </div>
            <form v-else key="form" class="space-y-5" @submit.prevent="handleSubmit">
                <Alert v-if="errorMessage" tone="danger">{{ errorMessage }}</Alert>
                <TextInput v-model="form.password" label="Nova senha" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required :icon="PhLockKey" :error="errors.password">
                    <template #suffix>
                        <button type="button" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword">
                            <PhEyeSlash v-if="showPassword" :size="18" />
                            <PhEye v-else :size="18" />
                        </button>
                    </template>
                </TextInput>
                <TextInput v-model="form.password_confirmation" label="Confirmar nova senha" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required :icon="PhLockKey" :error="errors.password_confirmation" />
                <Button type="submit" block size="lg" :loading="loading">Redefinir senha</Button>
            </form>
        </Transition>
        <template v-if="!success" #footer>
            <router-link to="/login" class="inline-flex items-center gap-2 font-medium text-slate-600 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400"><PhArrowLeft :size="16" /> Voltar para o login</router-link>
        </template>
    </AuthShell>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { PhLockKey, PhEye, PhEyeSlash, PhCheck, PhArrowLeft } from '@phosphor-icons/vue';
import { Alert, Button, TextInput } from '@/ui';
import AuthShell from '@/views/authentication/AuthShell.vue';

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
