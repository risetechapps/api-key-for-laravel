<template>
    <header class="mb-6 sm:mb-8 animate-fade-in-up">
        <nav v-if="breadcrumbs?.length" class="mb-2 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400" aria-label="Navegação">
            <template v-for="(b, i) in breadcrumbs" :key="i">
                <RouterLink v-if="b.to" :to="b.to" class="hover:text-brand-600 dark:hover:text-brand-400 transition-colors">{{ b.label }}</RouterLink>
                <span v-else class="text-slate-700 dark:text-slate-300">{{ b.label }}</span>
                <PhCaretRight v-if="i < breadcrumbs.length - 1" :size="10" weight="bold" />
            </template>
        </nav>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-start gap-3 min-w-0">
                <span v-if="icon" class="hidden sm:flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-600/25">
                    <component :is="icon" :size="22" weight="duotone" />
                </span>
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        <slot name="title">{{ title }}</slot>
                    </h1>
                    <p v-if="subtitle || $slots.subtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        <slot name="subtitle">{{ subtitle }}</slot>
                    </p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
import { RouterLink } from 'vue-router';
import type { Component } from 'vue';
import { PhCaretRight } from '@phosphor-icons/vue';

withDefaults(defineProps<{
    title?: string;
    subtitle?: string;
    icon?: Component | null;
    breadcrumbs?: { label: string; to?: string }[];
}>(), { title: '', subtitle: '', icon: null, breadcrumbs: () => [] });
</script>
