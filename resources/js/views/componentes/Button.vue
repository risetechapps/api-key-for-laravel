<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        @click="$emit('click')"
        :class="[
            'inline-flex items-center justify-center gap-2 px-6 py-3 font-semibold rounded-xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed',
            variants[variant],
            sizes[size],
            { 'cursor-wait': loading },
        ]"
    >
        <Loading v-if="loading" size="sm" variant="spinner" class="text-current" />
        <span v-else-if="icon" class="flex items-center gap-2">
            <component :is="icon" :size="iconSize" weight="bold" />
            <slot />
        </span>
        <slot v-else />
    </button>
</template>

<script setup>
import { computed } from 'vue';
import Loading from './Loading.vue';

defineProps({
    type: {
        type: String,
        default: 'button',
    },
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'secondary', 'outline', 'ghost', 'danger'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    icon: {
        type: [Object, Function],
        default: null,
    },
});

defineEmits(['click']);

const variants = {
    primary: 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-indigo-500 shadow-lg shadow-indigo-500/30',
    secondary: 'bg-emerald-500 hover:bg-emerald-600 text-white focus:ring-emerald-500 shadow-lg shadow-emerald-500/30',
    outline: 'border-2 border-indigo-600 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 focus:ring-indigo-500 dark:text-indigo-400 dark:border-indigo-400',
    ghost: 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 focus:ring-slate-500',
    danger: 'bg-red-500 hover:bg-red-600 text-white focus:ring-red-500 shadow-lg shadow-red-500/30',
};

const sizes = {
    sm: 'text-sm px-4 py-2',
    md: 'text-base px-6 py-3',
    lg: 'text-lg px-8 py-4',
};

const iconSize = computed((size) => {
    const sizes = { sm: 16, md: 20, lg: 24 };
    return sizes[size] || 20;
});
</script>
