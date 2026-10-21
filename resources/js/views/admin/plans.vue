<template>
    <div>
        <PageHeader title="Planos" subtitle="Preço, limites, excedente, período de teste e features de cada plano" :icon="PhCreditCard">
            <template #actions>
                <Button :icon="PhPlus" @click="openForm()">Novo plano</Button>
            </template>
        </PageHeader>

        <Card>
            <DataTable :columns="columns" :rows="plans" :loading="loading" clickable empty-title="Nenhum plano cadastrado" empty-text="Crie o primeiro plano para começar a vender." :empty-icon="PhCreditCard" @row-click="openForm">
                <template #empty>
                    <EmptyState title="Nenhum plano cadastrado" description="Crie o primeiro plano para começar a vender." :icon="PhCreditCard">
                        <Button :icon="PhPlus" @click="openForm()">Novo plano</Button>
                    </EmptyState>
                </template>
                <template #cell-name="{ row }">
                    <span class="font-semibold text-slate-900 dark:text-white">{{ row.name }}</span>
                    <span v-if="row.description" class="block max-w-xs truncate text-xs text-slate-500">{{ row.description }}</span>
                </template>
                <template #cell-price="{ row }"><span class="font-semibold tabular-nums">{{ format.money(row.raw_price ?? row.price) }}</span><span class="text-xs text-slate-500"> /{{ cycleShort(row.billing_cycle) }}</span></template>
                <template #cell-request_limit="{ row }"><span class="tabular-nums">{{ Number(row.request_limit).toLocaleString('pt-BR') }}</span></template>
                <template #cell-extras="{ row }">
                    <span class="flex flex-wrap gap-1">
                        <Badge v-if="row.trial_days > 0" tone="brand">{{ row.trial_days }}d teste</Badge>
                        <Badge v-if="row.overage_price" tone="info">excedente</Badge>
                        <Badge v-if="row.rate_limit_per_minute" tone="neutral">{{ row.rate_limit_per_minute }}/min</Badge>
                        <Badge v-if="row.team_members_limit" tone="neutral">{{ row.team_members_limit }} na equipe</Badge>
                        <Badge tone="neutral">{{ row.features?.length ?? 0 }} features</Badge>
                    </span>
                </template>
                <template #cell-status="{ row }"><Badge :tone="row.is_active ? 'success' : 'neutral'" dot>{{ row.is_active ? 'À venda' : 'Inativo' }}</Badge></template>
                <template #cell-actions="{ row }">
                    <span @click.stop><Dropdown :items="[{ label: 'Editar', icon: PhPencilSimple, onClick: () => openForm(row) }, { divider: true }, { label: 'Excluir', icon: PhTrash, danger: true, onClick: () => remove(row) }]" /></span>
                </template>
            </DataTable>
        </Card>

        <Drawer v-model="showForm" :title="editing ? `Editar ${editing.name}` : 'Novo plano'" size="lg">
            <form id="plan-form" class="space-y-8" @submit.prevent="save">
                <section class="space-y-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Identificação</h3>
                    <TextInput v-model="form.name" label="Nome" required :error="errors.name" />
                    <Textarea v-model="form.description" label="Descrição" :rows="2" :error="errors.description" />
                    <Switch v-model="form.is_active" label="À venda" description="Desligado, o plano some da página de planos. Quem já assina continua." />
                </section>

                <section class="space-y-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Preço e limites</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextInput v-model="form.price" type="number" step="0.01" min="0" label="Preço (R$)" required :error="errors.price" />
                        <Select v-model="form.billing_cycle" label="Ciclo de cobrança" :options="{ monthly: 'Mensal', annually: 'Anual', weekly: 'Semanal' }" />
                        <TextInput v-model="form.request_limit" type="number" min="0" label="Requisições por período" required :error="errors.request_limit" />
                        <TextInput v-model="form.rate_limit_per_minute" type="number" min="1" label="Requisições por minuto" placeholder="Sem limite" :error="errors.rate_limit_per_minute" />
                        <TextInput v-model="form.team_members_limit" type="number" min="0" label="Membros na equipe" hint="Além do dono. 0 = só o dono." :error="errors.team_members_limit" />
                        <TextInput v-model="form.trial_days" type="number" min="0" max="365" label="Dias de teste grátis" hint="0: sem teste" :error="errors.trial_days" />
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Excedente</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Com preço por requisição, o assinante pode ligar o excedente em Faturamento e continuar usando depois do limite. Sem preço, a API bloqueia no limite.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextInput v-model="form.overage_price" type="number" min="0" step="0.0001" label="Preço por requisição (R$)" placeholder="Sem excedente" :error="errors.overage_price" />
                        <TextInput v-model="form.overage_max_requests" type="number" min="1" label="Teto de requisições extras" placeholder="Sem teto" :disabled="form.overage_price === '' || form.overage_price === null" :error="errors.overage_max_requests" />
                    </div>
                </section>

                <section class="space-y-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Features <span class="text-red-500">*</span></h3>
                    <Alert v-if="!availableFeatures.length" tone="info">Nenhuma feature registrada. Use <code>FeatureRegistry::register()</code> no seu AppServiceProvider.</Alert>
                    <div v-else class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="f in availableFeatures"
                            :key="f.key"
                            type="button"
                            :aria-pressed="form.features.includes(f.key)"
                            :class="['flex items-start gap-3 rounded-xl border p-3 text-left transition-colors', form.features.includes(f.key) ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700']"
                            @click="toggleFeature(f.key)"
                        >
                            <span :class="['mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border', form.features.includes(f.key) ? 'border-brand-600 bg-brand-600' : 'border-slate-300 dark:border-slate-600']">
                                <PhCheck v-if="form.features.includes(f.key)" :size="10" weight="bold" class="text-white" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-slate-900 dark:text-white">{{ f.name }}</span>
                                <span v-if="f.description" class="block text-xs text-slate-500">{{ f.description }}</span>
                            </span>
                        </button>
                    </div>
                    <p v-if="errors.features" class="text-xs font-medium text-red-600">{{ errors.features }}</p>
                </section>

                <Alert v-if="formError" tone="danger">{{ formError }}</Alert>
            </form>
            <template #footer>
                <Button variant="ghost" @click="showForm = false">Cancelar</Button>
                <Button type="submit" form="plan-form" :loading="saving">{{ editing ? 'Salvar alterações' : 'Criar plano' }}</Button>
            </template>
        </Drawer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { PhPlus, PhPencilSimple, PhTrash, PhCreditCard, PhCheck } from '@phosphor-icons/vue';
import { useAdminStore } from '@/stores/admin';
import { Alert, Badge, Button, Card, DataTable, Drawer, Dropdown, EmptyState, PageHeader, Select, Switch, TextInput, Textarea, confirm, format, toast } from '@/ui';
import type { Column } from '@/ui';

const adminStore = useAdminStore();
const loading = computed(() => adminStore.loading);
const plans = computed<any[]>(() => adminStore.plans as any[]);
const availableFeatures = computed<any[]>(() => adminStore.features as any[]);

const showForm = ref(false);
const editing = ref<any>(null);
const saving = ref(false);
const formError = ref('');
const errors = ref<Record<string, string>>({});

const columns: Column[] = [
    { key: 'name', label: 'Plano', sortable: true },
    { key: 'price', label: 'Preço', align: 'right' },
    { key: 'request_limit', label: 'Limite', align: 'right', sortable: true },
    { key: 'extras', label: 'Recursos' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

const emptyForm = () => ({
    name: '', description: '', price: 0 as number | string, billing_cycle: 'monthly', request_limit: 1000 as number | string,
    is_active: true, features: [] as string[], trial_days: 0 as number | string, rate_limit_per_minute: '' as number | string, team_members_limit: 0 as number | string,
    overage_price: '' as number | string | null, overage_max_requests: '' as number | string,
});

const form = ref(emptyForm());

onMounted(() => {
    adminStore.fetchPlans();
    adminStore.fetchFeatures();
});

function cycleShort(c: string): string {
    return ({ monthly: 'mês', annually: 'ano', yearly: 'ano', weekly: 'semana' } as Record<string, string>)[c] ?? c;
}

function toggleFeature(key: string) {
    form.value.features = form.value.features.includes(key) ? form.value.features.filter((k) => k !== key) : [...form.value.features, key];
}

function openForm(plan: any = null) {
    editing.value = plan;
    form.value = plan
        ? {
            name: plan.name,
            description: plan.description ?? '',
            price: plan.raw_price ?? 0,
            billing_cycle: plan.billing_cycle ?? 'monthly',
            request_limit: plan.request_limit ?? 0,
            is_active: !!plan.is_active,
            trial_days: plan.trial_days ?? 0,
            rate_limit_per_minute: plan.rate_limit_per_minute ?? '',
            team_members_limit: plan.team_members_limit ?? 0,
            overage_price: plan.overage_price ?? '',
            overage_max_requests: plan.overage_max_requests ?? '',
            features: Array.isArray(plan.features) ? plan.features.map((f: any) => (f && typeof f === 'object' ? f.key : f)).filter(Boolean) : [],
        }
        : emptyForm();
    formError.value = '';
    errors.value = {};
    showForm.value = true;
}

async function save() {
    errors.value = {};
    formError.value = '';
    if (availableFeatures.value.length && !form.value.features.length) {
        errors.value.features = 'Selecione ao menos uma feature.';
        return;
    }

    saving.value = true;
    try {
        // Campo numérico vazio vai como null: "sem limite", "sem excedente".
        const blankToNull = (v: any) => (v === '' || v === undefined ? null : v);
        const payload = {
            ...form.value,
            trial_days: Number(form.value.trial_days || 0),
            rate_limit_per_minute: blankToNull(form.value.rate_limit_per_minute),
            team_members_limit: Number(form.value.team_members_limit) || 0,
            overage_price: blankToNull(form.value.overage_price),
            overage_max_requests: blankToNull(form.value.overage_price) === null ? null : blankToNull(form.value.overage_max_requests),
        };
        if (editing.value) await adminStore.updatePlan(editing.value.id, payload);
        else await adminStore.createPlan(payload);
        showForm.value = false;
        toast.success(editing.value ? 'Plano atualizado' : 'Plano criado');
    } catch (err: any) {
        const data = err?.response?.data;
        const fieldErrors = data?.errors ?? {};
        errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]: any) => [k.split('.')[0], v?.[0] ?? '']));
        if (!Object.keys(errors.value).length) formError.value = data?.message || 'Erro ao salvar plano.';
    } finally {
        saving.value = false;
    }
}

async function remove(plan: any) {
    const ok = await confirm({
        title: `Excluir o plano ${plan.name}?`,
        description: 'O plano é excluído permanentemente. Para só tirar de venda, desligue "À venda".',
        tone: 'danger',
        confirmText: 'Excluir plano',
        onConfirm: async () => {
            await adminStore.deletePlan(plan.id);
        },
    });
    if (ok) toast.success('Plano excluído');
}
</script>
