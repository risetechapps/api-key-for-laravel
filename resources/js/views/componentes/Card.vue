<template>
    <div
        :class="[
            'bg-white dark:bg-slate-800 rounded-2xl border border-slate-200/60 dark:border-slate-700/60',
            shadow ? 'shadow-lg shadow-slate-200/50 dark:shadow-none' : '',
            hover ? 'hover:shadow-xl hover:shadow-slate-200/60 dark:hover:shadow-lg dark:hover:shadow-slate-900/50' +
             ' transition-all duration-300' : '',
            padding ? 'p-6' : '',
        ]">
        <div v-if="$slots.header || title" class="mb-4">
            <slot name="header">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
                <p v-if="subtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
            </slot>
        </div>

        <slot />

        <div v-if="$slots.footer" class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
            <slot name="footer" />
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
    shadow: {
        type: Boolean,
        default: true,
    },
    hover: {
        type: Boolean,
        default: false,
    },
    padding: {
        type: Boolean,
        default: true,
    },
});
</script>
