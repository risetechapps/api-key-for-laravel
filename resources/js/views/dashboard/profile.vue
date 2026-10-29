<template>
    <div>
        <PageHeader title="Perfil" subtitle="Seus dados, endereço, avisos e privacidade" :icon="PhUserCircle" />

        <Alert v-if="pageError" tone="danger" title="Não foi possível carregar o perfil">
            {{ pageError }}
            <template #actions><Button size="sm" variant="outline" @click="reloadProfile">Tentar de novo</Button></template>
        </Alert>

        <template v-else>
            <!-- Cartão de identidade -->
            <Card :padding="false" class="mb-8 overflow-hidden">
                <div class="h-24 bg-gradient-to-r from-brand-600 via-brand-500 to-brand-700" />
                <div class="flex flex-col gap-4 px-6 pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:gap-4">
                        <Avatar :name="form.name" size="lg" class="-mt-10 !h-20 !w-20 !text-2xl ring-4" />
                        <div class="sm:pb-1">
                            <Skeleton v-if="pageLoading" height="1.25rem" width="12rem" />
                            <h2 v-else class="text-lg font-bold text-slate-900 dark:text-white">{{ form.name || 'Sem nome' }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ form.email }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Badge v-if="user?.active_plan?.plan" tone="brand" :icon="PhCrown">{{ user.active_plan.plan.name }}</Badge>
                        <Badge :tone="user?.email_verified ? 'success' : 'warning'" dot>{{ user?.email_verified ? 'E-mail verificado' : 'E-mail não verificado' }}</Badge>
                        <Badge :tone="user?.two_factor_enabled ? 'success' : 'neutral'" :icon="PhShieldCheck">{{ user?.two_factor_enabled ? '2FA ativa' : '2FA desligada' }}</Badge>
                    </div>
                </div>
            </Card>

            <Tabs v-model="tab" :tabs="tabs" label="Seções do perfil">
                <template #default="{ active }">
                    <!-- Dados pessoais -->
                    <div v-if="active === 'personal'" class="space-y-6">
                        <Card title="Dados pessoais" subtitle="Usados na nota, no recibo e na análise antifraude dos pagamentos">
                            <div v-if="pageLoading" class="grid gap-5 md:grid-cols-2"><Skeleton v-for="i in 8" :key="i" height="2.5rem" /></div>
                            <form v-else id="profile-form" class="grid gap-5 md:grid-cols-2" @submit.prevent="updateProfile">
                                <TextInput v-model="form.name" label="Nome completo" required uppercase :icon="PhUser" :error="fieldErrors.name" />
                                <TextInput v-model="form.email" label="E-mail" type="email" disabled :icon="PhEnvelope" hint="Troque em Segurança." />
                                <TextInput v-model="form.cpf" label="CPF" placeholder="000.000.000-00" required :icon="PhIdentificationCard" :error="fieldErrors.cpf" />
                                <TextInput v-model="form.rg" label="RG" uppercase :icon="PhIdentificationCard" :error="fieldErrors.rg" />
                                <TextInput v-model="form.birth_date" label="Data de nascimento" type="date" required :icon="PhCalendarBlank" :error="fieldErrors.birth_date" />
                                <Select v-model="form.genre" label="Gênero" placeholder="Selecione" :options="{ MASCULINE: 'Masculino', FEMININE: 'Feminino', OTHER: 'Outro', NOT_INFORMED: 'Prefiro não informar' }" />
                                <TextInput v-model="form.cellphone" label="Celular" placeholder="(00) 00000-0000" :icon="PhDeviceMobile" :error="fieldErrors.cellphone" />
                                <TextInput v-model="form.telephone" label="Telefone" placeholder="(00) 0000-0000" :icon="PhPhone" :error="fieldErrors.telephone" />
                                <TextInput v-model="form.nationality" label="Nacionalidade" placeholder="Brasileiro(a)" uppercase :icon="PhGlobe" />
                                <TextInput v-model="form.naturalness" label="Naturalidade" placeholder="Cidade/UF" uppercase :icon="PhMapPin" />
                                <Select v-model="form.marital_status" label="Estado civil" placeholder="Selecione" :options="{ SINGLE: 'Solteiro(a)', MARRIED: 'Casado(a)', WIDOWER: 'Viúvo(a)', 'JUDICIALLY SEPARATED': 'Separado(a)' }" />
                            </form>
                            <template #footer>
                                <div class="flex justify-end"><Button type="submit" form="profile-form" :loading="updating" :icon="PhCheck">Salvar alterações</Button></div>
                            </template>
                        </Card>
                        <component :is="card" v-for="(card, i) in extraCards.personal" :key="`extra-personal-${i}`" />
                    </div>

                    <!-- Endereço -->
                    <div v-else-if="active === 'address'" class="space-y-6">
                        <Card title="Endereço" subtitle="Digite o CEP para preencher o resto automaticamente">
                            <form id="address-form" class="grid gap-5 md:grid-cols-6" @submit.prevent="updateProfile">
                                <TextInput v-model="form.address.zip_code" class="md:col-span-2" label="CEP" placeholder="00000-000" :icon="PhMapPin">
                                    <template v-if="cepLoading" #suffix><Spinner size="sm" class="text-brand-500" /></template>
                                </TextInput>
                                <TextInput v-model="form.address.address" class="md:col-span-4" label="Endereço" uppercase :icon="PhHouse" />
                                <TextInput v-model="form.address.number" class="md:col-span-2" label="Número" uppercase :icon="PhHash" />
                                <TextInput v-model="form.address.complement" class="md:col-span-4" label="Complemento" placeholder="Apto, bloco…" uppercase :icon="PhNote" />
                                <TextInput v-model="form.address.district" class="md:col-span-2" label="Bairro" uppercase :icon="PhBuildings" />
                                <TextInput v-model="form.address.city" class="md:col-span-2" label="Cidade" uppercase :icon="PhBuildings" />
                                <Select v-model="form.address.state" class="md:col-span-2" label="Estado" placeholder="Selecione" :options="brazilianStates.map((s) => ({ value: s.uf, label: s.name }))" />
                                <TextInput v-model="form.address.country" class="md:col-span-2" label="País" uppercase :icon="PhGlobe" />
                            </form>
                            <template #footer>
                                <div class="flex justify-end"><Button type="submit" form="address-form" :loading="updating" :icon="PhCheck">Salvar endereço</Button></div>
                            </template>
                        </Card>
                        <component :is="card" v-for="(card, i) in extraCards.address" :key="`extra-address-${i}`" />
                    </div>

                    <!-- Avisos por e-mail -->
                    <div v-else-if="active === 'notifications'" class="space-y-6">
                        <Card title="Avisos por e-mail" subtitle="Escolha quais e-mails informativos a conta recebe">
                            <div class="space-y-5">
                                <Switch
                                    v-model="usageNotices"
                                    label="Avisos de uso"
                                    description="Uso em 80% do limite, chave de API perto de vencer e início do excedente. Desligue se você já acompanha pelo /usage, pelos headers X-Quota-* ou por webhook."
                                />
                                <p class="text-xs text-slate-500 dark:text-slate-400">E-mails de cobrança, de segurança da conta e de expiração do plano são sempre enviados.</p>
                            </div>
                            <template #footer>
                                <div class="flex justify-end"><Button :loading="savingNotices" :icon="PhCheck" @click="saveNotifications">Salvar</Button></div>
                            </template>
                        </Card>
                        <component :is="card" v-for="(card, i) in extraCards.notifications" :key="`extra-notifications-${i}`" />
                    </div>

                    <!-- Privacidade -->
                    <div v-else class="space-y-6">
                        <Card title="Baixar meus dados" subtitle="Perfil, endereço, assinaturas, cartões (só os últimos dígitos) e histórico de requisições, em JSON">
                            <Button variant="outline" :icon="PhDownloadSimple" :loading="exporting" @click="exportData">Baixar meus dados</Button>
                        </Card>

                        <Card v-if="!authStore.isAdmin" class="!border-red-200 dark:!border-red-500/30">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-red-600 dark:text-red-400">Excluir conta</h3>
                                    <p class="mt-1 max-w-xl text-sm text-slate-500 dark:text-slate-400">Encerra a assinatura na hora, sem estorno, invalida as API keys e apaga seus dados pessoais. O registro dos pagamentos é mantido, sem seus dados, por obrigação legal. Não dá para desfazer.</p>
                                </div>
                                <Button variant="danger" :icon="PhTrash" @click="deleteAccount">Excluir conta</Button>
                            </div>
                        </Card>

                        <component :is="card" v-for="(card, i) in extraCards.privacy" :key="`extra-privacy-${i}`" />
                    </div>
                </template>
            </Tabs>
        </template>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { useRouter } from 'vue-router';
import {
    PhUser, PhUserCircle, PhEnvelope, PhIdentificationCard, PhCalendarBlank, PhPhone, PhDeviceMobile,
    PhGlobe, PhMapPin, PhBuildings, PhHouse, PhHash, PhNote, PhCheck, PhCrown, PhShieldCheck, PhLockKey,
    PhDownloadSimple, PhTrash, PhBell,
} from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import appConfig from '@/api-key.config';
import type { ProfileCard, ProfileTab } from '@/api-key.config';
import { Alert, Avatar, Badge, Button, Card, PageHeader, Select, Skeleton, Spinner, Switch, Tabs, TextInput, errorMessage, prompt, toast } from '@/ui';
import type { TabItem } from '@/ui';

const authStore = useAuthStore();
const router = useRouter();

const tab = ref('personal');
// Cartões do app (api-key.config.ts → profile.extraCards), agrupados por aba.
// Só o componente vai para Dados pessoais; `{ component, tab }` escolhe outra.
// A aba da API key saiu (as chaves ficam em API Keys): `tab: 'key'` cai em
// Dados pessoais.
const extraCards = computed(() => {
    const byTab: Record<Exclude<ProfileTab, 'key'>, unknown[]> = { personal: [], address: [], notifications: [], privacy: [] };

    for (const card of (appConfig.profile?.extraCards ?? []) as ProfileCard[]) {
        const entry = card as { component?: unknown; tab?: ProfileTab };
        const isEntry = typeof card === 'object' && card !== null && 'component' in card;
        const target = isEntry && entry.tab && entry.tab !== 'key' ? entry.tab : 'personal';
        byTab[target].push(isEntry ? entry.component : card);
    }

    return byTab;
});

// Os avisos vão para o dono da conta: só ele vê a aba de notificações.
const tabs = computed<TabItem[]>(() => [
    { key: 'personal', label: 'Dados pessoais', icon: PhUser },
    { key: 'address', label: 'Endereço', icon: PhMapPin },
    ...(authStore.teamCan('owner') ? [{ key: 'notifications', label: 'Notificações', icon: PhBell }] : []),
    { key: 'privacy', label: 'Privacidade', icon: PhLockKey },
]);

const exporting = ref(false);
const updating = ref(false);
const cepLoading = ref(false);
const pageLoading = ref(true);
const pageError = ref('');
const usageNotices = ref(true);
const savingNotices = ref(false);

const user = computed<any>(() => authStore.user);

const form = reactive({
    name: '', email: '', cpf: '', rg: '', birth_date: '', genre: '', telephone: '', cellphone: '',
    nationality: '', naturalness: '', marital_status: '',
    address: { zip_code: '', country: 'Brasil', state: '', city: '', district: '', address: '', number: '', complement: '' },
});

const fieldErrors = reactive<Record<string, string>>({ name: '', cpf: '', rg: '', birth_date: '', cellphone: '', telephone: '' });

const brazilianStates = [
    { uf: 'AC', name: 'Acre' }, { uf: 'AL', name: 'Alagoas' }, { uf: 'AP', name: 'Amapá' }, { uf: 'AM', name: 'Amazonas' },
    { uf: 'BA', name: 'Bahia' }, { uf: 'CE', name: 'Ceará' }, { uf: 'DF', name: 'Distrito Federal' }, { uf: 'ES', name: 'Espírito Santo' },
    { uf: 'GO', name: 'Goiás' }, { uf: 'MA', name: 'Maranhão' }, { uf: 'MT', name: 'Mato Grosso' }, { uf: 'MS', name: 'Mato Grosso do Sul' },
    { uf: 'MG', name: 'Minas Gerais' }, { uf: 'PA', name: 'Pará' }, { uf: 'PB', name: 'Paraíba' }, { uf: 'PR', name: 'Paraná' },
    { uf: 'PE', name: 'Pernambuco' }, { uf: 'PI', name: 'Piauí' }, { uf: 'RJ', name: 'Rio de Janeiro' }, { uf: 'RN', name: 'Rio Grande do Norte' },
    { uf: 'RS', name: 'Rio Grande do Sul' }, { uf: 'RO', name: 'Rondônia' }, { uf: 'RR', name: 'Roraima' }, { uf: 'SC', name: 'Santa Catarina' },
    { uf: 'SP', name: 'São Paulo' }, { uf: 'SE', name: 'Sergipe' }, { uf: 'TO', name: 'Tocantins' },
];

function unformat(value: string): string {
    return value ? value.replace(/\D/g, '') : '';
}

function formatCPF(value: string): string {
    const n = unformat(value).slice(0, 11);
    if (n.length <= 3) return n;
    if (n.length <= 6) return `${n.slice(0, 3)}.${n.slice(3)}`;
    if (n.length <= 9) return `${n.slice(0, 3)}.${n.slice(3, 6)}.${n.slice(6)}`;
    return `${n.slice(0, 3)}.${n.slice(3, 6)}.${n.slice(6, 9)}-${n.slice(9, 11)}`;
}

function formatPhone(value: string): string {
    const n = unformat(value).slice(0, 11);
    if (n.length <= 2) return n;
    if (n.length <= 6) return `(${n.slice(0, 2)}) ${n.slice(2)}`;
    if (n.length <= 10) return `(${n.slice(0, 2)}) ${n.slice(2, 6)}-${n.slice(6)}`;
    return `(${n.slice(0, 2)}) ${n.slice(2, 7)}-${n.slice(7, 11)}`;
}

function formatCep(value: string): string {
    const n = unformat(value).slice(0, 8);
    return n.length > 5 ? `${n.slice(0, 5)}-${n.slice(5)}` : n;
}

watch(() => form.cpf, (v) => { const f = formatCPF(v); if (f !== v) form.cpf = f; });
watch(() => form.telephone, (v) => { const f = formatPhone(v); if (f !== v) form.telephone = f; });
watch(() => form.cellphone, (v) => { const f = formatPhone(v); if (f !== v) form.cellphone = f; });

let cepTimer: ReturnType<typeof setTimeout> | null = null;
watch(() => form.address.zip_code, (v, old) => {
    const f = formatCep(v);
    if (f !== v) {
        form.address.zip_code = f;
        return;
    }
    if (unformat(f).length === 8 && unformat(old ?? '') !== unformat(f) && !pageLoading.value) {
        if (cepTimer) clearTimeout(cepTimer);
        cepTimer = setTimeout(() => searchCep(unformat(f)), 400);
    }
});

async function searchCep(cep: string) {
    cepLoading.value = true;
    try {
        // fetch, e não o axios do painel: o axios leva o token de sessão no
        // Authorization, e ele não tem por que sair para o ViaCEP.
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 5000);
        const data = await fetch(`https://viacep.com.br/ws/${cep}/json/`, { signal: controller.signal }).then((r) => r.json());
        clearTimeout(timer);
        if (data && !data.erro) {
            Object.assign(form.address, {
                address: (data.logradouro || '').toUpperCase(),
                district: (data.bairro || '').toUpperCase(),
                city: (data.localidade || '').toUpperCase(),
                state: data.uf || '',
                country: 'BRASIL',
            });
        } else {
            toast.warning('CEP não encontrado', { description: 'Confira o número ou preencha o endereço à mão.' });
        }
    } catch {
        // Sem internet ou ViaCEP fora: o usuário preenche à mão.
    } finally {
        cepLoading.value = false;
    }
}

function formatDateForInput(date: string): string {
    if (!date) return '';
    const d = new Date(date);
    return Number.isNaN(d.getTime()) ? date : d.toISOString().split('T')[0]!;
}

function loadUserData() {
    if (!user.value) return;
    const personal = user.value.personal || {};
    const contact = user.value.contact || {};
    const address = user.value.address || {};

    Object.assign(form, {
        name: personal.name || '',
        email: contact.email || '',
        cpf: personal.cpf || '',
        rg: personal.rg || '',
        birth_date: formatDateForInput(personal.birth_date),
        genre: personal.genre || '',
        telephone: contact.telephone || '',
        cellphone: contact.cellphone || '',
        nationality: personal.nationality || '',
        naturalness: personal.naturalness || '',
        marital_status: personal.marital_status || '',
    });
    Object.assign(form.address, {
        zip_code: address.zip_code || '',
        country: address.country || 'Brasil',
        state: String(address.state || '').trim().toUpperCase(),
        city: address.city || '',
        district: address.district || '',
        address: address.address || '',
        number: address.number || '',
        complement: address.complement || '',
    });
    usageNotices.value = user.value.notifications?.usage_notices !== false;
}

async function reloadProfile() {
    pageLoading.value = true;
    pageError.value = '';
    try {
        await authStore.fetchProfile();
        loadUserData();
    } catch (err) {
        pageError.value = errorMessage(err, 'Não foi possível carregar os dados do perfil.');
    } finally {
        pageLoading.value = false;
    }
}

onMounted(reloadProfile);

async function updateProfile() {
    updating.value = true;
    Object.keys(fieldErrors).forEach((k) => (fieldErrors[k] = ''));

    try {
        await authStore.updateProfile({
            name: form.name,
            cpf: unformat(form.cpf),
            rg: form.rg,
            birth_date: form.birth_date,
            genre: form.genre,
            telephone: unformat(form.telephone),
            cellphone: unformat(form.cellphone),
            nationality: form.nationality,
            naturalness: form.naturalness,
            marital_status: form.marital_status,
            address: { ...form.address, zip_code: unformat(form.address.zip_code) },
        });
        toast.success('Dados atualizados');
        await reloadProfile();
    } catch (err: any) {
        const errors = err?.response?.data?.errors;
        if (errors) {
            Object.keys(errors).forEach((field) => {
                if (field in fieldErrors) fieldErrors[field] = errors[field][0];
            });
            if (tab.value !== 'personal' && Object.keys(errors).some((f) => f in fieldErrors)) tab.value = 'personal';
            toast.error('Confira os campos destacados');
        } else {
            toast.error('Não foi possível salvar', { description: errorMessage(err) });
        }
    } finally {
        updating.value = false;
    }
}

async function saveNotifications() {
    savingNotices.value = true;
    try {
        const response = await axios.put('/dashboard/profile/notifications', { usage_notices: usageNotices.value });
        usageNotices.value = response.data?.data?.usage_notices !== false;
        toast.success(usageNotices.value ? 'Avisos de uso ligados' : 'Avisos de uso desligados');
    } catch (err) {
        toast.error('Não foi possível salvar', { description: errorMessage(err) });
    } finally {
        savingNotices.value = false;
    }
}

// O download passa pelo axios, e não por um link, porque a rota exige o token.
async function exportData() {
    exporting.value = true;
    try {
        const response = await axios.get('/dashboard/profile/export', { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `meus-dados-${new Date().toISOString().slice(0, 10)}.json`;
        link.click();
        URL.revokeObjectURL(url);
    } catch {
        toast.error('Não foi possível gerar o arquivo agora');
    } finally {
        exporting.value = false;
    }
}

async function deleteAccount() {
    const result = await prompt({
        title: 'Excluir sua conta?',
        description: 'A assinatura é encerrada sem estorno e as API keys deixam de funcionar. Digite sua senha para confirmar.',
        tone: 'danger',
        confirmText: 'Excluir definitivamente',
        input: { type: 'password', label: 'Sua senha' },
        onConfirm: async (password) => {
            try {
                await axios.delete('/dashboard/profile', { data: { password } });
            } catch (err: any) {
                throw new Error(err?.response?.status === 429 ? 'Muitas tentativas. Aguarde alguns minutos.' : errorMessage(err));
            }
        },
    });
    if (result === null) return;
    authStore.clearAuth();
    toast.success('Conta excluída');
    router.push('/');
}
</script>
