<template>
    <div>
        <PageHeader title="Segurança" subtitle="Senha, e-mail de acesso, verificação em duas etapas e sessões abertas" :icon="PhShieldCheck" />

        <Alert v-if="twoFactorRequired" tone="warning" title="Ative a verificação em duas etapas" class="mb-8">
            Sua conta faz parte da equipe do painel: a área de administração só abre com a verificação em duas etapas ativa.
        </Alert>

        <div class="space-y-10">
            <!-- Senha -->
            <section class="grid gap-6 lg:grid-cols-3">
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">Senha</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Ao trocar, as outras sessões abertas são encerradas.</p>
                </div>
                <Card class="lg:col-span-2">
                    <form id="password-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="changePassword">
                        <TextInput v-model="password.current_password" class="sm:col-span-2" type="password" label="Senha atual" autocomplete="current-password" :error="password.errors.current_password" />
                        <TextInput v-model="password.password" type="password" label="Nova senha" autocomplete="new-password" :error="password.errors.password" hint="Mínimo de 8 caracteres" />
                        <TextInput v-model="password.password_confirmation" type="password" label="Confirmar nova senha" autocomplete="new-password" />
                    </form>
                    <template #footer>
                        <div class="flex justify-end"><Button type="submit" form="password-form" :loading="password.loading">Alterar senha</Button></div>
                    </template>
                </Card>
            </section>

            <!-- E-mail -->
            <section class="grid gap-6 lg:grid-cols-3">
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">E-mail de acesso</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">A troca só vale depois da confirmação pelo link enviado ao novo endereço.</p>
                </div>
                <Card class="lg:col-span-2">
                    <div class="mb-5 flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-slate-500 dark:text-slate-400">Atual:</span>
                        <strong class="text-slate-900 dark:text-white">{{ user?.email }}</strong>
                        <Badge v-if="user?.pending_email" tone="warning" dot pulse>Aguardando confirmação de {{ user.pending_email }}</Badge>
                    </div>
                    <form id="email-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="changeEmail">
                        <TextInput v-model="email.email" type="email" label="Novo e-mail" :icon="PhEnvelopeSimple" :error="email.errors.email" />
                        <TextInput v-model="email.password" type="password" label="Senha atual" autocomplete="current-password" :error="email.errors.password" />
                    </form>
                    <template #footer>
                        <div class="flex justify-end"><Button type="submit" form="email-form" :loading="email.loading">Enviar confirmação</Button></div>
                    </template>
                </Card>
            </section>

            <!-- 2FA -->
            <section class="grid gap-6 lg:grid-cols-3">
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">Verificação em duas etapas</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Além da senha, o login pede um código do aplicativo autenticador.</p>
                </div>
                <Card class="lg:col-span-2">
                    <div v-if="twoFactorEnabled" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300"><PhShieldCheck :size="20" weight="fill" /></span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Ativa</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Sua conta pede o código a cada login.</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" :icon="PhKey" @click="regenerateRecoveryCodes">Novos códigos de recuperação</Button>
                            <Button variant="ghost" size="sm" class="!text-red-600" @click="disableTwoFactor">Desativar</Button>
                        </div>
                    </div>

                    <div v-else-if="setup.secret" class="grid items-start gap-6 md:grid-cols-2">
                        <div class="text-center">
                            <div class="mx-auto w-fit rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                                <img v-if="setup.qr" :src="setup.qr" alt="QR code da verificação em duas etapas" class="h-44 w-44" />
                            </div>
                            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Ou digite a chave no aplicativo:</p>
                            <CopyField class="mt-2" :value="setup.secret" />
                        </div>
                        <form class="space-y-4" @submit.prevent="confirmTwoFactor">
                            <ol class="space-y-2 text-sm text-slate-600 dark:text-slate-400">
                                <li>1. Abra o Google Authenticator, Authy, 1Password ou similar.</li>
                                <li>2. Escaneie o QR code ao lado.</li>
                                <li>3. Digite o código de 6 dígitos que aparecer.</li>
                            </ol>
                            <TextInput v-model="setup.code" label="Código" placeholder="000 000" inputmode="numeric" autocomplete="one-time-code" maxlength="6" mono :error="setup.error" />
                            <div class="flex gap-2">
                                <Button type="submit" :loading="setup.loading">Confirmar e ativar</Button>
                                <Button variant="ghost" @click="setup.secret = ''">Cancelar</Button>
                            </div>
                        </form>
                    </div>

                    <div v-else class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"><PhShield :size="20" /></span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Desativada</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Recomendado: protege a conta mesmo se a senha vazar.</p>
                            </div>
                        </div>
                        <Button :icon="PhShieldCheck" @click="startTwoFactor">Ativar</Button>
                    </div>
                </Card>
            </section>

            <!-- Sessões -->
            <section class="grid gap-6 lg:grid-cols-3">
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">Sessões abertas</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Dispositivos com acesso ao painel. Encerre o que você não reconhecer.</p>
                </div>
                <Card class="lg:col-span-2">
                    <div v-if="sessionsLoading" class="space-y-3"><Skeleton height="2.75rem" /><Skeleton height="2.75rem" /></div>
                    <TransitionGroup v-else name="ui-list" tag="ul" class="relative divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="session in sessions" :key="session.id" class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    <component :is="deviceIcon(session.user_agent)" :size="20" />
                                </span>
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 truncate text-sm font-medium text-slate-900 dark:text-white">
                                        {{ describeAgent(session.user_agent) }}
                                        <Badge v-if="session.current" tone="success" dot>Esta sessão</Badge>
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ session.ip || 'IP desconhecido' }} · {{ format.relative(session.last_used_at || session.created_at) }}</p>
                                </div>
                            </div>
                            <Button v-if="!session.current" variant="ghost" size="sm" @click="revokeSession(session)">Encerrar</Button>
                        </li>
                    </TransitionGroup>
                    <template v-if="sessions.length > 1" #footer>
                        <div class="flex justify-end"><Button variant="outline" size="sm" :icon="PhSignOut" @click="revokeOthers">Encerrar todas as outras</Button></div>
                    </template>
                </Card>
            </section>
        </div>

        <!-- Códigos de recuperação -->
        <Modal v-model="codes.open" title="Guarde seus códigos de recuperação" description="Cada código vale uma vez, para entrar se você perder o celular. Eles não são mostrados de novo." :closable="codes.acknowledged">
            <div class="grid grid-cols-2 gap-2 rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                <code v-for="c in codes.list" :key="c" class="rounded-lg bg-white px-3 py-2 text-center font-mono text-sm tracking-wider text-slate-900 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-white dark:ring-slate-700">{{ c }}</code>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3">
                <Checkbox v-model="codes.acknowledged" label="Guardei os códigos" />
                <Button variant="outline" size="sm" :icon="PhCopy" @click="copyCodes">Copiar todos</Button>
            </div>
            <template #footer>
                <Button :disabled="!codes.acknowledged" @click="codes.open = false">Concluir</Button>
            </template>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import QRCode from 'qrcode';
import { PhShieldCheck, PhShield, PhKey, PhEnvelopeSimple, PhSignOut, PhCopy, PhDesktop, PhDeviceMobile } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { Alert, Badge, Button, Card, Checkbox, CopyField, Modal, PageHeader, Skeleton, TextInput, confirm, errorMessage, format, prompt, toast } from '@/ui';

const authStore = useAuthStore();
const user = computed(() => authStore.user as any);
const twoFactorEnabled = computed(() => !!user.value?.two_factor_enabled);
const twoFactorRequired = computed(() => !!user.value?.two_factor_required);

const password = reactive({ current_password: '', password: '', password_confirmation: '', loading: false, errors: {} as Record<string, string> });
const email = reactive({ email: '', password: '', loading: false, errors: {} as Record<string, string> });
const setup = reactive({ secret: '', qr: '', code: '', error: '', loading: false });
const codes = reactive({ open: false, list: [] as string[], acknowledged: false });
const sessions = ref<any[]>([]);
const sessionsLoading = ref(true);

onMounted(loadSessions);

function apiError(err: any, fallback: string): string {
    if (err?.response?.status === 429) return 'Muitas tentativas. Aguarde alguns minutos.';
    return err?.response?.data?.message || fallback;
}

function fieldErrors(err: any): Record<string, string> {
    const errors = err?.response?.data?.errors || {};
    return Object.fromEntries(Object.entries(errors).map(([k, v]: any) => [k, v?.[0] ?? '']));
}

async function changePassword() {
    password.loading = true;
    password.errors = {};
    try {
        const r = await axios.put('/dashboard/profile/password', {
            current_password: password.current_password,
            password: password.password,
            password_confirmation: password.password_confirmation,
        });
        password.current_password = password.password = password.password_confirmation = '';
        toast.success('Senha alterada', { description: r.data?.data?.message });
        loadSessions();
    } catch (err: any) {
        password.errors = fieldErrors(err);
        if (!Object.keys(password.errors).length) password.errors.current_password = apiError(err, 'Não foi possível alterar a senha.');
    } finally {
        password.loading = false;
    }
}

async function changeEmail() {
    email.loading = true;
    email.errors = {};
    try {
        const r = await axios.post('/dashboard/profile/email', { email: email.email, password: email.password });
        if (authStore.user) (authStore.user as any).pending_email = r.data?.data?.pending_email;
        email.email = email.password = '';
        toast.info('Confirme no novo e-mail', { description: r.data?.data?.message, duration: 6000 });
    } catch (err: any) {
        email.errors = fieldErrors(err);
        if (!Object.keys(email.errors).length) email.errors.email = apiError(err, 'Não foi possível pedir a troca.');
    } finally {
        email.loading = false;
    }
}

// Pede a senha num diálogo e roda a ação com ela, mostrando o erro no próprio
// diálogo (senha errada não fecha a janela).
function withPassword(title: string, description: string, action: (pwd: string) => Promise<void>, tone: 'default' | 'danger' = 'default') {
    return prompt({
        title,
        description,
        tone,
        confirmText: 'Continuar',
        input: { type: 'password', label: 'Sua senha', placeholder: '••••••••' },
        onConfirm: async (pwd) => {
            try {
                await action(String(pwd));
            } catch (err) {
                throw new Error(apiError(err, 'Não foi possível concluir.'));
            }
        },
    });
}

function startTwoFactor() {
    return withPassword('Confirme sua senha', 'Para ativar a verificação em duas etapas.', async (pwd) => {
        const r = await axios.post('/dashboard/two-factor', { password: pwd });
        setup.secret = r.data.data.secret;
        setup.qr = await QRCode.toDataURL(r.data.data.otpauth_uri, { width: 176, margin: 1 });
    });
}

function showRecoveryCodes(list: string[]) {
    Object.assign(codes, { open: true, list, acknowledged: false });
}

async function copyCodes() {
    try {
        await navigator.clipboard.writeText(codes.list.join('\n'));
        toast.success('Códigos copiados');
    } catch {
        toast.error('Não foi possível copiar');
    }
}

async function confirmTwoFactor() {
    setup.loading = true;
    setup.error = '';
    try {
        const r = await axios.post('/dashboard/two-factor/confirm', { code: setup.code.replace(/\s/g, '') });
        setup.secret = setup.qr = setup.code = '';
        await authStore.initializeAuth();
        toast.success('Verificação em duas etapas ativada');
        showRecoveryCodes(r.data.data.recovery_codes);
    } catch (err: any) {
        setup.error = apiError(err, 'Código inválido.');
    } finally {
        setup.loading = false;
    }
}

async function disableTwoFactor() {
    const done = await withPassword('Desativar a verificação em duas etapas?', 'A conta volta a pedir só a senha no login.', async (pwd) => {
        await axios.delete('/dashboard/two-factor', { data: { password: pwd } });
        await authStore.initializeAuth();
    }, 'danger');
    if (done !== null) toast.success('Verificação em duas etapas desativada');
}

async function regenerateRecoveryCodes() {
    let list: string[] = [];
    const done = await withPassword('Gerar novos códigos?', 'Os códigos atuais deixam de valer.', async (pwd) => {
        const r = await axios.post('/dashboard/two-factor/recovery-codes', { password: pwd });
        list = r.data.data.recovery_codes;
    });
    if (done !== null) showRecoveryCodes(list);
}

async function loadSessions() {
    sessionsLoading.value = true;
    try {
        const r = await axios.get('/dashboard/sessions');
        sessions.value = r.data?.data ?? [];
    } finally {
        sessionsLoading.value = false;
    }
}

async function revokeSession(session: any) {
    try {
        await axios.delete(`/dashboard/sessions/${session.id}`);
        toast.success('Sessão encerrada');
        loadSessions();
    } catch (err) {
        toast.error('Não foi possível encerrar', { description: errorMessage(err) });
    }
}

async function revokeOthers() {
    const ok = await confirm({
        title: 'Encerrar as outras sessões?',
        description: 'Os outros dispositivos precisarão entrar de novo.',
        tone: 'danger',
        confirmText: 'Encerrar',
        onConfirm: () => axios.delete('/dashboard/sessions').then(() => undefined),
    });
    if (!ok) return;
    toast.success('Outras sessões encerradas');
    loadSessions();
}

function deviceIcon(agent: string | null) {
    return agent && /Android|iPhone|iPad|Mobile/.test(agent) ? PhDeviceMobile : PhDesktop;
}

function describeAgent(agent: string | null): string {
    if (!agent) return 'Dispositivo desconhecido';
    const browser = /Edg\//.test(agent) ? 'Edge' : /Chrome\//.test(agent) ? 'Chrome' : /Firefox\//.test(agent) ? 'Firefox' : /Safari\//.test(agent) ? 'Safari' : 'Navegador';
    const os = /Windows/.test(agent) ? 'Windows' : /Android/.test(agent) ? 'Android' : /iPhone|iPad/.test(agent) ? 'iOS' : /Mac OS/.test(agent) ? 'macOS' : /Linux/.test(agent) ? 'Linux' : '';
    return os ? `${browser} · ${os}` : browser;
}
</script>
