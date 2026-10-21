<template>
    <div>
        <div v-if="label || showValue" class="flex items-center justify-between mb-1.5 text-xs">
            <span class="font-medium text-slate-600 dark:text-slate-300">{{ label }}</span>
            <span v-if="showValue" class="tabular-nums text-slate-500 dark:text-slate-400">{{ Math.round(percent) }}%</span>
        </div>
        <div :class="['w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800', heights[size]]" role="progressbar" :aria-valuenow="Math.round(percent)" aria-valuemin="0" aria-valuemax="100">
            <div :class="['h-full rounded-full transition-[width] duration-700 ease-[cubic-bezier(0.22,1,0.36,1)]', barTone]" :style="{ width: `${percent}%` }" />
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    value: number;
    max?: number;
    label?: string;
    showValue?: boolean;
    size?: 'sm' | 'md';
    tone?: 'auto' | 'brand' | 'success' | 'warning' | 'danger';
}>(), { max: 100, label: '', showValue: false, size: 'md', tone: 'auto' });

const heights = { sm: 'h-1.5', md: 'h-2.5' };

const percent = computed(() => props.max > 0 ? Math.min(100, Math.max(0, (props.value / props.max) * 100)) : 0);

const barTone = computed(() => {
    const tone = props.tone === 'auto'
        ? (percent.value >= 90 ? 'danger' : percent.value >= 75 ? 'warning' : 'brand')
        : props.tone;
    return {
        brand: 'bg-gradient-to-r from-brand-500 to-brand-400',
        success: 'bg-emerald-500',
        warning: 'bg-gradient-to-r from-amber-500 to-amber-400',
        danger: 'bg-gradient-to-r from-red-600 to-red-500',
    }[tone];
});
</script>
