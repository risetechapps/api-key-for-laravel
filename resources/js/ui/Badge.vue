<template>
    <span :class="['inline-flex items-center gap-1.5 font-medium whitespace-nowrap ring-1 ring-inset', sizes[size], tones[tone]]">
        <span v-if="dot" :class="['w-1.5 h-1.5 rounded-full', dots[tone], pulse ? 'animate-pulse' : '']" />
        <component :is="icon" v-if="icon" :size="12" weight="bold" />
        <slot />
    </span>
</template>

<script setup lang="ts">
import type { Component } from 'vue';

export type BadgeTone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info';

withDefaults(defineProps<{
    tone?: BadgeTone;
    size?: 'sm' | 'md';
    dot?: boolean;
    pulse?: boolean;
    icon?: Component | null;
}>(), { tone: 'neutral', size: 'sm', dot: false, pulse: false, icon: null });

const sizes = { sm: 'px-2 py-0.5 text-xs rounded-md', md: 'px-2.5 py-1 text-sm rounded-lg' };

const tones: Record<BadgeTone, string> = {
    neutral: 'bg-slate-50 text-slate-600 ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/30',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
    warning: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
    danger: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
    info: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
};

const dots: Record<BadgeTone, string> = {
    neutral: 'bg-slate-400', brand: 'bg-brand-500', success: 'bg-emerald-500',
    warning: 'bg-amber-500', danger: 'bg-red-500', info: 'bg-sky-500',
};
</script>
