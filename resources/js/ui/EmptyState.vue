<template>
    <div :class="['flex flex-col items-center justify-center text-center animate-fade-in', compact ? 'py-8' : 'py-14']">
        <div class="relative mb-4">
            <div class="absolute inset-0 rounded-2xl bg-brand-500/20 blur-xl" />
            <div class="relative w-14 h-14 rounded-2xl bg-gradient-to-br from-brand-50 to-white dark:from-brand-500/15 dark:to-slate-900 ring-1 ring-brand-100 dark:ring-brand-500/20 flex items-center justify-center">
                <component :is="icon" :size="26" weight="duotone" class="text-brand-600 dark:text-brand-400" />
            </div>
        </div>
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap items-center justify-center gap-2">
            <slot />
        </div>
    </div>
</template>

<script setup lang="ts">
import type { Component } from 'vue';
import { PhTray } from '@phosphor-icons/vue';

withDefaults(defineProps<{ title: string; description?: string; icon?: Component; compact?: boolean }>(), {
    description: '',
    icon: () => PhTray,
    compact: false,
});
</script>
