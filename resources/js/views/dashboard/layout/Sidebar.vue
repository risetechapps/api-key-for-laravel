<template>
    <div class="flex h-full flex-col">
        <!-- Marca -->
        <div :class="['flex h-16 shrink-0 items-center gap-3 border-b border-slate-200/80 dark:border-slate-800', collapsed ? 'justify-center px-3' : 'justify-between px-5']">
            <router-link :to="appConfig.app.logoHref || '/'" class="flex min-w-0 items-center gap-3">
                <img v-if="brand.logo" :src="brand.logo" :alt="brand.name" :class="collapsed ? 'h-8 w-8 object-contain' : 'h-8 w-auto max-w-40 object-contain'" />
                <template v-else>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 shadow-lg shadow-brand-600/30">
                        <PhHexagon :size="18" weight="fill" class="text-white" />
                    </span>
                    <span v-if="!collapsed" class="truncate text-base font-bold tracking-tight text-slate-900 dark:text-white">{{ brand.name }}</span>
                </template>
            </router-link>
            <button v-if="mobile" type="button" class="p-2 -mr-2 rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Fechar menu" @click="$emit('close')">
                <PhX :size="18" />
            </button>
        </div>

        <!-- Navegação -->
        <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4" aria-label="Menu principal">
            <div v-for="(section, si) in sections" :key="section.title ?? si" :class="si > 0 ? 'mt-6' : ''">
                <p v-if="section.title && !collapsed" class="mb-1.5 flex items-center gap-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                    <PhShieldStar v-if="section.title === 'Administração'" :size="12" weight="fill" class="text-brand-500" />
                    {{ section.title }}
                </p>
                <div v-else-if="section.title && collapsed" class="mx-3 mb-2 h-px bg-slate-200 dark:bg-slate-800" />

                <ul class="space-y-0.5">
                    <li v-for="item in section.items" :key="item.to">
                        <component :is="collapsed ? Tooltip : 'div'" v-bind="collapsed ? { text: item.name, position: 'right' } : {}" class="block w-full">
                            <router-link
                                :to="item.to"
                                :aria-current="isActivePath(route.path, item) ? 'page' : undefined"
                                :class="[
                                    'group relative flex w-full items-center rounded-xl text-sm font-medium transition-colors duration-150',
                                    collapsed ? 'h-10 justify-center' : 'gap-3 px-3 py-2',
                                    isActivePath(route.path, item)
                                        ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/70 dark:hover:text-white',
                                ]"
                                @click="$emit('navigate')"
                            >
                                <span v-if="isActivePath(route.path, item)" class="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-brand-600 dark:bg-brand-400" />
                                <component :is="item.icon" :size="19" :weight="isActivePath(route.path, item) ? 'fill' : 'regular'" class="shrink-0 transition-transform duration-150 group-hover:scale-110" />
                                <span v-if="!collapsed" class="flex-1 truncate">{{ item.name }}</span>
                                <Badge v-if="item.badge !== undefined && !collapsed" tone="brand">{{ item.badge }}</Badge>
                            </router-link>
                        </component>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Plano -->
        <div v-if="!collapsed" class="p-3">
            <router-link to="/dashboard/plans" class="group block rounded-2xl border border-brand-100 dark:border-brand-500/20 bg-gradient-to-br from-brand-50 via-white to-white dark:from-brand-500/10 dark:via-slate-900 dark:to-slate-900 p-4 transition-shadow hover:shadow-md hover:shadow-brand-500/10">
                <div class="mb-3 flex items-center justify-between">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                        <PhCrown :size="16" weight="fill" class="text-amber-500" />
                        {{ planName }}
                    </span>
                    <PhArrowRight :size="14" class="text-slate-400 transition-transform group-hover:translate-x-0.5" />
                </div>
                <Progress :value="usage.used" :max="usage.limit || 1" size="sm" />
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    <template v-if="usage.limit">{{ usage.used.toLocaleString('pt-BR') }} de {{ usage.limit.toLocaleString('pt-BR') }} requisições</template>
                    <template v-else>Escolha um plano para começar</template>
                </p>
            </router-link>
        </div>

        <!-- Recolher -->
        <div v-if="!mobile" class="border-t border-slate-200/80 dark:border-slate-800 p-3">
            <button
                type="button"
                :class="['flex w-full items-center rounded-xl py-2 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white transition-colors', collapsed ? 'justify-center' : 'gap-3 px-3']"
                :aria-label="collapsed ? 'Expandir menu' : 'Recolher menu'"
                @click="$emit('toggle-collapse')"
            >
                <PhSidebarSimple :size="18" :class="collapsed ? 'rotate-180' : ''" class="transition-transform" />
                <span v-if="!collapsed">Recolher menu</span>
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { PhHexagon, PhX, PhShieldStar, PhCrown, PhArrowRight, PhSidebarSimple } from '@phosphor-icons/vue';
import { useAuthStore } from '@/stores/auth';
import appConfig from '@/api-key.config';
import { brand } from '@/brand';
import { useNavigation, isActivePath } from '@/navigation';
import { Badge, Progress, Tooltip } from '@/ui';

withDefaults(defineProps<{ collapsed?: boolean; mobile?: boolean }>(), { collapsed: false, mobile: false });
defineEmits<{ close: []; navigate: []; 'toggle-collapse': [] }>();

const route = useRoute();
const authStore = useAuthStore();
const { sections } = useNavigation();

const user = computed<any>(() => authStore.user);

const planName = computed(() => user.value?.active_plan?.plan?.name || 'Sem plano');

const usage = computed(() => {
    const u = user.value?.usage;
    const used = Number(u?.requests_used ?? user.value?.active_plan?.requests_used ?? 0);
    const limit = Number(u?.requests_limit ?? user.value?.active_plan?.plan?.request_limit ?? 0);
    return { used, limit };
});
</script>
