<template>
    <div>
        <PageHeader title="Equipe" subtitle="Pessoas com login próprio que trabalham na sua conta" :icon="PhUsersFour">
            <template #actions>
                <Button :icon="PhUserPlus" :disabled="!canInvite" @click="openInvite">Convidar</Button>
            </template>
        </PageHeader>

        <div v-if="loading" class="space-y-3"><Skeleton class="h-24" /><Skeleton class="h-40" /></div>

        <template v-else-if="team">
            <Alert v-if="team.limit === 0" tone="info" title="Seu plano não inclui equipe" class="mb-5">
                Para ter pessoas com login próprio na sua conta, escolha um plano com membros na equipe.
                <template v-if="auth.teamCan('owner')"> <RouterLink to="/dashboard/plans" class="font-medium underline">Ver planos</RouterLink></template>
            </Alert>
            <Alert v-else-if="team.used >= team.limit" tone="warning" class="mb-5">
                Você usou os {{ team.limit }} lugares da equipe do seu plano (convites pendentes contam). Para convidar mais, remova alguém ou mude de plano.
            </Alert>

            <Card class="mb-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">Lugares na equipe</p>
                        <p class="text-xs text-slate-500">Além de você. Convites pendentes contam.</p>
                    </div>
                    <p class="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ team.used }}<span class="text-base font-normal text-slate-400"> / {{ team.limit }}</span></p>
                </div>
                <Progress v-if="team.limit" class="mt-3" :value="Math.min(100, (team.used / team.limit) * 100)" />
            </Card>

            <Card title="Membros">
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="member in people" :key="member.id" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">{{ initials(member.name) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-900 dark:text-white">
                                {{ member.name }}
                                <span v-if="member.id === team.me.id" class="text-xs font-normal text-slate-400">(você)</span>
                            </p>
                            <p v-if="member.external_id" class="truncate text-xs text-slate-500" title="Criado pela integração: usa o sistema da empresa, sem login aqui.">Integração · id <span class="font-mono">{{ member.external_id }}</span></p>
                            <p v-else class="truncate text-xs text-slate-500">{{ member.email }}<template v-if="member.two_factor_enabled"> · 2FA ativa</template></p>
                        </div>
                        <div v-if="member.role !== 'owner' && canManage(member.role)" class="flex shrink-0 items-center gap-1">
                            <div class="w-40"><Select :model-value="member.role" :options="assignableRoles" @update:model-value="(role: string) => changeRole(member, role)" /></div>
                            <Button size="sm" variant="ghost" square :icon="PhTrash" aria-label="Remover da equipe" @click="remove(member)" />
                        </div>
                        <Badge v-else :tone="ROLE_TONES[member.role]">{{ ROLES[member.role] }}</Badge>
                    </li>
                </ul>
            </Card>

            <Card v-if="team.invitations.length" title="Convites pendentes" class="mt-5">
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="invitation in team.invitations" :key="invitation.id" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-dashed border-slate-300 text-slate-400 dark:border-slate-600"><PhEnvelopeSimple :size="18" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-900 dark:text-white">{{ invitation.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ invitation.email }} · expira {{ format.relative(invitation.expires_at) }}</p>
                        </div>
                        <Badge :tone="ROLE_TONES[invitation.role]">{{ ROLES[invitation.role] }}</Badge>
                        <template v-if="canManage(invitation.role)">
                            <Button size="sm" variant="ghost" :icon="PhArrowClockwise" @click="resend(invitation)">Reenviar</Button>
                            <Button size="sm" variant="ghost" square :icon="PhX" aria-label="Cancelar convite" @click="cancel(invitation)" />
                        </template>
                    </li>
                </ul>
            </Card>

            <Card title="O que cada papel pode fazer" class="mt-5">
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="font-medium text-slate-900 dark:text-white">Dono</dt><dd class="mt-1 text-slate-500">Tudo, inclusive plano, cobrança e excluir a conta.</dd></div>
                    <div><dt class="font-medium text-slate-900 dark:text-white">Administrador</dt><dd class="mt-1 text-slate-500">Tudo menos plano e cobrança. Convida e gerencia atendentes.</dd></div>
                    <div><dt class="font-medium text-slate-900 dark:text-white">Atendente</dt><dd class="mt-1 text-slate-500">Só as telas de atendimento liberadas pelo sistema.</dd></div>
                </dl>
            </Card>
        </template>

        <Modal v-model="showInvite" title="Convidar para a equipe" description="A pessoa recebe um e-mail para criar a senha. O convite vale por 7 dias." size="sm">
            <form id="invite-form" class="space-y-4" @submit.prevent="invite">
                <TextInput v-model="form.name" label="Nome" required :error="errors.name" />
                <TextInput v-model="form.email" type="email" label="E-mail" required :error="errors.email" />
                <Select v-model="form.role" :options="assignableRoles" label="Papel" :error="errors.role" />
            </form>
            <template #footer>
                <Button variant="ghost" @click="showInvite = false">Cancelar</Button>
                <Button type="submit" form="invite-form" :loading="saving">Enviar convite</Button>
            </template>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { PhArrowClockwise, PhEnvelopeSimple, PhTrash, PhUserPlus, PhUsersFour, PhX } from '@phosphor-icons/vue';
import { Alert, Badge, Button, Card, Modal, PageHeader, Progress, Select, Skeleton, TextInput, confirm, errorMessage, format, toast } from '@/ui';
import type { BadgeTone } from '@/ui';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

const ROLES: Record<string, string> = { owner: 'Dono', admin: 'Administrador', agent: 'Atendente' };
const ROLE_TONES: Record<string, BadgeTone> = { owner: 'brand', admin: 'info', agent: 'neutral' };

const team = ref<any | null>(null);
const loading = ref(true);
const people = computed(() => (team.value ? [team.value.owner, ...team.value.members] : []));
const canInvite = computed(() => !!team.value && team.value.used < team.value.limit);

/** Admin convida e gerencia atendentes; admins, só o dono (a regra vale no servidor). */
const assignableRoles = computed(() => (auth.teamCan('owner') ? { agent: 'Atendente', admin: 'Administrador' } : { agent: 'Atendente' }));
const canManage = (role: string) => auth.teamCan('owner') || role === 'agent';

function initials(name: string) {
    return (name || '?').split(/\s+/).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

async function load() {
    try {
        const { data } = await axios.get('/dashboard/team');
        team.value = data.data;
    } catch (e) {
        toast.error(errorMessage(e, 'Não foi possível carregar a equipe.'));
    } finally {
        loading.value = false;
    }
}

const showInvite = ref(false);
const saving = ref(false);
const form = reactive({ name: '', email: '', role: 'agent' });
const errors = reactive({ name: '', email: '', role: '' });

function openInvite() {
    Object.assign(form, { name: '', email: '', role: 'agent' });
    Object.assign(errors, { name: '', email: '', role: '' });
    showInvite.value = true;
}

async function invite() {
    saving.value = true;
    Object.assign(errors, { name: '', email: '', role: '' });
    try {
        await axios.post('/dashboard/team/invitations', form);
        toast.success(`Convite enviado para ${form.email}.`);
        showInvite.value = false;
        load();
    } catch (e: any) {
        const bag = e?.response?.data?.errors;
        if (bag) Object.assign(errors, { name: bag.name?.[0] || '', email: bag.email?.[0] || '', role: bag.role?.[0] || '' });
        else toast.error(errorMessage(e));
    } finally {
        saving.value = false;
    }
}

async function changeRole(member: any, role: string) {
    if (role === member.role) return;
    try {
        await axios.put(`/dashboard/team/members/${member.id}`, { role });
        toast.success(`${member.name} agora é ${ROLES[role].toLowerCase()}.`);
        load();
    } catch (e: any) {
        toast.error(e?.response?.data?.errors?.role?.[0] || errorMessage(e));
        load();
    }
}

async function remove(member: any) {
    const ok = await confirm({
        title: `Remover ${member.name} da equipe?`,
        description: 'O login da pessoa deixa de funcionar na hora. O histórico do que ela fez continua na conta.',
        tone: 'danger',
        confirmText: 'Remover',
    });
    if (!ok) return;
    try {
        await axios.delete(`/dashboard/team/members/${member.id}`);
        toast.success('Removido da equipe.');
        load();
    } catch (e: any) {
        toast.error(e?.response?.data?.errors?.role?.[0] || errorMessage(e));
    }
}

async function resend(invitation: any) {
    try {
        await axios.post(`/dashboard/team/invitations/${invitation.id}/resend`);
        toast.success(`Convite reenviado para ${invitation.email}.`);
        load();
    } catch (e) {
        toast.error(errorMessage(e));
    }
}

async function cancel(invitation: any) {
    const ok = await confirm({ title: `Cancelar o convite de ${invitation.name}?`, confirmText: 'Cancelar convite', cancelText: 'Voltar' });
    if (!ok) return;
    try {
        await axios.delete(`/dashboard/team/invitations/${invitation.id}`);
        toast.success('Convite cancelado.');
        load();
    } catch (e) {
        toast.error(errorMessage(e));
    }
}

onMounted(load);
</script>
