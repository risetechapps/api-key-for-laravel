<template>
    <div class="flex flex-col h-full">
        <!-- Logo -->
        <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 h-16 border-b border-slate-200 dark:border-slate-800">
            <router-link :to="appConfig.app.logoHref" class="flex items-center gap-3">
                <template v-if="appConfig.app.logoImage">
                    <img :src="appConfig.app.logoImage" class="h-9 w-auto" :alt="appConfig.app.logoText" />
                </template>
                <template v-else>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                        <PhHexagon :size="20" weight="fill" class="text-white" />
                    </div>
                </template>
                <span class="text-xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                    {{ appConfig.app.logoText }}
                </span>
            </router-link>
            <button
                @click="$emit('close')"
                class="lg:hidden p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            >
                <PhX :size="20" class="text-slate-500" />
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto px-4 py-6">
            <div class="space-y-1">
                <router-link
                    v-for="item in navigation"
                    :key="item.name"
                    :to="item.to"
                    :class="[
                        'flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200',
                        isActive(item.to)
                            ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400'
                            : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white',
                    ]"
                >
                    <component
                        :is="item.icon"
                        :size="20"
                        :weight="isActive(item.to) ? 'fill' : 'regular'"
                        :class="isActive(item.to) ? 'text-indigo-600 dark:text-indigo-400' : ''"
                    />
                    {{ item.name }}
                </router-link>
            </div>

            <!-- Admin section -->
            <div v-if="isAdmin" class="mt-6">
                <div class="flex items-center gap-2 px-4 mb-2">
                    <PhShieldStar :size="14" weight="fill" class="text-indigo-500" />
                    <span class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider">Administração</span>
                </div>
                <div class="space-y-1">
                    <router-link
                        v-for="item in adminNavigation"
                        :key="item.name"
                        :to="item.to"
                        :class="[
                            'flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200',
                            isActive(item.to)
                                ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400'
                                : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white',
                        ]"
                    >
                        <component :is="item.icon" :size="18" :weight="isActive(item.to) ? 'fill' : 'regular'" />
                        {{ item.name }}
                    </router-link>
                </div>
            </div>

            <!-- Plan info -->
            <div class="mt-8 p-4 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-purple-500/10 border border-indigo-200 dark:border-indigo-800/30">
                <div class="flex items-center gap-2 mb-3">
                    <PhCrown :size="20" weight="fill" class="text-amber-500" />
                    <span class="font-semibold text-slate-900 dark:text-white">{{ planName }}</span>
                </div>
                <div class="mb-3">
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-slate-600 dark:text-slate-400">Uso do plano</span>
                        <span class="font-medium text-slate-900 dark:text-white">{{ usagePercentage }}%</span>
                    </div>
                    <div class="h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full transition-all duration-500"
                            :style="{ width: `${usagePercentage}%` }"
                        />
                    </div>
                </div>
                <router-link
                    to="/dashboard/plans"
                    class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors"
                >
                    Ver planos →
                </router-link>
            </div>
        </nav>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import appConfig from '@/api-key.config';
import {
    PhHexagon,
    PhX,
    PhHouse,
    PhUser,
    PhClockCounterClockwise,
    PhCreditCard,
    PhReceipt,
    PhCrown,
    PhShieldStar,
    PhUsersThree,
    PhArrowCounterClockwise,
    PhTag,
} from '@phosphor-icons/vue';

const props = defineProps({
    isOpen: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['close']);

const route    = useRoute();
const authStore = useAuthStore();
const isAdmin   = computed(() => authStore.isAdmin);

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

const planName = computed(() => {
    return user.value?.active_plan?.plan?.name || 'Sem plano';
});

const usagePercentage = computed(() => {
    // Usa dados de usage se disponíveis
    if (user.value?.usage) {
        const used = user.value.usage.requests_used || 0;
        const limit = user.value.usage.requests_limit || 1;
        return Math.round((used / limit) * 100);
    }
    // Fallback para estrutura antiga
    const used = user.value?.active_plan?.requests_used || 0;
    const limit = user.value?.active_plan?.plan?.request_limit || 1;
    return Math.round((used / limit) * 100);
});

const navigation = [
    { name: 'Dashboard',   to: '/dashboard',          icon: PhHouse },
    { name: 'Perfil',      to: '/dashboard/profile',  icon: PhUser },
    { name: 'Requisições', to: '/dashboard/requests', icon: PhClockCounterClockwise },
    { name: 'Planos',      to: '/dashboard/plans',    icon: PhCreditCard },
    { name: 'Faturamento', to: '/dashboard/billing',  icon: PhReceipt },
    ...appConfig.menu.extraItems,
];

const adminNavigation = [
    { name: 'Planos',    to: '/dashboard/admin/plans',   icon: PhCreditCard },
    { name: 'Cupons',    to: '/dashboard/admin/coupons', icon: PhTag },
    { name: 'Usuários',  to: '/dashboard/admin/users',   icon: PhUsersThree },
    { name: 'Estornos',  to: '/dashboard/admin/refunds', icon: PhArrowCounterClockwise },
    ...appConfig.menu.extraAdminItems,
];

function isActive(path) {
    // Para /dashboard, só ativa se for exatamente /dashboard (não sub-rotas)
    if (path === '/dashboard') {
        return route.path === '/dashboard';
    }
    // Para outras rotas, ativa se for exato ou sub-rota
    return route.path === path || route.path.startsWith(`${path}/`);
}
</script>
