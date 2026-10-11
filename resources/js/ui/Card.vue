<template>
    <component
        :is="as"
        :class="[
            'relative bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800',
            'shadow-[0_1px_2px_rgb(15_23_42/0.04),0_1px_1px_rgb(15_23_42/0.03)]',
            interactive ? 'transition-[box-shadow,border-color,transform] duration-200 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-200/60 hover:border-slate-300/80 dark:hover:shadow-black/30 dark:hover:border-slate-700' : '',
        ]"
    >
        <div v-if="title || $slots.header || $slots.actions" :class="['flex items-start justify-between gap-4', padding ? 'px-6 pt-5' : '', $slots.default ? 'pb-0' : 'pb-5']">
            <slot name="header">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
                    <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
                </div>
            </slot>
            <div v-if="$slots.actions" class="flex items-center gap-2 shrink-0">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.default" :class="padding ? (title || $slots.header ? 'px-6 pt-4 pb-6' : 'p-6') : ''">
            <slot />
        </div>

        <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 rounded-b-2xl">
            <slot name="footer" />
        </div>
    </component>
</template>

<script setup lang="ts">

withDefaults(defineProps<{
    title?: string;
    subtitle?: string;
    padding?: boolean;
    interactive?: boolean;
    as?: string | object;
}>(), { title: '', subtitle: '', padding: true, interactive: false, as: 'section' });
</script>
