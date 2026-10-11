<template>
    <component
        :is="to ? RouterLink : href ? 'a' : 'button'"
        :to="to"
        :href="href"
        :type="to || href ? undefined : type"
        :disabled="to || href ? undefined : disabled || loading"
        :aria-disabled="disabled || loading || undefined"
        :aria-busy="loading || undefined"
        :class="[
            'group/button relative inline-flex items-center justify-center gap-2 font-semibold whitespace-nowrap select-none',
            'transition-[background-color,border-color,color,box-shadow,transform] duration-150 ease-out',
            'active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand-500 dark:focus-visible:ring-offset-slate-900',
            'disabled:opacity-50 disabled:pointer-events-none aria-disabled:opacity-50 aria-disabled:pointer-events-none',
            variants[variant],
            square ? squares[size] : sizes[size],
            block ? 'w-full' : '',
        ]"
        @click="onClick"
    >
        <Spinner v-if="loading" :size="size === 'lg' ? 'md' : 'sm'" class="absolute" />
        <span :class="['inline-flex items-center gap-2', loading ? 'invisible' : '']">
            <component :is="icon" v-if="icon" :size="iconSizes[size]" weight="bold" class="shrink-0" />
            <slot />
            <component :is="iconRight" v-if="iconRight" :size="iconSizes[size]" weight="bold" class="shrink-0 transition-transform group-hover/button:translate-x-0.5" />
        </span>
    </component>
</template>

<script setup lang="ts">
/**
 * Botão do painel. Vira <router-link> com `to` e <a> com `href`.
 *
 *   <Button variant="primary" :icon="PhPlus" :loading="saving" @click="save">Salvar</Button>
 */
import { RouterLink } from 'vue-router';
import type { Component } from 'vue';
import Spinner from './Spinner.vue';

const props = withDefaults(defineProps<{
    variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'success' | 'soft';
    size?: 'xs' | 'sm' | 'md' | 'lg';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
    block?: boolean;
    square?: boolean;
    icon?: Component | null;
    iconRight?: Component | null;
    to?: string | Record<string, unknown>;
    href?: string;
}>(), {
    variant: 'primary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
    block: false,
    square: false,
    icon: null,
    iconRight: null,
    to: undefined,
    href: undefined,
});

const emit = defineEmits<{ click: [event: MouseEvent] }>();

function onClick(event: MouseEvent) {
    if (props.disabled || props.loading) {
        event.preventDefault();
        return;
    }
    emit('click', event);
}

const variants = {
    primary: 'bg-brand-600 text-white shadow-sm shadow-brand-600/25 hover:bg-brand-700 hover:shadow-md hover:shadow-brand-600/30',
    secondary: 'bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100',
    outline: 'border border-slate-200 bg-white text-slate-700 shadow-xs hover:bg-slate-50 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800',
    ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white',
    soft: 'bg-brand-50 text-brand-700 hover:bg-brand-100 dark:bg-brand-500/15 dark:text-brand-300 dark:hover:bg-brand-500/25',
    danger: 'bg-red-600 text-white shadow-sm shadow-red-600/25 hover:bg-red-700',
    success: 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/25 hover:bg-emerald-700',
};

const sizes = {
    xs: 'h-7 px-2.5 text-xs rounded-lg',
    sm: 'h-9 px-3.5 text-sm rounded-xl',
    md: 'h-10 px-4.5 text-sm rounded-xl',
    lg: 'h-12 px-6 text-base rounded-2xl',
};

const squares = {
    xs: 'h-7 w-7 rounded-lg text-xs',
    sm: 'h-9 w-9 rounded-xl text-sm',
    md: 'h-10 w-10 rounded-xl text-sm',
    lg: 'h-12 w-12 rounded-2xl text-base',
};

const iconSizes = { xs: 14, sm: 16, md: 18, lg: 20 };
</script>
