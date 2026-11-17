<template>
    <div>
        <PageHeader title="Webhooks" subtitle="Avisos enviados ao seu sistema quando algo acontece na conta. Todos são assinados com o mesmo segredo." :icon="PhPlugsConnected">
            <template #actions>
                <Button variant="soft" :icon="PhListMagnifyingGlass" @click="openDeliveries(null)">Outras entregas</Button>
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

            <div class="space-y-6 lg:sticky lg:top-24 lg:h-fit">
                <!-- Um segredo por conta: assina os endpoints daqui e as entregas para
                     a URL que o cliente informa em cada pedido. -->
                <Card title="Segredo de assinatura" subtitle="O mesmo para todos os webhooks da conta, inclusive os enviados para a URL que você informa em cada pedido">
                    <Skeleton v-if="secret.loading" :lines="2" />
                    <div v-else class="space-y-4">
                        <CopyField v-if="secret.value" :value="secret.value" masked />
                        <Button variant="outline" size="sm" :icon="PhKey" @click="rotate">Gerar novo segredo</Button>

                        <form class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800" @submit.prevent="sendUrlTest">
                            <TextInput v-model="urlTest.url" label="Testar uma URL" type="url" placeholder="https://seu-sistema.com/webhooks" :icon="PhLink" :error="urlTest.error" hint="Envia um webhook.test assinado e mostra a resposta. A URL não precisa estar cadastrada." />
                            <Button type="submit" variant="soft" size="sm" :icon="PhPaperPlaneTilt" :loading="urlTest.loading" :disabled="!urlTest.url">Enviar teste</Button>
                            <Alert v-if="urlTest.result" :tone="urlTest.result.delivered ? 'success' : 'danger'">
                                <template v-if="urlTest.result.delivered">Entregue: a URL respondeu HTTP {{ urlTest.result.http_status }}.</template>
                                <template v-else>
                                    Não entregue{{ urlTest.result.http_status ? ` (HTTP ${urlTest.result.http_status})` : '' }}.
                                    <span v-if="urlTest.result.http_status === 401">A assinatura foi recusada: confira se o seu sistema usa este segredo.</span>
                                    <span v-if="urlTest.result.error" class="mt-1 block break-all font-mono text-xs">{{ urlTest.result.error }}</span>
                                </template>
                            </Alert>
                        </form>
                    </div>
                </Card>

                <Card title="Verificando a assinatura" subtitle="Cada entrega é assinada com o segredo da conta">
                    <!-- O texto fica num <span>: no <li> flex, cada trecho e cada <code> virava
                         um item separado, com gap entre eles. -->
                    <ol class="space-y-3 text-sm text-slate-600 dark:text-slate-400">
                        <li class="flex gap-3"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">1</span><span class="min-w-0 leading-relaxed">Junte o header <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">X-Webhook-Timestamp</code>, um ponto e o corpo cru, exatamente como chegou.</span></li>
                        <li class="flex gap-3"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">2</span><span class="min-w-0 leading-relaxed">Calcule o HMAC-SHA256 com o segredo da conta (<code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">whsec_…</code>, inteiro) e compare com <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">X-Webhook-Signature</code>, que chega como <code class="whitespace-nowrap rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">sha256=&lt;hex&gt;</code>. Compare em tempo constante.</span></li>
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

        <!-- Entregas -->
        <Drawer v-model="deliveriesOpen" :title="deliveriesHook ? 'Entregas' : 'Outras entregas'" :description="deliveriesHook?.url ?? 'Enviadas para a URL informada em cada pedido, fora dos webhooks cadastrados.'" size="lg">
            <DataTable :columns="deliveriesHook ? deliveryColumns : directColumns" :rows="deliveries" :loading="deliveriesLoading" empty-title="Nenhuma entrega ainda" empty-text="Use “Enviar teste” para conferir a integração." :empty-icon="PhBroadcast">
                <template #cell-event="{ row }"><code class="text-xs text-slate-900 dark:text-white">{{ row.event }}</code></template>
                <template #cell-url="{ row }"><Tooltip :text="row.url"><span class="block max-w-[14rem] truncate font-mono text-xs">{{ row.url }}</span></Tooltip></template>
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

const directColumns: Column[] = [...deliveryColumns.slice(0, 1), { key: 'url', label: 'URL' }, ...deliveryColumns.slice(1)];

const secret = reactive({ value: '', loading: true });

const urlTest = reactive({
    url: '',
    loading: false,
    error: '',
    result: null as null | { delivered: boolean; http_status: number | null; error: string | null },
});

onMounted(() => {
    load();
    loadSecret();
});

async function loadSecret() {
    try {
        const r = await axios.get('/dashboard/webhooks/secret');
        secret.value = r.data?.data?.secret ?? '';
    } catch (err) {
        toast.error('Não foi possível carregar o segredo', { description: errorMessage(err) });
    } finally {
        secret.loading = false;
    }
}

async function sendUrlTest() {
    urlTest.loading = true;
    urlTest.error = '';
    urlTest.result = null;
    try {
        const r = await axios.post('/dashboard/webhooks/test-url', { url: urlTest.url });
        urlTest.result = r.data?.data ?? null;
    } catch (err: any) {
        urlTest.error = err?.response?.data?.errors?.url?.[0] ?? '';
        if (!urlTest.error) toast.error('Não foi possível enviar o teste', { description: errorMessage(err) });
    } finally {
        urlTest.loading = false;
    }
}

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
            await axios.post('/dashboard/webhooks', payload);
            toast.success('Webhook cadastrado', { description: 'As entregas são assinadas com o segredo da conta.' });
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

function deliveriesBase(): string {
    return deliveriesHook.value ? `/dashboard/webhooks/${deliveriesHook.value.id}/deliveries` : '/dashboard/webhooks/deliveries';
}

async function loadDeliveries() {
    deliveriesLoading.value = true;
    try {
        const r = await axios.get(deliveriesBase());
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
        await axios.post(`${deliveriesBase()}/${delivery.id}/redeliver`);
        toast.success('Reenvio na fila');
        await loadDeliveries();
    } catch (err) {
        toast.error('Não foi possível reenviar', { description: errorMessage(err) });
    }
}

async function rotate() {
    const ok = await confirm({
        title: 'Gerar novo segredo?',
        description: 'O segredo atual deixa de valer imediatamente para todos os webhooks da conta. Atualize o seu sistema logo depois.',
        tone: 'danger',
        confirmText: 'Gerar novo segredo',
        onConfirm: async () => {
            const r = await axios.post('/dashboard/webhooks/secret/rotate');
            secret.value = r.data.data.secret;
        },
    });
    if (ok) toast.success('Novo segredo gerado', { description: 'Atualize o seu sistema com o valor novo.' });
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
