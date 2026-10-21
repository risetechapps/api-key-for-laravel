<template>
    <div>
        <PageHeader title="Cupons" subtitle="Descontos percentuais ou de valor fixo, com limite de usos e validade" :icon="PhTag">
            <template #actions>
                <Tooltip text="Atualizar contagem de usos" position="bottom">
                    <Button variant="outline" square :icon="PhArrowsClockwise" :loading="loading" aria-label="Atualizar" @click="refresh" />
                </Tooltip>
                <Button :icon="PhPlus" @click="openForm()">Novo cupom</Button>
            </template>
        </PageHeader>

        <Card>
            <DataTable :columns="columns" :rows="coupons" :loading="loading" clickable :empty-icon="PhTag" @row-click="openForm">
                <template #empty>
                    <EmptyState title="Nenhum cupom ainda" description="Crie cupons para campanhas, parceiros ou retenção." :icon="PhTag">
                        <Button :icon="PhPlus" @click="openForm()">Novo cupom</Button>
                    </EmptyState>
                </template>
                <template #cell-code="{ row }"><code class="rounded-lg border border-dashed border-brand-300 bg-brand-50 px-2 py-1 text-xs font-bold tracking-wider text-brand-700 dark:border-brand-500/40 dark:bg-brand-500/10 dark:text-brand-300">{{ row.code }}</code></template>
                <template #cell-value="{ row }"><span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ row.type === 'percentage' ? `${row.value}%` : format.money(row.value) }}</span><span class="block text-xs text-slate-500">{{ row.type === 'percentage' ? 'percentual' : 'valor fixo' }}</span></template>
                <template #cell-usage="{ row }">
                    <div class="w-32">
                        <Progress v-if="row.usage?.max" :value="row.usage?.current ?? 0" :max="row.usage.max" size="sm" />
                        <span class="mt-1 block text-xs tabular-nums text-slate-500">{{ row.usage?.current ?? 0 }} de {{ row.usage?.max ?? '∞' }}</span>
                    </div>
                </template>
                <template #cell-expires_at="{ row }"><span class="whitespace-nowrap text-slate-500">{{ row.expires_at ? format.date(row.expires_at) : 'Sem validade' }}</span></template>
                <template #cell-status="{ row }"><Badge :tone="state(row).tone" dot>{{ state(row).label }}</Badge></template>
                <template #cell-actions="{ row }">
                    <span @click.stop><Dropdown :items="[{ label: 'Editar', icon: PhPencilSimple, onClick: () => openForm(row) }, { label: 'Copiar código', icon: PhCopy, onClick: () => copy(row.code) }, { divider: true }, { label: 'Excluir', icon: PhTrash, danger: true, onClick: () => remove(row) }]" /></span>
                </template>
            </DataTable>
        </Card>

        <Modal v-model="showForm" :title="editing ? `Editar ${editing.code}` : 'Novo cupom'" size="md">
            <form id="coupon-form" class="space-y-5" @submit.prevent="save">
                <TextInput v-model="form.code" label="Código" uppercase mono required :disabled="!!editing" :error="errors.code" :hint="editing ? 'O código não muda depois de criado.' : ''">
                    <template v-if="!editing" #suffix>
                        <button type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10" @click="generateCode">Gerar</button>
                    </template>
                </TextInput>

                <Field label="Tipo de desconto">
                    <Tabs v-model="form.type" variant="pills" :animate="false" :tabs="[{ key: 'percentage', label: 'Percentual', icon: PhPercent }, { key: 'fixed', label: 'Valor fixo', icon: PhCurrencyDollar }]" />
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextInput v-model="form.value" type="number" min="0" :step="form.type === 'percentage' ? '1' : '0.01'" :label="form.type === 'percentage' ? 'Desconto (%)' : 'Desconto (R$)'" required :error="errors.value" />
                    <TextInput v-model="form.max_uses" type="number" min="1" label="Limite de usos" placeholder="Ilimitado" :error="errors.max_uses" />
                    <TextInput v-model="form.expires_at" type="date" label="Validade" hint="Vazio: não expira." :error="errors.expires_at" />
                </div>

                <Switch v-model="form.is_active" label="Ativo" description="Desligado, o cupom é recusado no checkout." />
                <Alert v-if="formError" tone="danger">{{ formError }}</Alert>
            </form>
            <template #footer>
                <Button variant="ghost" @click="showForm = false">Cancelar</Button>
                <Button type="submit" form="coupon-form" :loading="saving">{{ editing ? 'Salvar alterações' : 'Criar cupom' }}</Button>
            </template>
        </Modal>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { PhPlus, PhPencilSimple, PhTrash, PhArrowsClockwise, PhTag, PhCopy, PhPercent, PhCurrencyDollar } from '@phosphor-icons/vue';
import { useAdminStore } from '@/stores/admin';
import { Alert, Badge, Button, Card, DataTable, Dropdown, EmptyState, Field, Modal, PageHeader, Progress, Switch, Tabs, TextInput, Tooltip, confirm, format, toast } from '@/ui';
import type { BadgeTone, Column } from '@/ui';

const adminStore = useAdminStore();
const loading = computed(() => adminStore.loading);
const coupons = computed<any[]>(() => adminStore.coupons as any[]);

const showForm = ref(false);
const editing = ref<any>(null);
const saving = ref(false);
const formError = ref('');
const errors = ref<Record<string, string>>({});

const columns: Column[] = [
    { key: 'code', label: 'Código' },
    { key: 'value', label: 'Desconto' },
    { key: 'usage', label: 'Usos' },
    { key: 'expires_at', label: 'Validade' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

const emptyForm = () => ({ code: '', type: 'percentage', value: 10 as number | string, max_uses: 1 as number | string | null, expires_at: '', is_active: true });
const form = ref(emptyForm());

onMounted(() => adminStore.fetchCoupons());

function refresh() {
    adminStore.fetchCoupons();
}

function state(c: any): { label: string; tone: BadgeTone } {
    if (!c.is_active) return { label: 'Inativo', tone: 'neutral' };
    if (c.expires_at && new Date(c.expires_at) < new Date()) return { label: 'Expirado', tone: 'warning' };
    if (c.usage?.max && (c.usage?.current ?? 0) >= c.usage.max) return { label: 'Esgotado', tone: 'warning' };
    return { label: 'Ativo', tone: 'success' };
}

function generateCode() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    form.value.code = Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

async function copy(code: string) {
    try {
        await navigator.clipboard.writeText(code);
        toast.success('Código copiado', { description: code });
    } catch {
        toast.error('Não foi possível copiar');
    }
}

function openForm(coupon: any = null) {
    editing.value = coupon;
    form.value = coupon
        ? { code: coupon.code, type: coupon.type, value: coupon.value, max_uses: coupon.usage?.max ?? '', expires_at: coupon.expires_at?.substring(0, 10) ?? '', is_active: !!coupon.is_active }
        : emptyForm();
    formError.value = '';
    errors.value = {};
    showForm.value = true;
}

async function save() {
    saving.value = true;
    formError.value = '';
    errors.value = {};
    try {
        const payload = { ...form.value, expires_at: form.value.expires_at || null, max_uses: form.value.max_uses === '' ? null : form.value.max_uses };
        if (editing.value) await adminStore.updateCoupon(editing.value.id, payload);
        else await adminStore.createCoupon(payload);
        showForm.value = false;
        toast.success(editing.value ? 'Cupom atualizado' : 'Cupom criado');
    } catch (err: any) {
        // A recusa por código duplicado chega como 422 com errors.code: sem ler
        // `errors`, o operador veria só "dados inválidos".
        const data = err?.response?.data;
        const fieldErrors = data?.errors ?? {};
        errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]: any) => [k, v?.[0] ?? '']));
        if (!Object.keys(errors.value).length) formError.value = data?.message || 'Erro ao salvar cupom.';
    } finally {
        saving.value = false;
    }
}

async function remove(coupon: any) {
    const ok = await confirm({
        title: `Excluir o cupom ${coupon.code}?`,
        description: 'Quem ainda não usou não consegue mais aplicar.',
        tone: 'danger',
        confirmText: 'Excluir cupom',
        onConfirm: async () => {
            await adminStore.deleteCoupon(coupon.id);
        },
    });
    if (ok) toast.success('Cupom excluído');
}
</script>
