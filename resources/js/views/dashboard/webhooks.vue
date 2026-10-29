<template>
    <div>
        <PageHeader title="Webhooks" subtitle="Avisos enviados ao seu sistema quando algo acontece na conta: cota perto do fim, plano vencido, pagamento recusado." :icon="PhPlugsConnected">
            <template #actions>
                <Button :icon="PhPlus" @click="openForm()">Novo webhook</Button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <template v-if="loading">
                    <Card v-for="i in 2" :key="i"><Skeleton :lines="3" /></Card>
                </template>

                <TransitionGroup v-else-if="endpoints.length" name="ui-list" tag="div" class="relative space-y-4">
                    <Card v-for="hook in endpoints" :key="hook.id">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span :class="['mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', hook.active ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-red-50 text-red-500 dark:bg-red-500/15 dark:text-red-300']">
                                    <PhBroadcast :size="20" weight="duotone" />
                                </span>
                                <div class="min-w-0">
                                    <p class="break-all font-mono text-sm font-medium text-slate-900 dark:text-white">{{ hook.url }}</p>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <Badge :tone="hook.active ? 'success' : 'danger'" dot>{{ hook.active ? 'Ativo' : hook.disabled_at ? 'Desativado após falhas' : 'Desativado' }}</Badge>
                                        <template v-if="hook.events?.length">
                                            <Badge v-for="e in hook.events" :key="e" tone="neutral" :title="describe(e)">{{ e }}</Badge>
                                        </template>
                                        <Badge v-else tone="brand">Todos os eventos</Badge>
                                    </div>
                                </div>
                            </div>
                            <Dropdown :items="actions(hook)" />
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                            <Button variant="soft" size="sm" :icon="PhListMagnifyingGlass" @click="openDeliveries(hook)">Entregas</Button>
                            <Button variant="ghost" size="sm" :icon="PhPaperPlaneTilt" :loading="testing === hook.id" @click="sendTest(hook)">Enviar teste</Button>
                        </div>
                    </Card>
                </TransitionGroup>

                <Card v-else>
                    <EmptyState title="Nenhum webhook cadastrado" description="Cadastre uma URL HTTPS do seu sistema para receber os eventos da conta." :icon="PhPlugsConnected">
                        <Button :icon="PhPlus" @click="openForm()">Cadastrar webhook</Button>
                    </EmptyState>
                </Card>
            </div>

            <Card title="Verificando a assinatura" subtitle="Cada entrega é assinada com o segredo do webhook" class="h-fit lg:sticky lg:top-24">
                <!-- O texto fica num <span>: no <li> flex, cada trecho e cada <code> virava
                     um item separado, com gap entre eles. -->
                <ol class="space-y-3 text-sm text-slate-600 dark:text-slate-400">
                    <li class="flex gap-3"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">1</span><span class="min-w-0 leading-relaxed">Junte o header <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">X-Webhook-Timestamp</code>, um ponto e o corpo cru, exatamente como chegou.</span></li>
                    <li class="flex gap-3"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">2</span><span class="min-w-0 leading-relaxed">Calcule o HMAC-SHA256 com o segredo (<code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">whsec_…</code>, inteiro) e compare com <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">X-Webhook-Signature</code>, que chega como <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">sha256=&lt;hex&gt;</code>. Compare em tempo constante.</span></li>
                    <li class="flex gap-3"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">3</span><span class="min-w-0 leading-relaxed">Recuse timestamps com mais de 5 minutos e responda 2xx em até 10 segundos. Outras respostas são reenviadas com espera crescente.</span></li>
                    <li class="flex gap-3"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">4</span><span class="min-w-0 leading-relaxed">Ignore um <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">X-Webhook-Id</code> já processado: novas tentativas e reenvios mandam o mesmo id.</span></li>
                </ol>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 font-mono text-[11px] leading-relaxed text-slate-300"><span class="text-slate-500">// PHP</span>
$expected = 'sha256=' . hash_hmac('sha256',
    $timestamp . '.' . $body, $secret);

hash_equals($expected, $signature)
    && abs(time() - (int) $timestamp) &lt;= 300;</pre>
            </Card>
        </div>

        <!-- Formulário -->
        <Modal v-model="form.open" :title="form.id ? 'Editar webhook' : 'Novo webhook'" size="lg">
            <form id="hook-form" class="space-y-5" @submit.prevent="save">
                <TextInput v-model="form.url" label="URL (HTTPS)" placeholder="https://seu-sistema.com/webhooks" :icon="PhLink" :error="form.errors.url" required />
                <Field label="Eventos" :error="form.errors.events">
                    <div class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <Switch v-model="form.allEvents" label="Todos os eventos" description="Inclui os eventos que forem criados depois." />
                        <div v-if="!form.allEvents" class="grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2 dark:border-slate-800">
                            <Checkbox v-for="e in availableEvents" :key="e.name" :model-value="form.events.includes(e.name)" @update:model-value="(v) => toggleEvent(e.name, v)">
                                <span class="flex flex-col">
                                    <span v-if="e.description">{{ e.description }}</span>
                                    <code :class="e.description ? 'text-[11px] text-slate-500 dark:text-slate-400' : 'text-xs'">{{ e.name }}</code>
                                </span>
                            </Checkbox>
                        </div>
                    </div>
                </Field>
                <Switch v-if="form.id" v-model="form.active" label="Ativo" description="Desligado, nenhum evento é enviado para esta URL." />
            </form>
            <template #footer>
                <Button variant="ghost" @click="form.open = false">Cancelar</Button>
                <Button type="submit" form="hook-form" :loading="form.loading">{{ form.id ? 'Salvar' : 'Cadastrar' }}</Button>
            </template>
        </Modal>

        <!-- Segredo: mostrado uma vez -->
        <Modal v-model="secret.open" title="Segredo de assinatura" :closable="secret.acknowledged">
            <div class="space-y-4">
                <Alert tone="warning" title="Guarde agora">Use para validar as entregas no seu sistema. Ele não é mostrado de novo.</Alert>
                <CopyField :value="secret.value" />
                <Checkbox v-model="secret.acknowledged" label="Já guardei o segredo" />
            </div>
            <template #footer>
                <Button :disabled="!secret.acknowledged" @click="secret.open = false">Concluir</Button>
            </template>
        </Modal>

        <!-- Entregas -->
        <Drawer v-model="deliveriesOpen" title="Entregas" :description="deliveriesHook?.url" size="lg">
            <DataTable :columns="deliveryColumns" :rows="deliveries" :loading="deliveriesLoading" empty-title="Nenhuma entrega ainda" empty-text="Use “Enviar teste” para conferir a integração." :empty-icon="PhBroadcast">
                <template #cell-event="{ row }"><code class="text-xs text-slate-900 dark:text-white">{{ row.event }}</code></template>
                <template #cell-status="{ row }"><Badge :tone="row.status === 'delivered' ? 'success' : row.status === 'failed' ? 'danger' : 'warning'" dot>{{ deliveryStatus[row.status] ?? row.status }}</Badge></template>
                <template #cell-response_code="{ row }"><Tooltip v-if="row.error" :text="row.error"><span class="cursor-help underline decoration-dotted">{{ row.response_code ?? '—' }}</span></Tooltip><span v-else>{{ row.response_code ?? '—' }}</span></template>
                <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-slate-500">{{ format.relative(row.created_at) }}</span></template>
                <template #cell-actions="{ row }"><Button v-if="row.status !== 'delivered'" variant="ghost" size="xs" :icon="PhArrowClockwise" @click.stop="redeliver(row)">Reenviar</Button></template>
            </DataTable>
        </Drawer>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import {
    PhPlugsConnected, PhPlus, PhBroadcast, PhListMagnifyingGlass, PhPaperPlaneTilt, PhLink, PhPencilSimple, PhKey, PhTrash, PhArrowClockwise,
} from '@phosphor-icons/vue';
import { Alert, Badge, Button, Card, Checkbox, CopyField, DataTable, Drawer, Dropdown, EmptyState, Field, Modal, PageHeader, Skeleton, Switch, TextInput, Tooltip, confirm, errorMessage, format, toast } from '@/ui';
import type { Column, DropdownItem } from '@/ui';

const endpoints = ref<any[]>([]);
const availableEvents = ref<{ name: string; description: string | null }[]>([]);
const loading = ref(true);
const testing = ref<string | null>(null);

const deliveriesOpen = ref(false);
const deliveriesHook = ref<any>(null);
const deliveries = ref<any[]>([]);
const deliveriesLoading = ref(false);

const deliveryStatus: Record<string, string> = { delivered: 'Entregue', failed: 'Falhou', pending: 'Na fila', retrying: 'Tentando de novo' };

const deliveryColumns: Column[] = [
    { key: 'event', label: 'Evento' },
    { key: 'status', label: 'Status' },
    { key: 'attempts', label: 'Tentativas', align: 'center' },
    { key: 'response_code', label: 'Resposta' },
    { key: 'created_at', label: 'Quando' },
    { key: 'actions', label: '', align: 'right' },
];

const form = reactive({
    open: false,
    id: null as string | null,
    url: '',
    allEvents: true,
    events: [] as string[],
    active: true,
    loading: false,
    errors: {} as Record<string, string>,
});

const secret = reactive({ open: false, value: '', acknowledged: false });

onMounted(load);

async function load() {
    try {
        const r = await axios.get('/dashboard/webhooks');
        endpoints.value = r.data?.data?.endpoints ?? [];
        // events_catalog traz a descrição; available_events (só nomes) é o
        // formato antigo, mantido para quem já consome a rota.
        availableEvents.value = r.data?.data?.events_catalog
            ?? (r.data?.data?.available_events ?? []).map((name: string) => ({ name, description: null }));
    } catch (err) {
        toast.error('Não foi possível carregar os webhooks', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
}

function actions(hook: any): DropdownItem[] {
    return [
        { label: 'Editar', icon: PhPencilSimple, onClick: () => openForm(hook) },
        { label: 'Gerar novo segredo', icon: PhKey, onClick: () => rotate(hook) },
        { divider: true },
        { label: 'Excluir', icon: PhTrash, danger: true, onClick: () => remove(hook) },
    ];
}

function describe(name: string): string | undefined {
    return availableEvents.value.find((e) => e.name === name)?.description ?? undefined;
}

function toggleEvent(event: string, on: boolean) {
    form.events = on ? [...new Set([...form.events, event])] : form.events.filter((e) => e !== event);
}

function openForm(hook: any = null) {
    Object.assign(form, {
        open: true,
        id: hook?.id ?? null,
        url: hook?.url ?? '',
        allEvents: !hook?.events?.length,
        events: hook?.events ?? [],
        active: hook?.active ?? true,
        errors: {},
    });
}

function showSecret(value: string) {
    Object.assign(secret, { open: true, value, acknowledged: false });
}

async function save() {
    form.loading = true;
    form.errors = {};
    const payload: any = { url: form.url, events: form.allEvents ? null : form.events };
    if (form.id) payload.active = form.active;

    try {
        if (form.id) {
            await axios.put(`/dashboard/webhooks/${form.id}`, payload);
            toast.success('Webhook atualizado');
        } else {
            const r = await axios.post('/dashboard/webhooks', payload);
            showSecret(r.data.data.secret);
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

async function loadDeliveries() {
    if (!deliveriesHook.value) return;
    deliveriesLoading.value = true;
    try {
        const r = await axios.get(`/dashboard/webhooks/${deliveriesHook.value.id}/deliveries`);
        deliveries.value = r.data?.data ?? [];
    } finally {
        deliveriesLoading.value = false;
    }
}

function openDeliveries(hook: any) {
    deliveriesHook.value = hook;
    deliveries.value = [];
    deliveriesOpen.value = true;
    loadDeliveries();
}

async function sendTest(hook: any) {
    testing.value = hook.id;
    try {
        await axios.post(`/dashboard/webhooks/${hook.id}/test`);
        toast.info('Evento de teste enviado', { description: 'O resultado aparece em Entregas.', action: { label: 'Ver entregas', onClick: () => openDeliveries(hook) } });
    } catch (err) {
        toast.error('Não foi possível enviar o teste', { description: errorMessage(err) });
    } finally {
        testing.value = null;
    }
}

async function redeliver(delivery: any) {
    try {
        await axios.post(`/dashboard/webhooks/${deliveriesHook.value.id}/deliveries/${delivery.id}/redeliver`);
        toast.success('Reenvio na fila');
        await loadDeliveries();
    } catch (err) {
        toast.error('Não foi possível reenviar', { description: errorMessage(err) });
    }
}

async function rotate(hook: any) {
    let value = '';
    const ok = await confirm({
        title: 'Gerar novo segredo?',
        description: 'O segredo atual deixa de valer imediatamente. Atualize o seu sistema logo depois.',
        tone: 'danger',
        confirmText: 'Gerar novo segredo',
        onConfirm: async () => {
            const r = await axios.post(`/dashboard/webhooks/${hook.id}/rotate-secret`);
            value = r.data.data.secret;
        },
    });
    if (ok) showSecret(value);
}

async function remove(hook: any) {
    const ok = await confirm({
        title: 'Excluir webhook?',
        description: `${hook.url} deixa de receber eventos.`,
        tone: 'danger',
        confirmText: 'Excluir',
        onConfirm: () => axios.delete(`/dashboard/webhooks/${hook.id}`).then(() => undefined),
    });
    if (!ok) return;
    toast.success('Webhook excluído');
    await load();
}
</script>
