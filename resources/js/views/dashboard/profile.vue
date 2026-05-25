<template>
    <div class="space-y-6">
        <!-- Loading State -->
        <div v-if="pageLoading" class="flex items-center justify-center py-12">
            <div class="flex flex-col items-center">
                <div class="w-8 h-8 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
                <p class="mt-4 text-slate-500 dark:text-slate-400">Carregando perfil...</p>
            </div>
        </div>

        <!-- Error State -->
        <div v-else-if="pageError"
             class="p-6 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
            <div class="flex items-center gap-3">
                <PhWarning :size="24" class="text-red-500"/>
                <div>
                    <p class="font-medium text-red-800 dark:text-red-200">Erro ao carregar perfil</p>
                    <p class="text-sm text-red-600 dark:text-red-300">{{ pageError }}</p>
                    <button @click="reloadProfile"
                            class="mt-2 text-sm font-medium text-red-700 dark:text-red-300 hover:underline">
                        Tentar novamente
                    </button>
                </div>
            </div>
        </div>

        <!-- Profile Info -->
        <Card v-if="!pageLoading && !pageError" title="Informações Pessoais" subtitle="Atualize seus dados pessoais">
            <form @submit.prevent="updateProfile" class="space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <Input
                        v-model="form.name"
                        label="Nome completo"
                        required
                        :icon="PhUser"
                        :error="fieldErrors.name"
                    />

                    <Input
                        v-model="form.email"
                        label="Email"
                        type="email"
                        required
                        disabled
                        :icon="PhEnvelope"
                    />

                    <Input
                        v-model="form.cpf"
                        label="CPF"
                        placeholder="000.000.000-00"
                        required
                        :icon="PhIdentificationCard"
                        :error="fieldErrors.cpf"
                    />

                    <Input
                        v-model="form.rg"
                        label="RG"
                        :icon="PhIdentificationCard"
                        :error="fieldErrors.rg"
                    />

                    <Input
                        v-model="form.birth_date"
                        label="Data de nascimento"
                        type="date"
                        required
                        :icon="PhCalendar"
                        :error="fieldErrors.birth_date"
                    />

                    <div class="w-full">
                        <label
                            class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Gênero</label>
                        <select
                            v-model="form.genre"
                            class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                        >
                            <option value="">Selecione o gênero</option>
                            <option value="MASCULINE">Masculino</option>
                            <option value="FEMININE">Feminino</option>
                            <option value="OTHER">Outro</option>
                            <option value="NOT_INFORMED">Prefiro não informar</option>
                        </select>
                    </div>

                    <Input
                        v-model="form.telephone"
                        label="Telefone"
                        placeholder="(00) 0000-0000"
                        :icon="PhPhone"
                        :error="fieldErrors.telephone"
                    />

                    <Input
                        v-model="form.cellphone"
                        label="Celular"
                        placeholder="(00) 00000-0000"
                        :icon="PhDeviceMobile"
                        :error="fieldErrors.cellphone"
                    />

                    <Input
                        v-model="form.nationality"
                        label="Nacionalidade"
                        placeholder="Brasileiro(a)"
                        :icon="PhGlobe"
                    />

                    <Input
                        v-model="form.naturalness"
                        label="Naturalidade"
                        placeholder="Cidade/UF"
                        :icon="PhMapPin"
                    />

                    <div class="w-full">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Estado
                            civil</label>
                        <select
                            v-model="form.marital_status"
                            class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                        >
                            <option value="">Selecione</option>
                            <option value="SINGLE">Solteiro(a)</option>
                            <option value="MARRIED">Casado(a)</option>
                            <option value="WIDOWER">Viúvo(a)</option>
                            <option value="JUDICIALLY SEPARATED">Separado(a)</option>
                        </select>
                    </div>
                </div>

                <hr class="border-slate-200 dark:border-slate-700 my-6"/>

                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Endereço</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <Input
                        v-model="form.address.zip_code"
                        label="CEP"
                        placeholder="00000-000"
                        :icon="PhMapPin"
                        @input="onCepInput"
                    />

                    <Input
                        v-model="form.address.country"
                        label="País"
                        :icon="PhGlobe"
                    />

                    <div class="w-full">
                        <label
                            class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Estado</label>
                        <select
                            v-model="form.address.state"
                            class="w-full px-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                        >
                            <option value="">Selecione o estado</option>
                            <option v-for="state in brazilianStates" :key="state.uf" :value="state.uf">
                                {{ state.name }}
                            </option>
                        </select>
                    </div>

                    <Input
                        v-model="form.address.city"
                        label="Cidade"
                        :icon="PhBuildings"
                    />

                    <Input
                        v-model="form.address.district"
                        label="Bairro"
                        :icon="PhBuildings"
                    />

                    <Input
                        v-model="form.address.address"
                        label="Endereço"
                        :icon="PhHouse"
                    />

                    <Input
                        v-model="form.address.number"
                        label="Número"
                        :icon="PhHash"
                    />

                    <Input
                        v-model="form.address.complement"
                        label="Complemento"
                        placeholder="Apto, Bloco, etc."
                        :icon="PhNote"
                    />
                </div>

                <div class="flex items-center gap-4 mt-6">
                    <Button type="submit" variant="primary" :loading="updating">
                        Salvar alterações
                    </Button>
                    <span v-if="updateSuccess" class="text-emerald-600 dark:text-emerald-400 text-sm">
                        Dados atualizados com sucesso!
                    </span>
                </div>
            </form>
        </Card>

        <!-- API Key -->
        <Card title="API Key" subtitle="Use esta chave para autenticar suas requisições">
            <div class="space-y-4">
                <div class="relative">
                    <Input
                        v-model="apiKey"
                        label="Sua API Key"
                        readonly
                        class="font-mono text-sm"
                    >
                        <template #suffix>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    @click="copyApiKey"
                                    class="p-1.5 rounded hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors"
                                    :title="copied ? 'Copiado!' : 'Copiar'"
                                >
                                    <PhCheck v-if="copied" :size="18" class="text-emerald-500"/>
                                    <PhCopy v-else :size="18" class="text-slate-400"/>
                                </button>
                            </div>
                        </template>
                    </Input>
                </div>

                <div
                    class="flex items-center gap-4 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/30">
                    <PhWarning :size="24" class="text-amber-500 flex-shrink-0"/>
                    <p class="text-sm text-amber-800 dark:text-amber-200">
                        Mantenha sua API Key segura. Nunca compartilhe-a em código público.
                    </p>
                </div>

                <div class="flex items-center gap-4">
                    <Button variant="outline" @click="regenerateKey" :loading="regenerating">
                        <PhArrowsClockwise :size="18"/>
                        Regenerar API Key
                    </Button>
                    <span v-if="regenerateSuccess" class="text-emerald-600 dark:text-emerald-400 text-sm">
                        API Key regenerada com sucesso!
                    </span>
                </div>
            </div>
        </Card>

        <!-- Allowed Origins -->
        <Card title="Origens Permitidas" subtitle="Restrinja o acesso por IP ou domínio">
            <div class="space-y-4">
                <div v-if="allowedOrigins.length > 0" class="flex flex-wrap gap-2">
                    <span
                        v-for="(origin, index) in allowedOrigins"
                        :key="index"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-sm text-slate-700 dark:text-slate-300"
                    >
                        {{ origin }}
                        <button
                            @click="removeOrigin(index)"
                            class="text-slate-400 hover:text-red-500 transition-colors"
                        >
                            <PhX :size="14"/>
                        </button>
                    </span>
                </div>
                <p v-else class="text-sm text-slate-500 dark:text-slate-400">
                    Nenhuma origem restrita. Todas as origens são permitidas.
                </p>

                <div class="flex gap-3">
                    <Input
                        v-model="newOrigin"
                        placeholder="Ex: 192.168.1.1 ou *.meusite.com"
                        class="flex-1"
                    />
                    <Button variant="secondary" @click="addOrigin" :disabled="!newOrigin">
                        <PhPlus :size="18"/>
                        Adicionar
                    </Button>
                </div>

                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Deixe vazio para permitir todas as origens (não recomendado em produção).
                </p>
            </div>
        </Card>

    </div>
</template>

<script setup>
import {ref, reactive, computed, onMounted, watch} from 'vue';
import Card from '@/views/componentes/Card.vue';
import Input from '@/views/componentes/Input.vue';
import Button from '@/views/componentes/Button.vue';
import {useAuthStore} from '@/stores/auth';
import axios from 'axios';
import {
    PhUser,
    PhEnvelope,
    PhCopy,
    PhCheck,
    PhArrowsClockwise,
    PhWarning,
    PhPlus,
    PhX,
    PhLockKey,
    PhLockKeyOpen,
    PhIdentificationCard,
    PhCalendar,
    PhPhone,
    PhDeviceMobile,
    PhGlobe,
    PhMapPin,
    PhBuildings,
    PhHouse,
    PhHash,
    PhNote,
} from '@phosphor-icons/vue';

const authStore = useAuthStore();

const updating = ref(false);
const updateSuccess = ref(false);
const regenerating = ref(false);
const regenerateSuccess = ref(false);
const copied = ref(false);
const changingPassword = ref(false);
const newOrigin = ref('');
const pageLoading = ref(true);
const pageError = ref('');

const form = reactive({
    name: '',
    email: '',
    cpf: '',
    rg: '',
    birth_date: '',
    genre: '',
    telephone: '',
    cellphone: '',
    nationality: '',
    naturalness: '',
    marital_status: '',
    address: {
        zip_code: '',
        country: 'Brasil',
        state: '',
        city: '',
        district: '',
        address: '',
        number: '',
        complement: '',
    },
});

const brazilianStates = [
    {uf: 'AC', name: 'Acre'},
    {uf: 'AL', name: 'Alagoas'},
    {uf: 'AP', name: 'Amapá'},
    {uf: 'AM', name: 'Amazonas'},
    {uf: 'BA', name: 'Bahia'},
    {uf: 'CE', name: 'Ceará'},
    {uf: 'DF', name: 'Distrito Federal'},
    {uf: 'ES', name: 'Espírito Santo'},
    {uf: 'GO', name: 'Goiás'},
    {uf: 'MA', name: 'Maranhão'},
    {uf: 'MT', name: 'Mato Grosso'},
    {uf: 'MS', name: 'Mato Grosso do Sul'},
    {uf: 'MG', name: 'Minas Gerais'},
    {uf: 'PA', name: 'Pará'},
    {uf: 'PB', name: 'Paraíba'},
    {uf: 'PR', name: 'Paraná'},
    {uf: 'PE', name: 'Pernambuco'},
    {uf: 'PI', name: 'Piauí'},
    {uf: 'RJ', name: 'Rio de Janeiro'},
    {uf: 'RN', name: 'Rio Grande do Norte'},
    {uf: 'RS', name: 'Rio Grande do Sul'},
    {uf: 'RO', name: 'Rondônia'},
    {uf: 'RR', name: 'Roraima'},
    {uf: 'SC', name: 'Santa Catarina'},
    {uf: 'SP', name: 'São Paulo'},
    {uf: 'SE', name: 'Sergipe'},
    {uf: 'TO', name: 'Tocantins'},
];

let cepTimeout = null;

const allowedOrigins = ref([]);

const user = computed(() => authStore.user);

const onCepInput = () => {
    const cep = unformat(form.address.zip_code);
    if (cep.length === 8) {
        if (cepTimeout) clearTimeout(cepTimeout);
        cepTimeout = setTimeout(() => searchAddressByZipCode(cep), 500);
    }
}

const searchAddressByZipCode = async (cep) => {
    if (!cep || cep.length !== 8) return;
    try {
        const response = await axios.get(`https://viacep.com.br/ws/${cep}/json/`, {
            timeout: 5000,
            withCredentials: false,
        });
        if (response.data && !response.data.erro) {
            form.address.address = response.data.logradouro || '';
            form.address.district = response.data.bairro || '';
            form.address.city = response.data.localidade || '';
            form.address.state = response.data.uf || '';
            form.address.country = 'Brasil';
        }
    } catch (err) {
        console.error('Erro ao buscar CEP:', err);
    }
}


const apiKey = computed(() => {
    if (user.value?.api_key?.key) return user.value.api_key.key;
    if (user.value?.apiKey?.key) return user.value.apiKey.key;
    if (user.value?.api_key) return user.value.api_key;
    if (user.value?.token) return user.value.token;
    return '';
});

onMounted(async () => {
    await reloadProfile();
});

const reloadProfile = async () => {

    pageLoading.value = true;
    pageError.value = '';
    try {
        await authStore.fetchProfile();
        loadUserData();

        const origins = await authStore.fetchAllowedOrigins();
        allowedOrigins.value = Array.isArray(origins) ? origins : [];
    } catch (err) {
        console.error('Erro ao carregar perfil:', err);
        pageError.value = err.response?.data?.message || 'Não foi possível carregar os dados do perfil';
    } finally {
        pageLoading.value = false;
    }
}

const loadUserData= () => {
    if (!user.value) return;

    // Estrutura aninhada do ProfileResource
    const personal = user.value.personal || {};
    const contact = user.value.contact || {};
    const address = user.value.address || {};

    form.name = personal.name || '';
    form.email = contact.email || '';
    form.cpf = personal.cpf || '';
    form.rg = personal.rg || '';
    form.birth_date = formatDateForInput(personal.birth_date);
    form.genre = personal.genre || '';
    form.telephone = contact.telephone || '';
    form.cellphone = contact.cellphone || '';
    form.nationality = personal.nationality || '';
    form.naturalness = personal.naturalness || '';
    form.marital_status = personal.marital_status || '';

    // Carrega dados do endereco
    form.address.zip_code = address.zip_code || '';
    form.address.country = address.country || 'Brasil';
    form.address.state = (address.state || '').toString().trim().toUpperCase();
    form.address.city = address.city || '';
    form.address.district = address.district || '';
    form.address.address = address.address || '';
    form.address.number = address.number || '';
    form.address.complement = address.complement || '';
}

function formatDateForInput(date) {
    if (!date) return '';
    // Converte para formato yyyy-mm-dd para input type="date"
    try {
        const d = new Date(date);
        return d.toISOString().split('T')[0];
    } catch {
        return date;
    }
}

// Erros de validação por campo
const fieldErrors = reactive({
    name: '',
    cpf: '',
    rg: '',
    birth_date: '',
    cellphone: '',
    telephone: '',
});

// Funções de máscara
function formatCPF(value) {
    if (!value) return '';
    const numbers = value.replace(/\D/g, '').slice(0, 11);
    if (numbers.length <= 3) return numbers;
    if (numbers.length <= 6) return `${numbers.slice(0, 3)}.${numbers.slice(3)}`;
    if (numbers.length <= 9) return `${numbers.slice(0, 3)}.${numbers.slice(3, 6)}.${numbers.slice(6)}`;
    return `${numbers.slice(0, 3)}.${numbers.slice(3, 6)}.${numbers.slice(6, 9)}-${numbers.slice(9, 11)}`;
}

function formatPhone(value) {
    if (!value) return '';
    const numbers = value.replace(/\D/g, '').slice(0, 11);
    if (numbers.length <= 2) return numbers;
    if (numbers.length <= 6) return `(${numbers.slice(0, 2)}) ${numbers.slice(2)}`;
    if (numbers.length <= 10) return `(${numbers.slice(0, 2)}) ${numbers.slice(2, 6)}-${numbers.slice(6)}`;
    return `(${numbers.slice(0, 2)}) ${numbers.slice(2, 7)}-${numbers.slice(7, 11)}`;
}

function unformat(value) {
    return value ? value.replace(/\D/g, '') : '';
}

// Watchers para aplicar máscaras

async function updateProfile() {
    updating.value = true;
    // Limpa erros anteriores
    Object.keys(fieldErrors).forEach(key => fieldErrors[key] = '');

    try {
        // Envia dados sem formatacao, incluindo endereco
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
            address: {
                zip_code: unformat(form.address.zip_code),
                country: form.address.country,
                state: form.address.state,
                city: form.address.city,
                district: form.address.district,
                address: form.address.address,
                number: form.address.number,
                complement: form.address.complement,
            },
        });
        updateSuccess.value = true;
        setTimeout(() => updateSuccess.value = false, 3000);

        // Recarrega os dados do perfil para atualizar a tela
        await reloadProfile();
    } catch (err) {
        console.error('Erro ao atualizar perfil:', err);
        // Trata erros de validacao
        if (err.response?.data?.errors) {
            const errors = err.response.data.errors;
            Object.keys(errors).forEach(field => {
                if (fieldErrors.hasOwnProperty(field)) {
                    fieldErrors[field] = errors[field][0];
                }
            });
        } else {
            alert(err.response?.data?.message || 'Erro ao atualizar perfil');
        }
    } finally {
        updating.value = false;
    }
}

async function copyApiKey() {
    if (!apiKey.value) return;
    try {
        await navigator.clipboard.writeText(apiKey.value);
        copied.value = true;
        setTimeout(() => copied.value = false, 2000);
    } catch (err) {
        console.error('Failed to copy:', err);
    }
}

async function regenerateKey() {
    regenerating.value = true;
    try {
        const response = await axios.post('/dashboard/profile/regenerate-key');
        if (response.data?.data?.key) {
            // Atualiza o usuário com a nova API key
            await authStore.fetchProfile();
            regenerateSuccess.value = true;
            setTimeout(() => regenerateSuccess.value = false, 3000);
        }
    } catch (err) {
        console.error('Erro ao regenerar API key:', err);
        alert(err.response?.data?.message || 'Erro ao regenerar API Key');
    } finally {
        regenerating.value = false;
    }
}

async function addOrigin() {
    console.log(newOrigin.value)
    if (!newOrigin.value) return;

    if (!Array.isArray(allowedOrigins.value)) {
        allowedOrigins.value = [];
    }

    allowedOrigins.value.push(newOrigin.value);
    newOrigin.value = '';
    await authStore.updateAllowedOrigins(allowedOrigins.value);
}

async function removeOrigin(index) {
    allowedOrigins.value.splice(index, 1);
    await authStore.updateAllowedOrigins(allowedOrigins.value);
}

watch(() => form.cpf, (newVal) => {
    const formatted = formatCPF(newVal);
    if (formatted !== newVal) form.cpf = formatted;
});

watch(() => form.telephone, (newVal) => {
    const formatted = formatPhone(newVal);
    if (formatted !== newVal) form.telephone = formatted;
});

watch(() => form.cellphone, (newVal) => {
    const formatted = formatPhone(newVal);
    if (formatted !== newVal) form.cellphone = formatted;
});


watch(user, (newUser) => {
    if (newUser) loadUserData();
}, {immediate: true});
</script>
