<template>
    <div>
        <PageHeader title="Usuários" subtitle="Contas, assinaturas e papéis na equipe" :icon="PhUsersThree" />

        <Card>
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <TextInput v-model="search" class="sm:max-w-sm" placeholder="Buscar por nome ou e-mail…" :icon="PhMagnifyingGlass" />
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ total.toLocaleString('pt-BR') }} usuário(s)</p>
            </div>

            <DataTable :columns="columns" :rows="rows" :loading="loading" clickable empty-title="Nenhum usuário encontrado" :empty-icon="PhUsersThree" @row-click="(u) => router.push(`/dashboard/admin/users/${u.id}`)">
                <template #cell-name="{ row }">
                    <span class="flex items-center gap-3">
                        <Avatar :name="row.name" size="sm" />
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-slate-900 dark:text-white">{{ row.name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ row.email }}</span>
                        </span>
                    </span>
                </template>
                <template #cell-role="{ row }"><Badge :tone="roleLabels[row.role?.toLowerCase()] ? 'brand' : 'neutral'">{{ roleLabels[row.role?.toLowerCase()] ?? 'Cliente' }}</Badge></template>
                <template #cell-plan="{ row }">
                    <span v-if="row.active_plan" class="font-medium text-slate-900 dark:text-white">{{ row.active_plan.name }}</span>
                    <span v-else class="text-slate-400">Sem plano</span>
                    <span v-if="row.active_plan?.end_date" class="block text-xs text-slate-500">vence {{ format.date(row.active_plan.end_date) }}</span>
                </template>
                <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-slate-500">{{ format.date(row.created_at) }}</span></template>
                <template #cell-status="{ row }"><Badge :tone="isEnabled(row) ? 'success' : 'danger'" dot>{{ statusLabel(row.status) }}</Badge></template>
                <template #cell-actions="{ row }">
                    <span @click.stop>
                        <Dropdown :items="actions(row)" />
                    </span>
                </template>
            </DataTable>

            <Pagination :page="page" :last-page="totalPages" :total="total" label="usuários" @change="changePage" />
        </Card>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { PhUsersThree, PhMagnifyingGlass, PhIdentificationCard, PhGift, PhProhibit, PhCheckCircle } from '@phosphor-icons/vue';
import { useAdminStore } from '@/stores/admin';
import { useAuthStore } from '@/stores/auth';
import { Avatar, Badge, Card, DataTable, Dropdown, PageHeader, Pagination, TextInput, confirm, format, prompt, toast } from '@/ui';
import type { Column, DropdownItem } from '@/ui';

const router = useRouter();
const adminStore = useAdminStore();
const authStore = useAuthStore();

const roleLabels: Record<string, string> = { admin: 'Admin', finance: 'Financeiro', support: 'Suporte' };

const loading = computed(() => adminStore.loading);
const search = ref('');
const page = ref(1);

const rows = computed<any[]>(() => adminStore.users?.data ?? []);
const total = computed<number>(() => adminStore.users?.total ?? 0);
const totalPages = computed<number>(() => adminStore.users?.last_page ?? 1);

const columns: Column[] = [
    { key: 'name', label: 'Usuário' },
    { key: 'role', label: 'Papel' },
    { key: 'plan', label: 'Plano' },
    { key: 'created_at', label: 'Cadastro' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

function fetchUsers() {
    adminStore.fetchUsers({ search: search.value, page: page.value });
}

let debounce: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(() => {
        page.value = 1;
        fetchUsers();
    }, 350);
});

onMounted(fetchUsers);

function changePage(p: number) {
    page.value = p;
    fetchUsers();
}

function isEnabled(user: any) {
    return !user.status || user.status === 'enabled';
}

function statusLabel(status: string | null) {
    return ({ enabled: 'Ativo', disabled: 'Suspenso', blocked: 'Bloqueado' } as Record<string, string>)[status ?? 'enabled'] ?? status;
}

function actions(user: any): DropdownItem[] {
    const items: DropdownItem[] = [{ label: 'Abrir ficha', icon: PhIdentificationCard, to: `/dashboard/admin/users/${user.id}` }];
    if (authStore.can('manage')) {
        items.push({ label: 'Conceder plano', icon: PhGift, onClick: () => grantPlan(user) });
        if (user.id !== authStore.user?.id) {
            items.push({ divider: true });
            items.push(isEnabled(user)
                ? { label: 'Suspender conta', icon: PhProhibit, danger: true, onClick: () => toggleStatus(user) }
                : { label: 'Reativar conta', icon: PhCheckCircle, onClick: () => toggleStatus(user) });
        }
    }
    return items;
}

async function toggleStatus(user: any) {
    const suspending = isEnabled(user);
    const ok = await confirm({
        title: suspending ? `Suspender ${user.name}?` : `Reativar ${user.name}?`,
        description: suspending
            ? 'A pessoa perde o acesso ao painel na hora e a API key passa a ser recusada. A assinatura não é alterada.'
            : 'A pessoa volta a acessar o painel e a usar a mesma API key.',
        tone: suspending ? 'danger' : 'default',
        confirmText: suspending ? 'Suspender' : 'Reativar',
        onConfirm: async () => {
            await adminStore.updateUserStatus(user.id, suspending ? 'disabled' : 'enabled');
        },
    });
    if (ok) toast.success(suspending ? 'Conta suspensa' : 'Conta reativada');
}

async function grantPlan(user: any) {
    if (!adminStore.plans.length) await adminStore.fetchPlans();
    const options = Object.fromEntries((adminStore.plans as any[]).map((p: any) => [p.id, p.name]));

    const planId = await prompt({
        title: `Conceder plano a ${user.name}`,
        description: 'Sem cobrança. A assinatura em curso é encerrada e a nova começa hoje.',
        confirmText: 'Conceder',
        input: { type: 'select', label: 'Plano', options, value: Object.keys(options)[0] },
        onConfirm: async (id) => {
            await adminStore.grantPlan(user.id, String(id));
        },
    });
    if (planId === null) return;
    toast.success('Plano concedido');
    fetchUsers();
}
</script>
