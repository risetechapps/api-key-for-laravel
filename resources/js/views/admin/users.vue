<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Usuários</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Lista de usuários e assinaturas</p>
        </div>

        <!-- Search -->
        <Card>
            <div class="flex gap-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar por nome ou e-mail..."
                    class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    @input="debouncedFetch"
                />
            </div>
        </Card>

        <Card>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Usuário</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Role</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Plano Ativo</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Vencimento</th>
                            <th class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-3 px-4">Cadastro</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="loading" v-for="i in 5" :key="i">
                            <td v-for="j in 5" :key="j" class="py-3 px-4">
                                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse"></div>
                            </td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td colspan="5" class="py-10 text-center text-slate-500 dark:text-slate-400">Nenhum usuário encontrado</td>
                        </tr>
                        <tr v-else v-for="user in rows" :key="user.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-xs font-bold text-indigo-600 dark:text-indigo-400">
                                        {{ initials(user.name) }}
                                    </div>
                                    <div>
                                        <p class="font-medium text-slate-900 dark:text-white text-sm">{{ user.name }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span :class="user.role === 'admin' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'"
                                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize">
                                    {{ user.role === 'admin' ? 'Admin' : 'Usuário' }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span v-if="user.active_plan" class="text-sm font-medium text-slate-900 dark:text-white">{{ user.active_plan.name }}</span>
                                <span v-else class="text-sm text-slate-400">Sem plano</span>
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-700 dark:text-slate-300">
                                {{ user.active_plan?.end_date ? formatDate(user.active_plan.end_date) : '—' }}
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-700 dark:text-slate-300">
                                {{ user.created_at ? formatDate(user.created_at) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ total }} usuários</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="page <= 1" @click="changePage(page - 1)">Anterior</Button>
                    <Button variant="outline" size="sm" :disabled="page >= totalPages" @click="changePage(page + 1)">Próximo</Button>
                </div>
            </div>
        </Card>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { format, parseISO, isValid } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { useAdminStore } from '@/stores/admin';
import Card from '@/views/componentes/Card.vue';
import Button from '@/views/componentes/Button.vue';

const adminStore = useAdminStore();
const loading = computed(() => adminStore.loading);

const search = ref('');
const page   = ref(1);
let debounceTimer: any = null;

const usersData    = computed(() => adminStore.users);
const rows         = computed(() => usersData.value?.data ?? []);
const total        = computed(() => usersData.value?.total ?? 0);
const totalPages   = computed(() => usersData.value?.last_page ?? 1);

onMounted(() => fetchUsers());

function fetchUsers() {
    adminStore.fetchUsers({ search: search.value, page: page.value });
}

function debouncedFetch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { page.value = 1; fetchUsers(); }, 400);
}

function changePage(p: number) {
    page.value = p;
    fetchUsers();
}

function initials(name: string) {
    return name?.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) ?? '?';
}

function formatDate(date: string) {
    if (!date) return '—';
    try {
        const d = typeof date === 'string' ? parseISO(date) : new Date(date);
        return isValid(d) ? format(d, 'dd/MM/yyyy', { locale: ptBR }) : '—';
    } catch {
        return '—';
    }
}
</script>
