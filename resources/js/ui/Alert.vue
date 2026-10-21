<template>
    <div role="status" :class="['relative flex flex-col gap-3 rounded-2xl border p-4 sm:flex-row sm:items-center animate-fade-in', tones[tone].box]">
        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', tones[tone].icon]">
            <component :is="icon ?? defaults[tone]" :size="18" weight="duotone" />
        </span>
        <div class="min-w-0 flex-1 text-sm">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="title ? 'mt-0.5 opacity-90' : ''"><slot /></div>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap gap-2"><slot name="actions" /></div>
    </div>
</template>

<script setup lang="ts">
import type { Component } from 'vue';
import { PhInfo, PhCheckCircle, PhWarning, PhWarningOctagon, PhSparkle } from '@phosphor-icons/vue';

withDefaults(defineProps<{
    tone?: 'info' | 'success' | 'warning' | 'danger' | 'brand';
    title?: string;
    icon?: Component | null;
}>(), { tone: 'info', title: '', icon: null });

const defaults = { info: PhInfo, success: PhCheckCircle, warning: PhWarning, danger: PhWarningOctagon, brand: PhSparkle };

const tones = {
    info: { box: 'border-sky-200 bg-sky-50/80 text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100', icon: 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300' },
    success: { box: 'border-emerald-200 bg-emerald-50/80 text-emerald-900 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-100', icon: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300' },
    warning: { box: 'border-amber-200 bg-amber-50/80 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100', icon: 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300' },
    danger: { box: 'border-red-200 bg-red-50/80 text-red-900 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-100', icon: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' },
    brand: { box: 'border-brand-200 bg-brand-50/80 text-brand-900 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-100', icon: 'bg-brand-100 text-brand-600 dark:bg-brand-500/20 dark:text-brand-300' },
};
</script>
