<template>
    <AuthShell :title="invite ? `Entrar na equipe de ${invite.account}` : 'Convite para a equipe'" :subtitle="invite ? `Você foi convidado como ${roleLabel}. Crie sua senha para acessar.` : ''">
        <div v-if="loading" class="space-y-3"><Skeleton class="h-10" /><Skeleton class="h-10" /><Skeleton class="h-10" /></div>

        <div v-else-if="invalid" class="space-y-6 text-center">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300"><PhWarningCircle :size="30" weight="bold" /></span>
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ invalid }}</p>
            <Button block size="lg" to="/login">Ir para o login</Button>
        </div>

        <form v-else class="space-y-5" @submit.prevent="accept">
            <Alert v-if="errorMessage" tone="danger">{{ errorMessage }}</Alert>
            <TextInput :model-value="invite?.email" label="E-mail" disabled :icon="PhEnvelope" />
            <TextInput v-model="form.name" label="Seu nome" hint="É o nome que os clientes veem quando você responde." required :icon="PhUser" :error="errors.name" />
            <TextInput v-model="form.password" label="Senha" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required :icon="PhLockKey" :error="errors.password">
                <template #suffix>
                    <button type="button" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword">
                        <PhEyeSlash v-if="showPassword" :size="18" />
                        <PhEye v-else :size="18" />
                    </button>
                </template>
            </TextInput>
            <TextInput v-model="form.password_confirmation" label="Confirmar senha" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required :icon="PhLockKey" />
            <Button type="submit" block size="lg" :loading="saving">Criar senha e entrar</Button>
        </form>
    </AuthShell>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { PhEnvelope, PhEye, PhEyeSlash, PhLockKey, PhUser, PhWarningCircle } from '@phosphor-icons/vue';
import { Alert, Button, Skeleton, TextInput } from '@/ui';
import AuthShell from '@/views/authentication/AuthShell.vue';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const token = String(route.params.token || '');
const invite = ref<{ name: string; email: string; role: string; account: string } | null>(null);
const loading = ref(true);
const invalid = ref('');
const saving = ref(false);
const showPassword = ref(false);
const errorMessage = ref('');
const form = reactive({ name: '', password: '', password_confirmation: '' });
const errors = reactive({ name: '', password: '' });

const roleLabel = computed(() => (invite.value?.role === 'admin' ? 'administrador' : 'atendente'));

onMounted(async () => {
    // Quem já está logado (outra conta) sai antes: o convite cria uma conta nova.
    if (auth.isAuthenticated) auth.clearAuth();

    try {
        const { data } = await axios.get(`/team/invitations/${token}`);
        invite.value = data.data;
        form.name = data.data.name || '';
    } catch (e: any) {
        invalid.value = e?.response?.data?.message || 'Este convite expirou, já foi usado ou foi cancelado. Peça um novo convite.';
    } finally {
        loading.value = false;
    }
});

async function accept() {
    errors.name = '';
    errors.password = '';
    errorMessage.value = '';

    if (form.password.length < 8) {
        errors.password = 'A senha deve ter no mínimo 8 caracteres';
        return;
    }
    if (form.password !== form.password_confirmation) {
        errors.password = 'As senhas não conferem';
        return;
    }

    saving.value = true;
    try {
        await auth.acceptInvitation(token, { name: form.name, password: form.password, password_confirmation: form.password_confirmation });
        router.push('/dashboard');
    } catch (e: any) {
        const bag = e?.response?.data?.errors;
        if (bag?.password) errors.password = bag.password[0];
        else if (bag?.name) errors.name = bag.name[0];
        else errorMessage.value = bag ? (Object.values(bag).flat()[0] as string) : e?.response?.data?.message || 'Não foi possível aceitar o convite.';
    } finally {
        saving.value = false;
    }
}
</script>
