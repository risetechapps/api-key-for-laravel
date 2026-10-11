<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
        <!-- Fundo: brilho suave na cor da marca -->
        <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 -z-0 h-80 bg-[radial-gradient(60%_100%_at_50%_0%,color-mix(in_oklab,var(--brand)_10%,transparent),transparent)] dark:bg-[radial-gradient(60%_100%_at_50%_0%,color-mix(in_oklab,var(--brand)_16%,transparent),transparent)]" />

        <div class="relative flex">
            <!-- Menu lateral (desktop) -->
            <aside :class="['hidden lg:block shrink-0 sticky top-0 h-screen border-r border-slate-200/80 dark:border-slate-800 bg-white/80 dark:bg-slate-900/60 backdrop-blur transition-[width] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]', collapsed ? 'w-[76px]' : 'w-72']">
                <Sidebar :collapsed="collapsed" @toggle-collapse="toggleCollapse" />
            </aside>

            <!-- Menu lateral (celular) -->
            <TransitionRoot :show="mobileOpen" as="template">
                <Dialog as="div" class="relative z-50 lg:hidden" @close="mobileOpen = false">
                    <TransitionChild as="template" enter="duration-300 ease-out" enter-from="opacity-0" enter-to="opacity-100" leave="duration-200 ease-in" leave-from="opacity-100" leave-to="opacity-0">
                        <div class="fixed inset-0 bg-slate-950/50 backdrop-blur-[2px]" />
                    </TransitionChild>
                    <TransitionChild as="template" enter="transform transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" enter-from="-translate-x-full" enter-to="translate-x-0" leave="transform transition duration-200 ease-in" leave-from="translate-x-0" leave-to="-translate-x-full">
                        <DialogPanel class="fixed inset-y-0 left-0 w-80 max-w-[85vw] bg-white dark:bg-slate-900 shadow-2xl">
                            <Sidebar mobile @close="mobileOpen = false" @navigate="mobileOpen = false" />
                        </DialogPanel>
                    </TransitionChild>
                </Dialog>
            </TransitionRoot>

            <div class="flex min-w-0 flex-1 flex-col">
                <Header @toggle-sidebar="mobileOpen = true" />

                <main class="flex-1 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        <router-view v-slot="{ Component, route: current }">
                            <Transition name="ui-page" mode="out-in">
                                <component :is="Component" :key="current.path" />
                            </Transition>
                        </router-view>
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Dialog, DialogPanel, TransitionRoot, TransitionChild } from '@headlessui/vue';
import Sidebar from '@/views/dashboard/layout/Sidebar.vue';
import Header from '@/views/dashboard/layout/Header.vue';
import { useAuthStore } from '@/stores/auth';
import { useNavigation } from '@/navigation';
import { registerCommands } from '@/ui';
import appConfig from '@/api-key.config';
import { toggleTheme } from '@/theme';
import { PhMoonStars } from '@phosphor-icons/vue';

const authStore = useAuthStore();
const mobileOpen = ref(false);

const COLLAPSE_KEY = 'api-key:sidebar-collapsed';
const collapsed = ref(readCollapsed());

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
        return false;
    }
}

function toggleCollapse() {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem(COLLAPSE_KEY, collapsed.value ? '1' : '0');
    } catch {
        // sem storage: vale só nesta visita
    }
}

// Busca rápida: páginas do menu (respeitando permissões), ações gerais e
// comandos dos módulos do app (api-key.config.ts → commands).
const { sections } = useNavigation();
let unregister: (() => void) | null = null;

watch(sections, (list) => {
    unregister?.();
    unregister = registerCommands([
        ...list.flatMap((s) => s.items.map((item) => ({
            id: `nav:${item.to}`,
            label: item.name,
            group: s.title ?? 'Páginas',
            icon: item.icon,
            to: item.to,
        }))),
        { id: 'action:theme', label: 'Alternar tema claro/escuro', group: 'Ações', icon: PhMoonStars, run: toggleTheme },
        ...(appConfig.commands ?? []),
    ]);
}, { immediate: true });

onMounted(async () => {
    await authStore.initializeAuth();
});
</script>
