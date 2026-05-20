<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Planos</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Gerencie os planos disponíveis</p>
            </div>
            <Button variant="primary" @click="openModal()">
                <PhPlus :size="18" /> Novo Plano
            </Button>
        </div>

        <Card>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Nome</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Preço</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Ciclo</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Limite</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Features</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Status</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="loading" v-for="i in 4" :key="i">
                            <td v-for="j in 7" :key="j" class="py-3 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>
                        <tr v-else-if="!plans.length">
                            <td colspan="7" class="py-10 text-center text-slate-500 dark:text-slate-400">Nenhum plano cadastrado</td>
                        </tr>
                        <tr v-else v-for="plan in plans" :key="plan.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="py-3 px-4 font-medium text-slate-900 dark:text-white">{{ plan.name }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300">{{ plan.price }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300 capitalize">{{ cycleLabel(plan.billing_cycle) }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300">{{ plan.request_limit?.toLocaleString('pt-BR') }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300">{{ plan.features?.length ?? 0 }} features</td>
                            <td class="py-3 px-4">
                                <span :class="plan.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'"
                                      class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium">
                                    {{ plan.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2 justify-end">
                                    <button @click="openModal(plan)" class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors">
                                        <PhPencil :size="16" />
                                    </button>
                                    <button @click="remove(plan)" class="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                        <PhTrash :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Modal -->
        <Modal v-model="showModal" :title="editing ? 'Editar Plano' : 'Novo Plano'">
            <form @submit.prevent="save" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <!-- Nome -->
                    <div class="col-span-2">
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Nome</label>
                        <input v-model="form.name" type="text" required
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Descrição -->
                    <div class="col-span-2">
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Descrição</label>
                        <textarea v-model="form.description" rows="2"
                                  class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>

                    <!-- Preço -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Preço (R$)</label>
                        <input v-model="form.price" type="number" step="0.01" min="0" required
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Ciclo -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Ciclo de cobrança</label>
                        <select v-model="form.billing_cycle" required
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="monthly">Mensal</option>
                            <option value="annually">Anual</option>
                            <option value="weekly">Semanal</option>
                        </select>
                    </div>

                    <!-- Limite -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Limite de requisições</label>
                        <input v-model="form.request_limit" type="number" min="0" required
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Ativo -->
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-indigo-600" />
                            <span class="text-sm text-slate-700 dark:text-slate-300">Plano ativo</span>
                        </label>
                    </div>

                    <!-- Features -->
                    <div class="col-span-2">
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 block">
                            Features <span class="text-red-500">*</span>
                        </label>
                        <p v-if="!availableFeatures.length" class="text-xs text-slate-400 italic">
                            Nenhuma feature registrada. Use <code>FeatureRegistry::register()</code> no seu AppServiceProvider.
                        </p>
                        <div v-else class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <label
                                v-for="f in availableFeatures"
                                :key="f.key"
                                class="flex items-center gap-2 cursor-pointer p-2 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                                :title="f.description ?? ''"
                            >
                                <input type="checkbox" :value="f.key" v-model="form.features" class="w-4 h-4 rounded text-indigo-600 accent-indigo-600" />
                                <span class="text-xs text-slate-700 dark:text-slate-300">{{ f.name }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Features Description -->
                    <div class="col-span-2">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider block">
                                Descrição das features <span class="text-red-500">*</span>
                            </label>
                            <button type="button" @click="addDescription"
                                    class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors">
                                + Adicionar linha
                            </button>
                        </div>
                        <div class="space-y-2">
                            <div v-for="(_, i) in form.features_description" :key="i" class="flex gap-2 items-center">
                                <input v-model="form.features_description[i]" type="text"
                                       placeholder="Ex: 1000 requisições/mês"
                                       class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                                <button type="button" @click="removeDescription(i)"
                                        class="p-2 text-slate-400 hover:text-red-500 transition-colors shrink-0">
                                    <PhTrash :size="14" />
                                </button>
                            </div>
                            <p v-if="form.features_description.length === 0" class="text-xs text-slate-400">
                                Adicione ao menos uma descrição.
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="formError || fieldErrors.length" class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-400 space-y-1">
                    <p v-if="formError">{{ formError }}</p>
                    <ul v-if="fieldErrors.length" class="list-disc list-inside space-y-0.5">
                        <li v-for="(msg, i) in fieldErrors" :key="i">{{ msg }}</li>
                    </ul>
                </div>

                <Button type="submit" variant="primary" class="w-full" :loading="saving">
                    {{ editing ? 'Salvar alterações' : 'Criar plano' }}
                </Button>
            </form>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { PhPlus, PhPencil, PhTrash } from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import { useAdminStore } from '@/stores/admin';
import Card from '@/views/componentes/Card.vue';
import Button from '@/views/componentes/Button.vue';
import Modal from '@/views/componentes/Modal.vue';

const adminStore = useAdminStore();
const loading          = computed(() => adminStore.loading);
const plans            = computed(() => adminStore.plans);
const availableFeatures = computed(() => adminStore.features);

const showModal   = ref(false);
const editing     = ref(null);
const saving      = ref(false);
const formError   = ref('');
const fieldErrors = ref([]);

const emptyForm = () => ({
    name: '',
    description: '',
    price: 0,
    billing_cycle: 'monthly',
    request_limit: 1000,
    is_active: true,
    features: [],
    features_description: [''],
});

const form = ref(emptyForm());

onMounted(() => {
    adminStore.fetchPlans();
    adminStore.fetchFeatures();
});

function openModal(plan = null) {
    editing.value = plan;
    form.value = plan
        ? {
            name: plan.name,
            description: plan.description ?? '',
            price: plan.raw_price ?? 0,
            billing_cycle: plan.billing_cycle ?? 'monthly',
            request_limit: plan.request_limit ?? 0,
            is_active: plan.is_active,
            features: Array.isArray(plan.features)
                ? plan.features.map(f => (f && typeof f === 'object') ? f.key : f).filter(Boolean)
                : [],
            features_description: Array.isArray(plan.features_description) && plan.features_description.length
                ? [...plan.features_description]
                : [''],
          }
        : emptyForm();
    formError.value   = '';
    fieldErrors.value = [];
    showModal.value   = true;
}

function addDescription() {
    form.value.features_description.push('');
}

function removeDescription(index) {
    form.value.features_description.splice(index, 1);
}

async function save() {
    if (availableFeatures.value.length && !form.value.features.length) {
        formError.value = 'Selecione ao menos uma feature.';
        return;
    }
    const descs = form.value.features_description.filter(d => d.trim());
    if (!descs.length) {
        formError.value = 'Adicione ao menos uma descrição de feature.';
        return;
    }

    saving.value      = true;
    formError.value   = '';
    fieldErrors.value = [];
    try {
        const payload = { ...form.value, features_description: descs };
        if (editing.value) {
            await adminStore.updatePlan(editing.value.id, payload);
        } else {
            await adminStore.createPlan(payload);
        }
        showModal.value = false;
    } catch (err) {
        const data = err?.response?.data;
        const errors = data?.errors ?? {};
        const flat = Object.values(errors).flat();
        if (flat.length) {
            fieldErrors.value = flat;
        } else {
            formError.value = data?.message || 'Erro ao salvar plano.';
        }
    } finally {
        saving.value = false;
    }
}

async function remove(plan) {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Excluir plano?',
        html: `O plano <strong>${plan.name}</strong> será excluído permanentemente.`,
        showCancelButton: true,
        confirmButtonText: 'Sim, excluir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    });
    if (result.isConfirmed) {
        await adminStore.deletePlan(plan.id);
    }
}

function cycleLabel(cycle) {
    return { monthly: 'Mensal', annually: 'Anual', weekly: 'Semanal' }[cycle] ?? cycle;
}
</script>
