<template>
    <div>
        <PageHeader title="Auditoria" subtitle="Quem fez o quê: ações da equipe e alterações de segurança das contas" :icon="PhListChecks" />

        <Card>
            <Tabs :model-value="action || 'all'" variant="pills" :animate="false" :tabs="filterTabs" label="Filtrar por tipo" class="mb-5" @update:model-value="(v) => setFilter(v === 'all' ? '' : v)" />

            <DataTable :columns="columns" :rows="rows" :loading="loading" clickable empty-title="Nenhum registro" :empty-icon="PhListChecks" @row-click="open">
                <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-slate-500">{{ format.date(row.created_at, true) }}</span></template>
                <template #cell-action="{ row }"><Badge :tone="tone(row.action)">{{ labelFor(row.action) }}</Badge></template>
                <template #cell-actor="{ row }">
                    <span v-if="row.actor" class="flex items-center gap-2"><Avatar :name="row.actor.name" size="xs" /><span class="truncate text-slate-900 dark:text-white">{{ row.actor.name }}</span></span>
                    <span v-else class="text-slate-400">sistema</span>
                </template>
                <template #cell-subject="{ row }">
                    <span v-if="row.subject" class="truncate text-slate-700 dark:text-slate-300">{{ row.subject.name }}</span>
                    <span v-else class="text-slate-400">—</span>
                </template>
                <template #cell-ip="{ row }"><span class="font-mono text-xs text-slate-500">{{ row.ip || '' }}</span></template>
            </DataTable>

            <Pagination :page="page" :last-page="lastPage" :total="total" label="registros" @change="go" />
        </Card>

        <Drawer v-model="detailOpen" :title="selected ? labelFor(selected.action) : ''" :description="selected ? format.date(selected.created_at, true) : ''">
            <div v-if="selected" class="space-y-6 text-sm">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-500">Quem</p>
                        <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ selected.actor?.name ?? 'sistema' }}</p>
                        <p class="break-all text-xs text-slate-500">{{ selected.actor?.email }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Conta afetada</p>
                        <router-link v-if="selected.subject" :to="`/dashboard/admin/users/${selected.subject.id}`" class="mt-1 block font-medium text-brand-600 hover:underline dark:text-brand-400">{{ selected.subject.name }}</router-link>
                        <p v-else class="mt-1 text-slate-400">—</p>
                        <p class="break-all text-xs text-slate-500">{{ selected.subject?.email }}</p>
                    </div>
                </div>
                <div v-if="selected.context && Object.keys(selected.context).length">
                    <p class="mb-2 text-xs text-slate-500">Detalhes</p>
                    <dl class="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                        <div v-for="(value, key) in selected.context" :key="key" class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-slate-500">{{ key }}</dt>
                            <dd class="break-all text-right font-mono text-xs text-slate-900 dark:text-white">{{ formatValue(value) }}</dd>
                        </div>
                    </dl>
                </div>
                <div v-if="selected.ip || selected.user_agent">
                    <p class="mb-1 text-xs text-slate-500">Origem</p>
                    <p class="font-mono text-xs text-slate-700 dark:text-slate-300">{{ selected.ip }}</p>
                    <p class="text-xs text-slate-500">{{ selected.user_agent }}</p>
                </div>
            </div>
        </Drawer>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { computed } from 'vue';
import { PhListChecks } from '@phosphor-icons/vue';
import { Avatar, Badge, Card, DataTable, Drawer, PageHeader, Pagination, Tabs, format } from '@/ui';
import type { BadgeTone, Column, TabItem } from '@/ui';

const rows = ref<any[]>([]);
const loading = ref(true);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const action = ref('');

const filters = [
    { value: '', label: 'Tudo' },
    { value: 'admin.', label: 'Administração' },
    { value: 'account.', label: 'Segurança da conta' },
    { value: 'api_key.', label: 'API keys' },
    { value: 'webhook.', label: 'Webhooks' },
    { value: 'billing.', label: 'Cobrança' },
];

const labels: Record<string, string> = {
    'admin.user_status_changed': 'Status do usuário alterado',
    'admin.plan_granted': 'Plano concedido',
    'admin.refund_processed': 'Estorno processado',
    'admin.payment_settings_updated': 'Configuração de pagamento alterada',
    'admin.subscription_cancelled': 'Renovação cancelada pela equipe',
    'admin.subscription_extended': 'Vencimento estendido',
    'admin.usage_adjusted': 'Consumo ajustado',
    'admin.renewal_retried': 'Nova tentativa de renovação',
    'admin.overage_waived': 'Excedente perdoado',
    'admin.verification_resent': 'Verificação de e-mail reenviada',
    'admin.sessions_revoked': 'Sessões encerradas pela equipe',
    'admin.api_keys_revoked': 'API keys desativadas pela equipe',
    'admin.two_factor_reset': '2FA desligada pela equipe',
    'admin.role_changed': 'Papel alterado',
    'admin.export_downloaded': 'Exportação baixada',
    'account.password_changed': 'Senha alterada',
    'account.email_change_requested': 'Troca de e-mail pedida',
    'account.email_changed': 'E-mail alterado',
    'account.two_factor_enabled': '2FA ativada',
    'account.two_factor_disabled': '2FA desativada',
    'account.two_factor_failed': 'Código 2FA errado',
    'account.login_two_factor': 'Login com 2FA',
    'account.deleted': 'Conta excluída',
    'account.data_exported': 'Dados exportados',
    'account.session_revoked': 'Sessão encerrada',
    'account.sessions_revoked': 'Outras sessões encerradas',
    'api_key.created': 'Chave criada',
    'api_key.revoked': 'Chave revogada',
    'api_key.regenerated': 'Chave regenerada',
    'billing.trial_started': 'Teste iniciado',
};

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const r = await axios.get('/dashboard/admin/audit', { params: { page: page.value, action: action.value || undefined } });
        const d = r.data?.data ?? {};
        rows.value = d.data ?? [];
        lastPage.value = d.last_page ?? 1;
        total.value = d.total ?? 0;
    } finally {
        loading.value = false;
    }
}

function setFilter(value: string) {
    action.value = value;
    page.value = 1;
    load();
}

function go(p: number) {
    page.value = p;
    load();
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'sim' : 'não';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function labelFor(a: string): string {
    return labels[a] ?? a;
}

const detailOpen = ref(false);
const selected = ref<any>(null);

const columns: Column[] = [
    { key: 'created_at', label: 'Quando' },
    { key: 'action', label: 'Ação' },
    { key: 'actor', label: 'Quem' },
    { key: 'subject', label: 'Conta afetada' },
    { key: 'ip', label: 'IP' },
];

const filterTabs = computed<TabItem[]>(() => filters.map((f: any) => ({ key: f.value || 'all', label: f.label })));

function open(row: any) {
    selected.value = row;
    detailOpen.value = true;
}

function tone(a: string): BadgeTone {
    if (a.startsWith('admin.')) return 'brand';
    if (/failed|deleted|revoked|reset|refund/.test(a)) return 'warning';
    return 'neutral';
}
</script>
