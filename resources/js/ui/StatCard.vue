<template>
    <Card :padding="false" :interactive="!!to" :as="wrapper" :to="to" class="overflow-hidden">
        <div class="p-5">
            <div class="flex items-start justify-between gap-3">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ label }}</p>
                <span v-if="icon" :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', iconTones[tone]]">
                    <component :is="icon" :size="18" weight="duotone" />
                </span>
            </div>

            <div class="mt-2">
                <Skeleton v-if="loading" height="2rem" width="60%" />
                <p v-else class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    <AnimatedNumber v-if="typeof value === 'number'" :value="value" :format="format" :digits="digits" />
                    <template v-else>{{ value }}</template>
                </p>
            </div>

            <div v-if="hint || delta !== null" class="mt-2 flex items-center gap-2 text-xs">
                <span v-if="delta !== null" :class="['inline-flex items-center gap-0.5 font-semibold', delta >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400']">
                    <PhArrowUpRight v-if="delta >= 0" :size="12" weight="bold" />
                    <PhArrowDownRight v-else :size="12" weight="bold" />
                    {{ Math.abs(delta).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) }}%
                </span>
                <span class="text-slate-500 dark:text-slate-400">{{ hint }}</span>
            </div>
        </div>
        <slot />
    </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import type { Component } from 'vue';
import { PhArrowUpRight, PhArrowDownRight } from '@phosphor-icons/vue';
import Card from './Card.vue';
import Skeleton from './Skeleton.vue';
import AnimatedNumber from './AnimatedNumber.vue';

const props = withDefaults(defineProps<{
    label: string;
    value?: number | string | null;
    format?: 'number' | 'money' | 'percent' | 'compact';
    digits?: number;
    hint?: string;
    delta?: number | null;
    icon?: Component | null;
    tone?: 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';
    loading?: boolean;
    to?: string;
}>(), { value: null, format: 'number', digits: 0, hint: '', delta: null, icon: null, tone: 'brand', loading: false, to: undefined });

// Com `to`, o cartão inteiro vira link.
const wrapper = computed<string | Component>(() => (props.to ? RouterLink : 'section'));

const iconTones = {
    brand: 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
    success: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
    danger: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300',
    info: 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300',
    neutral: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};
</script>
