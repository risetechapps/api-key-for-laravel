<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-900">
        <!-- Navigation -->
        <nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border-b
         border-slate-200 dark:border-slate-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <!-- Logo -->
                    <router-link to="/" class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex
                            items-center justify-center shadow-lg shadow-indigo-500/30">
                            <PhHexagon :size="20" weight="fill" class="text-white" />
                        </div>
                        <span class="text-lg font-bold bg-gradient-to-r from-indigo-600 to-purple-600
                            bg-clip-text text-transparent">
                            Orchestrator
                        </span>
                    </router-link>

                    <!-- Desktop Navigation -->
                    <div class="hidden md:flex items-center gap-8">
                        <a
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            @click.prevent="navigateToSection(item.href)"
                            class="text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-indigo-600
                                dark:hover:text-indigo-400 transition-colors cursor-pointer">
                            {{ item.name }}
                        </a>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3">
                        <button
                            @click="toggleDarkMode"
                            class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            :title="isDark ? 'Modo claro' : 'Modo escuro'">
                            <PhSun v-if="isDark" :size="20" class="text-amber-500" />
                            <PhMoon v-else :size="20" class="text-slate-600" />
                        </button>

                        <router-link
                            v-if="!authStore.isAuthenticated"
                            to="/login"
                            class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-medium
                                text-slate-700 dark:text-slate-200 hover:text-indigo-600
                                dark:hover:text-indigo-400 transition-colors">
                            Entrar
                        </router-link>

                        <router-link
                            v-if="!authStore.isAuthenticated"
                            to="/register"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white
                                bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-colors shadow-lg
                                shadow-indigo-500/30">
                            Começar Grátis
                        </router-link>

                        <router-link
                            v-else
                            to="/dashboard"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white
                            bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-colors shadow-lg shadow-indigo-500/30"
                        >
                            Dashboard
                        </router-link>

                        <!-- Mobile menu button -->
                        <button
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            class="md:hidden p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            <PhList v-if="!mobileMenuOpen" :size="24" class="text-slate-600 dark:text-slate-400" />
                            <PhX v-else :size="24" class="text-slate-600 dark:text-slate-400" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile menu -->
            <Transition
                enter="transition ease-out duration-200"
                enter-from="opacity-0 -translate-y-4"
                enter-to="opacity-100 translate-y-0"
                leave="transition ease-in duration-150"
                leave-from="opacity-100 translate-y-0"
                leave-to="opacity-0 -translate-y-4">
                <div
                    v-if="mobileMenuOpen"
                    class="md:hidden bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800">
                    <div class="px-4 py-4 space-y-3">
                        <a
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            @click.prevent="navigateToSection(item.href)"
                            class="block text-base font-medium text-slate-600 dark:text-slate-300
                                hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors cursor-pointer">
                            {{ item.name }}
                        </a>
                        <hr class="border-slate-200 dark:border-slate-700" />
                        <router-link
                            v-if="!authStore.isAuthenticated"
                            to="/login"
                            class="block text-base font-medium text-slate-600 dark:text-slate-300"
                        >
                            Entrar
                        </router-link>
                    </div>
                </div>
            </Transition>
        </nav>

        <!-- Main content -->
        <main class="pt-16">
            <router-view />
        </main>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { PhHexagon, PhSun, PhMoon, PhList, PhX } from '@phosphor-icons/vue';

const authStore = useAuthStore();
const route = useRoute();
const router = useRouter();

const isDark = ref(false);
const mobileMenuOpen = ref(false);

const navigation = [
    { name: 'Recursos', href: '#features' },
    { name: 'Demo', href: '#demo' },
    { name: 'Preços', href: '#pricing' },
    { name: 'Docs', href: '#docs' },
];

function navigateToSection(href) {
    const isHomePage = route.path === '/';

    if (isHomePage) {
        // Se já está na home, apenas rola até a seção
        const element = document.querySelector(href);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth' });
        }
    } else {
        // Se está em outra página, navega para home com o hash
        router.push({ path: '/', hash: href });
    }

    mobileMenuOpen.value = false;
}

onMounted(() => {
    const savedDarkMode = localStorage.getItem('darkMode');
    if (savedDarkMode === 'true') {
        document.documentElement.classList.add('dark');
        isDark.value = true;
    } else if (savedDarkMode === 'false') {
        document.documentElement.classList.remove('dark');
        isDark.value = false;
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark');
        isDark.value = true;
    }
});

function toggleDarkMode() {
    isDark.value = !isDark.value;
    if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('darkMode', 'true');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('darkMode', 'false');
    }
}
</script>
