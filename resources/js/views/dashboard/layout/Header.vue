<template>
    <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border-b border-slate-200 dark:border-slate-800">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Left -->
                <div class="flex items-center gap-4">
                    <button
                        @click="$emit('toggle-sidebar')"
                        class="lg:hidden p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        <PhList :size="24" class="text-slate-600 dark:text-slate-400" />
                    </button>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ pageTitle }}</h1>
                </div>

                <!-- Right -->
                <div class="flex items-center gap-3">
                    <!-- Dark mode toggle -->
                    <button
                        @click="toggleDarkMode"
                        class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        :title="isDark ? 'Modo claro' : 'Modo escuro'"
                    >
                        <PhSun v-if="isDark" :size="20" class="text-amber-500" />
                        <PhMoon v-else :size="20" class="text-slate-600" />
                    </button>

                    <!-- Notifications -->
                    <div class="relative">
                        <button
                            @click="showNotifications = !showNotifications"
                            class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors relative"
                        >
                            <PhBell :size="20" class="text-slate-600 dark:text-slate-400" />
                            <span
                                v-if="notificationCount > 0"
                                class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"
                            />
                        </button>

                        <!-- Notification dropdown -->
                        <Transition
                            enter="transition ease-out duration-200"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="transition ease-in duration-150"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <div
                                v-if="showNotifications"
                                class="absolute right-0 mt-2 w-80 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-50"
                            >
                                <div class="px-4 py-2 border-b border-slate-200 dark:border-slate-700">
                                    <span class="font-semibold text-slate-900 dark:text-white">Notificações</span>
                                </div>
                                <div class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400 text-center">
                                    Nenhuma notificação nova
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- User dropdown -->
                    <div class="relative">
                        <button
                            @click="showUserMenu = !showUserMenu"
                            class="flex items-center gap-2 p-1.5 pr-3 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-sm font-semibold">
                                {{ userInitials }}
                            </div>
                            <PhCaretDown
                                :size="16"
                                class="text-slate-400 transition-transform"
                                :class="{ 'rotate-180': showUserMenu }"
                            />
                        </button>

                        <!-- User menu -->
                        <Transition
                            enter="transition ease-out duration-200"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="transition ease-in duration-150"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <div
                                v-if="showUserMenu"
                                class="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 py-2 z-50"
                            >
                                <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700"
>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white truncate">{{ user?.personal?.name || user?.name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ user?.contact?.email || user?.email }}</p>
                                </div>
                                <div class="py-1">
                                    <router-link
                                        to="/dashboard/profile"
                                        class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors"
                                    >
                                        <PhUser :size="18" />
                                        Perfil
                                    </router-link>
                                    <router-link
                                        to="/dashboard/plans"
                                        class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors"
                                    >
                                        <PhCreditCard :size="18" />
                                        Meu Plano
                                    </router-link>
                                </div>
                                <div class="border-t border-slate-200 dark:border-slate-700 py-1"
>
                                    <button
                                        @click="logout"
                                        class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                    >
                                        <PhSignOut :size="18" />
                                        Sair
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import {
    PhList,
    PhSun,
    PhMoon,
    PhBell,
    PhCaretDown,
    PhUser,
    PhCreditCard,
    PhSignOut,
} from '@phosphor-icons/vue';

const emit = defineEmits(['toggle-sidebar']);

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const showNotifications = ref(false);
const showUserMenu = ref(false);
const isDark = ref(false);
const notificationCount = ref(0);

const user = computed(() => authStore.user);

const userInitials = computed(() => {
    const name = user.value?.personal?.name || user.value?.name;
    if (!name) return '';
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
});

const pageTitles = {
    '/dashboard': 'Dashboard',
    '/dashboard/profile': 'Perfil',
    '/dashboard/requests': 'Histórico de Requisições',
    '/dashboard/plans': 'Planos',
    '/dashboard/billing': 'Faturamento',
};

const pageTitle = computed(() => {
    return pageTitles[route.path] || 'Dashboard';
});

onMounted(() => {
    // Sincroniza o estado com a classe atual no DOM
    isDark.value = document.documentElement.classList.contains('dark');
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

async function logout() {
    await authStore.logout();
    router.push('/login');
}

// Close dropdowns when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.relative')) {
        showNotifications.value = false;
        showUserMenu.value = false;
    }
});
</script>
