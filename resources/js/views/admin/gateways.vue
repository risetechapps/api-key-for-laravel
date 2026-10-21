<template>
    <div>
        <PageHeader title="Gateways de pagamento" subtitle="O que for salvo aqui vale por cima do .env e fica criptografado no banco." :icon="PhWallet">
            <template #actions>
                <Button :icon="PhCheck" :loading="saving" :disabled="!dirty" @click="save">Salvar alterações</Button>
            </template>
        </PageHeader>

        <div v-if="loading" class="space-y-4"><Skeleton height="7rem" rounded="rounded-2xl" /><Skeleton height="20rem" rounded="rounded-2xl" /></div>

        <template v-else>
            <!-- Gateway ativo -->
            <Card title="Gateway ativo" subtitle="Atende o checkout, o cadastro de cartão, o PIX, a renovação e o excedente. Ao trocar, os cartões salvos no anterior deixam de ser cobrados e o assinante cadastra um novo.">
                <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Gateway ativo">
                    <button
                        v-for="g in visibleGateways"
                        :key="g.id"
                        type="button"
                        role="radio"
                        :aria-checked="form['payment.gateway'] === g.id"
                        :class="[
                            'relative flex items-start gap-4 rounded-2xl border-2 p-4 text-left transition-[border-color,background-color,box-shadow] duration-200',
                            form['payment.gateway'] === g.id
                                ? 'border-brand-500 bg-brand-50/60 shadow-lg shadow-brand-500/10 dark:bg-brand-500/10'
                                : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600',
                        ]"
                        @click="form['payment.gateway'] = g.id"
                    >
                        <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-black text-white', g.badge]">{{ g.short }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                                {{ g.name }}
                                <Badge v-if="configured(g)" tone="success" dot>Configurado</Badge>
                                <Badge v-else tone="warning">Sem credenciais</Badge>
                            </span>
                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">{{ g.hint }}</span>
                        </span>
                        <span :class="['mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2', form['payment.gateway'] === g.id ? 'border-brand-600 bg-brand-600' : 'border-slate-300 dark:border-slate-600']">
                            <PhCheck v-if="form['payment.gateway'] === g.id" :size="11" weight="bold" class="text-white" />
                        </span>
                    </button>
                </div>
                <Alert v-if="activeNotConfigured" tone="danger" class="mt-4">O gateway escolhido está sem credenciais: o checkout e o cadastro de cartão vão falhar até você preencher abaixo.</Alert>
            </Card>

            <!-- Credenciais -->
            <Tabs v-model="tab" class="mt-8" :tabs="visibleGateways.map((g) => ({ key: g.id, label: g.name }))">
                <template #default="{ active }">
                    <div v-for="g in visibleGateways.filter((x) => x.id === active)" :key="g.id" class="grid gap-6 lg:grid-cols-3">
                        <Card class="lg:col-span-2" title="Credenciais e opções">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <template v-for="field in g.fields" :key="field.key">
                                    <div v-if="field.type === 'bool'" class="sm:col-span-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                                        <Switch v-model="form[field.key]" :label="field.label" :description="field.help" />
                                    </div>
                                    <Field v-else-if="field.type === 'certificate'" class="sm:col-span-2" :label="field.label" :hint="field.help">
                                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 p-4 transition-colors hover:border-brand-400 hover:bg-brand-50/40 dark:border-slate-600 dark:hover:bg-brand-500/5">
                                            <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', form[field.key] || secrets[field.key]?.set ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-slate-800']">
                                                <PhCertificate :size="20" />
                                            </span>
                                            <span class="min-w-0 flex-1 text-sm">
                                                <span class="block font-medium text-slate-900 dark:text-white">{{ certificateNames[field.key] || (secrets[field.key]?.set ? 'Certificado configurado' : 'Nenhum certificado') }}</span>
                                                <span class="block text-xs text-slate-500">{{ form[field.key] ? 'Será salvo ao clicar em Salvar alterações.' : 'Clique para enviar o arquivo .p12 (ou .pem) da aplicação.' }}</span>
                                            </span>
                                            <input type="file" accept=".p12,.pfx,.pem" class="sr-only" @change="pickCertificate(field.key, $event)" />
                                        </label>
                                    </Field>
                                    <TextInput
                                        v-else
                                        v-model="form[field.key]"
                                        :class="field.wide ? 'sm:col-span-2' : ''"
                                        :label="field.label"
                                        :type="field.secret ? 'password' : field.type === 'int' ? 'number' : 'text'"
                                        :placeholder="placeholder(field)"
                                        :hint="fromPanel.includes(field.key) ? `Salvo no painel${field.help ? ' · ' + field.help : ''}` : field.help"
                                        autocomplete="off"
                                        mono
                                        :icon="field.secret ? PhLockKey : null"
                                    />
                                </template>
                            </div>
                        </Card>

                        <div class="space-y-6">
                            <Card :title="g.webhookCopy === false ? 'Notificações' : 'Webhook'" :subtitle="g.webhookHelp">
                                <CopyField v-if="g.webhookCopy !== false" :value="webhooks[g.id] ?? ''" />
                            </Card>
                            <Card title="Conexão" subtitle="Confere as credenciais salvas, sem cobrar nada">
                                <Button block variant="outline" :icon="PhPlugsConnected" :loading="testing === g.id" @click="test(g.id)">Testar conexão</Button>
                                <Transition name="ui-fade">
                                    <Alert v-if="results[g.id]" :tone="results[g.id].ok ? 'success' : 'danger'" class="mt-4">
                                        {{ results[g.id].message }}
                                        <template v-if="g.id === 'mercadopago' && results[g.id].ok && results[g.id].automatic_payments !== undefined && results[g.id].automatic_payments !== null">
                                            <br>Pagamentos Automáticos: <strong>{{ results[g.id].automatic_payments ? 'liberados' : 'não liberados' }}</strong>{{ results[g.id].automatic_payments ? '' : ' (cobrança sem CVV indisponível)' }}.
                                        </template>
                                    </Alert>
                                </Transition>
                            </Card>
                        </div>
                    </div>
                </template>
            </Tabs>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { PhWallet, PhCheck, PhLockKey, PhPlugsConnected, PhCertificate } from '@phosphor-icons/vue';
import { Alert, Badge, Button, Card, CopyField, Field, PageHeader, Skeleton, Switch, Tabs, TextInput, errorMessage, toast } from '@/ui';

interface GatewayField {
    key: string;
    label: string;
    type: 'string' | 'bool' | 'int' | 'url' | 'certificate';
    secret?: boolean;
    help?: string;
    wide?: boolean;
}

interface Gateway {
    id: string;
    name: string;
    short: string;
    badge: string;
    hint: string;
    webhookHelp: string;
    /** Falso quando a URL vai em cada cobrança e não há o que cadastrar. */
    webhookCopy?: boolean;
    credential: string;
    fields: GatewayField[];
}

const gateways: Gateway[] = [
    {
        id: 'mercadopago',
        name: 'Mercado Pago',
        short: 'MP',
        badge: 'bg-gradient-to-br from-sky-400 to-sky-600',
        hint: 'Checkout Bricks, PIX e Pagamentos Automáticos (cobrança sem CVV depende de liberação comercial).',
        webhookHelp: 'Cadastre no Mercado Pago com os eventos Pagamentos, Order e Perfis de pagamento.',
        credential: 'mercadopago.access_token',
        fields: [
            { key: 'mercadopago.public_key', label: 'Public key', type: 'string' },
            { key: 'mercadopago.access_token', label: 'Access token', type: 'string', secret: true },
            { key: 'mercadopago.webhook_secret', label: 'Assinatura secreta do webhook', type: 'string', secret: true },
            { key: 'mercadopago.pix.expiration_minutes', label: 'Validade do QR PIX (minutos)', type: 'int' },
            { key: 'mercadopago.notification_url', label: 'URL de notificação', type: 'url', help: 'Vai em cada pagamento. Deixe vazio em desenvolvimento local.', wide: true },
            { key: 'mercadopago.pix.enabled', label: 'Aceitar PIX', type: 'bool', help: 'Precisa de chave PIX cadastrada na conta do Mercado Pago.' },
            { key: 'mercadopago.automatic_payments.enabled', label: 'Pagamentos Automáticos', type: 'bool', help: 'Cobrar a renovação pelo perfil do cartão, sem CVV.' },
        ],
    },
    {
        id: 'stripe',
        name: 'Stripe',
        short: 'S',
        badge: 'bg-gradient-to-br from-violet-500 to-indigo-600',
        hint: 'Cartão salvo cobrado sem CVV (off_session), 3DS quando o banco pede, e PIX em conta brasileira.',
        webhookHelp: 'Em Developers > Webhooks, adicione esta URL com os eventos payment_intent.succeeded, payment_intent.payment_failed e payment_intent.canceled.',
        credential: 'stripe.secret_key',
        fields: [
            { key: 'stripe.publishable_key', label: 'Publishable key (pk_…)', type: 'string' },
            { key: 'stripe.secret_key', label: 'Secret key (sk_…)', type: 'string', secret: true },
            { key: 'stripe.webhook_secret', label: 'Signing secret do webhook (whsec_…)', type: 'string', secret: true },
            { key: 'stripe.statement_descriptor', label: 'Complemento na fatura do cartão', type: 'string', help: 'Até 22 caracteres, sem acentos. O PIX ignora.' },
            { key: 'stripe.pix.expiration_minutes', label: 'Validade do QR PIX (minutos)', type: 'int' },
            { key: 'stripe.pix.enabled', label: 'Aceitar PIX', type: 'bool', help: 'Ative PIX em Settings > Payment methods. De R$ 0,50 a R$ 3.000,00 por pagamento.' },
        ],
    },
    {
        id: 'efi',
        name: 'Efí',
        short: 'Efí',
        badge: 'bg-gradient-to-br from-orange-400 to-orange-600',
        hint: 'Cartão com token reutilizável (sem CVV na renovação) e PIX pela API Pix com certificado.',
        webhookHelp: 'O cartão manda a notificação sozinho, em cada cobrança, para a URL do pacote (precisa ser HTTPS). O PIX é conferido pelo painel enquanto o QR está aberto e pela reconciliação.',
        webhookCopy: false,
        credential: 'efi.client_secret',
        fields: [
            { key: 'efi.client_id', label: 'Client ID', type: 'string' },
            { key: 'efi.client_secret', label: 'Client Secret', type: 'string', secret: true },
            { key: 'efi.account_id', label: 'Identificador de conta (payee code)', type: 'string', help: 'Código de 32 caracteres em API > Introdução > Identificador de conta. Não é o número da conta.' },
            { key: 'efi.pix.key', label: 'Chave PIX', type: 'string', help: 'Cadastrada na conta Efí.' },
            { key: 'efi.pix.certificate', label: 'Certificado da aplicação', type: 'certificate', secret: true, help: 'Gerado em API > Aplicações > Certificados. Fica criptografado no banco.' },
            { key: 'efi.pix.certificate_password', label: 'Senha do certificado', type: 'string', secret: true, help: 'Só se o certificado tiver senha.' },
            { key: 'efi.pix.expiration_minutes', label: 'Validade do QR PIX (minutos)', type: 'int' },
            { key: 'efi.sandbox', label: 'Homologação', type: 'bool', help: 'Usa as APIs de homologação da Efí (credenciais e certificado de homologação).' },
            { key: 'efi.pix.enabled', label: 'Aceitar PIX', type: 'bool', help: 'Precisa da chave PIX e do certificado. O cliente informa o CPF.' },
        ],
    },
    {
        id: 'pagbank',
        name: 'PagBank',
        short: 'PB',
        badge: 'bg-gradient-to-br from-lime-400 to-green-600',
        hint: 'Cartão criptografado no navegador, salvo e cobrado sem CVV como recorrência, e PIX.',
        webhookHelp: 'Vai em cada pedido, sem cadastro (precisa ser HTTPS). A notificação é conferida pelo token da conta.',
        webhookCopy: false,
        credential: 'pagbank.token',
        fields: [
            { key: 'pagbank.token', label: 'Token da conta', type: 'string', secret: true, wide: true },
            { key: 'pagbank.public_key', label: 'Chave pública', type: 'string', help: 'Em branco, o pacote cria e guarda uma.', wide: true },
            { key: 'pagbank.statement_descriptor', label: 'Nome na fatura do cartão', type: 'string', help: 'Até 17 caracteres, sem acentos.' },
            { key: 'pagbank.pix.expiration_minutes', label: 'Validade do QR PIX (minutos)', type: 'int' },
            { key: 'pagbank.sandbox', label: 'Sandbox', type: 'bool', help: 'Usa o ambiente de testes do PagBank (token de sandbox).' },
            { key: 'pagbank.pix.enabled', label: 'Aceitar PIX', type: 'bool', help: 'O cliente informa o CPF.' },
        ],
    },
    {
        id: 'pagarme',
        name: 'Pagar.me',
        short: 'P',
        badge: 'bg-gradient-to-br from-emerald-400 to-emerald-600',
        hint: 'Cartão salvo cobrado sem CVV na renovação, e PIX.',
        webhookHelp: 'Cadastre no Pagar.me com os eventos de pedido. Se definir usuário e senha, use os mesmos lá.',
        credential: 'pagarme.secret_key',
        fields: [
            { key: 'pagarme.public_key', label: 'Chave pública (pk_…)', type: 'string' },
            { key: 'pagarme.secret_key', label: 'Chave secreta (sk_…)', type: 'string', secret: true },
            { key: 'pagarme.webhook_user', label: 'Usuário do webhook (Basic Auth)', type: 'string' },
            { key: 'pagarme.webhook_password', label: 'Senha do webhook (Basic Auth)', type: 'string', secret: true },
            { key: 'pagarme.statement_descriptor', label: 'Nome na fatura do cartão', type: 'string', help: 'Até 13 caracteres, sem acentos.' },
            { key: 'pagarme.pix.expiration_minutes', label: 'Validade do QR PIX (minutos)', type: 'int' },
            { key: 'pagarme.pix.enabled', label: 'Aceitar PIX', type: 'bool', help: 'O Pagar.me exige CPF e celular do cliente.' },
        ],
    },
];

const loading = ref(true);
const saving = ref(false);
const testing = ref<string | null>(null);
const tab = ref('mercadopago');
const form = ref<Record<string, any>>({});
// Como chegou do servidor: só o que mudou vai no salvar, para um valor do .env
// não ser copiado para o banco sem ninguém pedir.
const original = ref<Record<string, any>>({});
const secrets = ref<Record<string, { set: boolean; hint: string | null }>>({});
const fromPanel = ref<string[]>([]);
const webhooks = ref<Record<string, string>>({});
const results = ref<Record<string, any>>({});
// O que o servidor diz de cada gateway: se tem credencial e se aparece aqui.
const serverGateways = ref<Record<string, { configured: boolean; hidden: boolean }>>({});
const certificateNames = ref<Record<string, string>>({});

const visibleGateways = computed(() => gateways.filter((g) => !serverGateways.value[g.id]?.hidden));

const allFields = computed(() => [{ key: 'payment.gateway', type: 'string' } as GatewayField, ...gateways.flatMap((g) => g.fields)]);
const dirty = computed(() => allFields.value.some((f) => form.value[f.key] !== original.value[f.key]));

function configured(g: Gateway): boolean {
    return !!serverGateways.value[g.id]?.configured || !!secrets.value[g.credential]?.set || !!form.value[g.credential];
}

function pickCertificate(key: string, event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (file.size > 48 * 1024) {
        toast.error('Arquivo grande demais', { description: 'O certificado da Efí tem poucos KB. Confira o arquivo.' });
        return;
    }
    const reader = new FileReader();
    reader.onload = () => {
        // data:...;base64,XXXX → só o base64
        form.value[key] = String(reader.result ?? '').replace(/^data:[^,]*,/, '');
        certificateNames.value[key] = file.name;
    };
    reader.readAsDataURL(file);
}

const activeNotConfigured = computed(() => {
    const g = gateways.find((x) => x.id === form.value['payment.gateway']);
    return g ? !configured(g) : false;
});

function apply(data: any) {
    const values = data?.values ?? {};
    const next: Record<string, any> = {};

    for (const [key, value] of Object.entries(values)) {
        if (value && typeof value === 'object' && 'set' in (value as any)) {
            secrets.value[key] = value as any;
            next[key] = '';
        } else {
            next[key] = value ?? '';
        }
    }

    form.value = next;
    original.value = { ...next };
    fromPanel.value = data?.from_panel ?? [];
    webhooks.value = data?.webhooks ?? {};
    serverGateways.value = Object.fromEntries((data?.gateways ?? []).map((g: any) => [g.key, g]));
    certificateNames.value = {};
    tab.value = next['payment.gateway'] || 'mercadopago';
}

function placeholder(field: GatewayField): string {
    if (!field.secret) return '';
    const s = secrets.value[field.key];
    return s?.set ? `Configurado (${s.hint ?? '••••'}). Em branco mantém.` : 'Não configurado';
}

onMounted(async () => {
    try {
        const r = await axios.get('/dashboard/admin/payment-settings');
        apply(r.data?.data);
    } catch (err) {
        toast.error('Não foi possível carregar', { description: errorMessage(err) });
    } finally {
        loading.value = false;
    }
});

async function save() {
    const values: Record<string, any> = {};
    for (const f of allFields.value) {
        const v = form.value[f.key];
        if (v === original.value[f.key]) continue;
        // Campo comum esvaziado: volta a valer o .env.
        values[f.key] = v === '' && !f.secret ? null : f.type === 'int' ? Number(v) : v;
    }
    if (!Object.keys(values).length) return;

    saving.value = true;
    try {
        const r = await axios.put('/dashboard/admin/payment-settings', { values });
        apply(r.data?.data);
        results.value = {};
        toast.success('Configurações salvas', { description: 'Valem a partir da próxima requisição, inclusive na fila.' });
    } catch (err) {
        toast.error('Não foi possível salvar', { description: errorMessage(err) });
    } finally {
        saving.value = false;
    }
}

async function test(gateway: string) {
    testing.value = gateway;
    try {
        const r = await axios.post(`/dashboard/admin/payment-settings/test/${gateway}`);
        results.value[gateway] = r.data?.data;
    } catch (err) {
        results.value[gateway] = { ok: false, message: errorMessage(err, 'Falha ao testar.') };
    } finally {
        testing.value = null;
    }
}
</script>
