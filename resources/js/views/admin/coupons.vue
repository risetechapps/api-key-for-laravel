<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Cupons</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Gerencie os cupons de desconto</p>
            </div>
            <Button variant="primary" @click="openModal()">
                <PhPlus :size="18" /> Novo Cupom
            </Button>
        </div>

        <Card>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Código</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Tipo</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Valor</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Usos</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Validade</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Status</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="loading" v-for="i in 3" :key="i">
                            <td v-for="j in 7" :key="j" class="py-3 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>
                        <tr v-else-if="!coupons.length">
                            <td colspan="7" class="py-10 text-center text-slate-500 dark:text-slate-400">Nenhum cupom cadastrado</td>
                        </tr>
                        <tr v-else v-for="coupon in coupons" :key="coupon.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="py-3 px-4 font-mono font-semibold text-slate-900 dark:text-white">{{ coupon.code }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300 capitalize">{{ coupon.type === 'percentage' ? 'Percentual' : 'Fixo' }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300">{{ coupon.type === 'percentage' ? `${coupon.value}%` : `R$ ${Number(coupon.value).toFixed(2).replace('.', ',')}` }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300">{{ coupon.uses ?? 0 }} / {{ coupon.max_uses }}</td>
                            <td class="py-3 px-4 text-slate-700 dark:text-slate-300">{{ coupon.expires_at ? formatDate(coupon.expires_at) : 'Sem validade' }}</td>
                            <td class="py-3 px-4">
                                <span :class="coupon.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'"
                                      class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium">
                                    {{ coupon.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2 justify-end">
                                    <button @click="openModal(coupon)" class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors">
                                        <PhPencil :size="16" />
                                    </button>
                                    <button @click="remove(coupon)" class="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
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
        <Modal v-model="showModal" :title="editing ? 'Editar Cupom' : 'Novo Cupom'">
            <form @submit.prevent="save" class="space-y-4">
                <!-- Código -->
                <div>
                    <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Código</label>
                    <div class="flex gap-2">
                        <input v-model="form.code" type="text" required :disabled="!!editing"
                               class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm uppercase font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-60" />
                        <button
                            v-if="!editing"
                            type="button"
                            @click="generateCode"
                            class="px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-indigo-50 dark:hover:bg-indigo-900/20 hover:text-indigo-600 dark:hover:text-indigo-400 hover:border-indigo-300 dark:hover:border-indigo-700 transition-colors whitespace-nowrap"
                            title="Gerar código aleatório"
                        >
                            <PhArrowsClockwise :size="16" />
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- Tipo -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Tipo</label>
                        <select v-model="form.type" required
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="percentage">Percentual (%)</option>
                            <option value="fixed">Fixo (R$)</option>
                        </select>
                    </div>

                    <!-- Valor -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Valor</label>
                        <input v-model="form.value" type="number" step="0.01" min="0" required
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Máximo de usos -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Máximo de usos</label>
                        <input v-model="form.max_uses" type="number" min="1" required
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    <!-- Validade -->
                    <div>
                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5 block">Validade <span class="normal-case font-normal text-slate-400">(opcional)</span></label>
                        <input v-model="form.expires_at" type="date"
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>
                </div>

                <!-- Ativo -->
                <label class="flex items-center gap-2 cursor-pointer">
                    <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-indigo-600 accent-indigo-600" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">Cupom ativo</span>
                </label>

                <div v-if="formError" class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-400">
                    {{ formError }}
                </div>

                <Button type="submit" variant="primary" class="w-full" :loading="saving">
                    {{ editing ? 'Salvar alterações' : 'Criar cupom' }}
                </Button>
            </form>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { format, parseISO, isValid } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { PhPlus, PhPencil, PhTrash, PhArrowsClockwise } from '@phosphor-icons/vue';
import Swal from 'sweetalert2';
import { useAdminStore } from '@/stores/admin';
import Card from '@/views/componentes/Card.vue';
import Button from '@/views/componentes/Button.vue';
import Modal from '@/views/componentes/Modal.vue';

const adminStore = useAdminStore();
const loading  = computed(() => adminStore.loading);
const coupons  = computed(() => adminStore.coupons);

const showModal = ref(false);
const editing   = ref(null);
const saving    = ref(false);
const formError = ref('');

const emptyForm = () => ({ code: '', type: 'percentage', value: 10, max_uses: 1, expires_at: '', is_active: true });
const form = ref(emptyForm());

onMounted(() => adminStore.fetchCoupons());

function generateCode() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    form.value.code = Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

function openModal(coupon = null) {
    editing.value = coupon;
    form.value = coupon
        ? { code: coupon.code, type: coupon.type, value: coupon.value, max_uses: coupon.max_uses, expires_at: coupon.expires_at?.substring(0, 10) ?? '', is_active: coupon.is_active }
        : emptyForm();
    formError.value = '';
    showModal.value = true;
}

async function save() {
    saving.value = true;
    formError.value = '';
    try {
        const payload = { ...form.value, expires_at: form.value.expires_at || null };
        if (editing.value) {
            await adminStore.updateCoupon(editing.value.id, payload);
        } else {
            await adminStore.createCoupon(payload);
        }
        showModal.value = false;
    } catch (err) {
        formError.value = err?.response?.data?.message || 'Erro ao salvar cupom.';
    } finally {
        saving.value = false;
    }
}

async function remove(coupon) {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Excluir cupom?',
        html: `O cupom <strong>${coupon.code}</strong> será excluído.`,
        showCancelButton: true,
        confirmButtonText: 'Sim, excluir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    });
    if (result.isConfirmed) await adminStore.deleteCoupon(coupon.id);
}

function formatDate(date) {
    if (!date) return '—';
    try {
        const d = typeof date === 'string' ? parseISO(date) : new Date(date);
        return isValid(d) ? format(d, 'dd/MM/yyyy', { locale: ptBR }) : '—';
    } catch {
        return '—';
    }
}
</script>
