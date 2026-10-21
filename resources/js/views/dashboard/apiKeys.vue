<template>
    <div>
        <PageHeader title="API Keys" subtitle="Uma chave por sistema ou ambiente: dá para trocar ou revogar uma sem derrubar as outras." :icon="PhKey">
            <template #actions>
                <Button :icon="PhPlus" @click="openForm()">Nova chave</Button>
            </template>
        </PageHeader>

        <div v-if="loading" class="grid gap-4 lg:grid-cols-2">
            <Card v-for="i in 2" :key="i"><Skeleton :lines="4" /></Card>
        </div>

        <TransitionGroup v-else-if="keys.length" name="ui-list" tag="div" class="relative grid gap-4 lg:grid-cols-2">
            <Card v-for="key in keys" :key="key.id" interactive>
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl', key.active ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300' : 'bg-slate-100 text-slate-400 dark:bg-slate-800']">
                            <PhKey :size="20" weight="duotone" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-slate-900 dark:text-white">{{ key.name || 'Sem nome' }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Criada {{ format.relative(key.created_at) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <Badge :tone="key.active ? 'success' : 'neutral'" dot :pulse="key.active">{{ key.active ? 'Ativa' : 'Inativa' }}</Badge>
                        <Dropdown :items="actions(key)" />
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Último uso</dt>
                        <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ key.last_used_at ? format.relative(key.last_used_at) : 'Nunca' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Expira</dt>
                        <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ key.expires_at ? format.date(key.expires_at) : 'Não expira' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Limite</dt>
                        <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ key.rate_limit_per_minute ? `${key.rate_limit_per_minute}/min` : 'O do plano' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Origens</dt>
                        <dd class="mt-0.5 truncate font-medium text-slate-900 dark:text-white">{{ key.allowed_origins?.length ? key.allowed_origins.join(', ') : 'Qualquer' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-1.5 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <template v-if="key.scopes?.length">
                        <Badge v-for="s in key.scopes" :key="s" tone="brand">{{ featureName(s) }}</Badge>
                    </template>
                    <span v-else class="text-xs text-slate-500 dark:text-slate-400">Todas as permissões do plano</span>
                </div>

                <p v-if="!key.active" class="mt-3 text-xs text-amber-600 dark:text-amber-400">Inativa porque a conta está sem plano ativo.</p>
            </Card>
        </TransitionGroup>

        <Card v-else>
            <EmptyState title="Nenhuma API key ainda" description="Crie uma chave para autenticar as chamadas da sua aplicação." :icon="PhKey">
                <Button :icon="PhPlus" @click="openForm()">Criar primeira chave</Button>
            </EmptyState>
        </Card>

        <!-- Formulário -->
        <Modal v-model="form.open" :title="form.id ? 'Editar chave' : 'Nova chave'" description="Restrinja o que a chave pode fazer e de onde ela pode ser usada." size="lg">
            <form id="key-form" class="space-y-5" @submit.prevent="save">
                <TextInput v-model="form.name" label="Nome" placeholder="Ex.: Produção, Homologação, App mobile" :error="form.errors.name" required />

                <Field label="Permissões" :error="form.errors.scopes">
                    <div class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <Switch v-model="form.allFeatures" label="Todas as features do plano" description="Desligue para escolher quais features esta chave pode usar." />
                        <div v-if="!form.allFeatures" class="grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2 dark:border-slate-800">
                            <Checkbox v-for="f in features" :key="f.key" :model-value="form.scopes.includes(f.key)" :label="f.name || f.key" @update:model-value="(v) => toggleScope(f.key, v)" />
                            <p v-if="!features.length" class="text-xs text-slate-500">Nenhuma feature registrada.</p>
                        </div>
                    </div>
                </Field>

                <TextInput v-model="form.origins" label="Origens permitidas" placeholder="app.exemplo.com, 203.0.113.10" hint="Separe por vírgula. Vazio: qualquer origem." :error="form.errors.allowed_origins" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextInput v-model="form.rate" type="number" min="1" label="Limite por minuto" hint="Vazio: o do plano." :error="form.errors.rate_limit_per_minute" />
                    <TextInput v-model="form.expires_at" type="date" label="Expira em" hint="Opcional." :error="form.errors.expires_at" />
                </div>
            </form>
            <template #footer>
                <Button variant="ghost" @click="form.open = false">Cancelar</Button>
                <Button type="submit" form="key-form" :loading="form.loading">{{ form.id ? 'Salvar alterações' : 'Criar chave' }}</Button>
            </template>
        </Modal>

        <!-- Chave nova: mostrada uma vez -->
        <Modal v-model="secret.open" :title="secret.title" size="md" :closable="secret.acknowledged">
            <div class="space-y-4">
                <Alert tone="warning" title="Copie agora">A chave não é mostrada de novo. Guarde num cofre de segredos ou na variável de ambiente da sua aplicação.</Alert>
                <CopyField :value="secret.value" />
                <Checkbox v-model="secret.acknowledged" label="Já copiei e guardei a chave" />
            </div>
            <template #footer>
                <Button :disabled="!secret.acknowledged" @click="secret.open = false">Concluir</Button>
            </template>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { PhKey, PhPlus, PhPencilSimple, PhArrowsClockwise, PhTrash } from '@phosphor-icons/vue';
import { Alert, Badge, Button, Card, Checkbox, CopyField, Dropdown, EmptyState, Field, Modal, PageHeader, Skeleton, Switch, TextInput, confirm, errorMessage, format, toast } from '@/ui';
import type { DropdownItem } from '@/ui';

const keys = ref<any[]>([]);
const features = ref<any[]>([]);
const loading = ref(true);

const form = reactive({
    open: false,
    id: null as string | null,
    name: '',
    allFeatures: true,
    scopes: [] as string[],
    origins: '',
    rate: '' as string | number,
    expires_at: '',
    loading: false,
    errors: {} as Record<string, string>,
});

const secret = reactive({ open: false, title: '', value: '', acknowledged: false });

onMounted(async () => {
    await load();
    try {
        const r = await axios.get('/dashboard/plans');
        const all = (r.data?.data ?? []).flatMap((p: any) => p.features ?? []);
        features.value = Object.values(Object.fromEntries(all.map((f: any) => [f.key, f])));
    } catch {
        features.value = [];
    }
});

async function load() {
    try {
        const r = await axios.get('/dashboard/api-keys');
        keys.value = r.data?.data ?? [];
    } catch (err) {
        toast.error('Não foi possível carregar as chaves', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
}

function featureName(key: string): string {
    return features.value.find((f) => f.key === key)?.name ?? key;
}

function toggleScope(key: string, on: boolean) {
    form.scopes = on ? [...new Set([...form.scopes, key])] : form.scopes.filter((s) => s !== key);
}

function actions(key: any): DropdownItem[] {
    return [
        { label: 'Editar', icon: PhPencilSimple, onClick: () => openForm(key) },
        { label: 'Regenerar chave', icon: PhArrowsClockwise, onClick: () => regenerate(key) },
        ...(keys.value.length > 1 ? [{ divider: true }, { label: 'Revogar', icon: PhTrash, danger: true, onClick: () => revoke(key) }] : []),
    ];
}

function openForm(key: any = null) {
    Object.assign(form, {
        open: true,
        id: key?.id ?? null,
        name: key?.name ?? '',
        allFeatures: !key?.scopes,
        scopes: key?.scopes ?? [],
        origins: (key?.allowed_origins ?? []).join(', '),
        rate: key?.rate_limit_per_minute ?? '',
        expires_at: key?.expires_at ? key.expires_at.slice(0, 10) : '',
        errors: {},
    });
}

function showSecret(title: string, value: string) {
    Object.assign(secret, { open: true, title, value, acknowledged: false });
}

async function save() {
    form.loading = true;
    form.errors = {};

    const payload = {
        name: form.name,
        scopes: form.allFeatures ? null : form.scopes,
        allowed_origins: form.origins.split(',').map((o) => o.trim()).filter(Boolean),
        rate_limit_per_minute: form.rate === '' ? null : Number(form.rate),
        expires_at: form.expires_at || null,
    };

    try {
        if (form.id) {
            await axios.put(`/dashboard/api-keys/${form.id}`, payload);
            toast.success('Chave atualizada');
        } else {
            const r = await axios.post('/dashboard/api-keys', payload);
            showSecret('Chave criada', r.data.data.key);
        }
        form.open = false;
        await load();
    } catch (err: any) {
        const errors = err?.response?.data?.errors || {};
        form.errors = Object.fromEntries(Object.entries(errors).map(([k, v]: any) => [k.split('.')[0], v?.[0] ?? '']));
        if (!Object.keys(form.errors).length) toast.error('Não foi possível salvar', { description: errorMessage(err) });
    } finally {
        form.loading = false;
    }
}

async function regenerate(key: any) {
    let value = '';
    const ok = await confirm({
        title: `Regenerar "${key.name || 'Sem nome'}"?`,
        description: 'A chave atual para de funcionar imediatamente. Atualize a aplicação que a usa logo depois.',
        tone: 'danger',
        confirmText: 'Regenerar',
        onConfirm: async () => {
            const r = await axios.post(`/dashboard/api-keys/${key.id}/regenerate`);
            value = r.data.data.key;
        },
    });
    if (!ok) return;
    showSecret('Nova chave gerada', value);
    await load();
}

async function revoke(key: any) {
    const ok = await confirm({
        title: `Revogar "${key.name || 'Sem nome'}"?`,
        description: 'A chave deixa de funcionar e não pode ser recuperada.',
        tone: 'danger',
        confirmText: 'Revogar chave',
        onConfirm: () => axios.delete(`/dashboard/api-keys/${key.id}`).then(() => undefined),
    });
    if (!ok) return;
    toast.success('Chave revogada');
    await load();
}
</script>
