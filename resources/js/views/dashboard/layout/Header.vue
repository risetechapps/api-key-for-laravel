<template>
    <header class="sticky top-0 z-30 glass border-b border-slate-200/70 dark:border-slate-800/80">
        <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
            <button type="button" class="lg:hidden -ml-1 p-2 rounded-xl text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="Abrir menu" @click="$emit('toggle-sidebar')">
                <PhList :size="22" />
            </button>

            <!-- Busca rápida -->
            <button
                type="button"
                class="group flex h-10 flex-1 max-w-md items-center gap-2.5 rounded-xl border border-slate-200 bg-white/70 px-3 text-sm text-slate-400 shadow-xs transition-colors hover:border-slate-300 hover:text-slate-600 dark:border-slate-700 dark:bg-slate-900/70 dark:hover:border-slate-600 dark:hover:text-slate-300"
                @click="openCommandPalette"
            >
                <PhMagnifyingGlass :size="16" />
                <span class="flex-1 text-left truncate">Buscar páginas e ações…</span>
                <kbd class="hidden sm:inline-flex items-center gap-0.5 rounded-md border border-slate-200 dark:border-slate-700 px-1.5 py-0.5 text-[10px] font-medium">{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
            </button>

            <div class="ml-auto flex items-center gap-1.5">
                <Tooltip :text="isDark ? 'Tema claro' : 'Tema escuro'" position="bottom">
                    <button type="button" class="relative h-10 w-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white transition-colors" :aria-label="isDark ? 'Ativar tema claro' : 'Ativar tema escuro'" @click="toggleTheme">
                        <Transition name="ui-fade" mode="out-in">
                            <PhSun v-if="isDark" key="sun" :size="19" weight="duotone" class="text-amber-400" />
                            <PhMoon v-else key="moon" :size="19" weight="duotone" />
                        </Transition>
                    </button>
                </Tooltip>

                <Dropdown :items="menu">
                    <template #trigger>
                        <button type="button" class="flex items-center gap-2.5 rounded-xl p-1 pr-2 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" aria-label="Menu da conta">
                            <Avatar :name="displayName" size="sm" />
                            <span class="hidden md:block text-left leading-tight">
                                <span class="block max-w-36 truncate text-sm font-semibold text-slate-900 dark:text-white">{{ displayName }}</span>
                                <span class="block max-w-36 truncate text-xs text-slate-500 dark:text-slate-400">{{ email }}</span>
                            </span>
                            <PhCaretDown :size="14" class="hidden md:block text-slate-400" />
                        </button>
                    </template>
                </Dropdown>
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { PhList, PhSun, PhMoon, PhCaretDown, PhMagnifyingGlass, PhUser, PhShieldCheck, PhCreditCard, PhSignOut } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import { isDark, toggleTheme } from '@/theme';
import { Avatar, Dropdown, Tooltip, openCommandPalette } from '@/ui';
import type { DropdownItem } from '@/ui';

defineEmits<{ 'toggle-sidebar': [] }>();

const router = useRouter();
const authStore = useAuthStore();

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

const user = computed<any>(() => authStore.user);
const displayName = computed(() => user.value?.personal?.name || user.value?.name || '');
const email = computed(() => user.value?.contact?.email || user.value?.email || '');

async function logout() {
    try {
        await authStore.logout();
    } finally {
        router.push('/login');
    }
}

const menu = computed<DropdownItem[]>(() => [
    { label: 'Perfil', icon: PhUser, to: '/dashboard/profile' },
    { label: 'Segurança', icon: PhShieldCheck, to: '/dashboard/security' },
    { label: 'Faturamento', icon: PhCreditCard, to: '/dashboard/billing' },
    { divider: true },
    { label: 'Sair', icon: PhSignOut, danger: true, onClick: logout },
]);
</script>
