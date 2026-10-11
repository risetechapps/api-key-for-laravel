<template>
    <div aria-live="polite" class="pointer-events-none fixed inset-x-0 top-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6">
        <TransitionGroup name="ui-toast" tag="div" class="flex w-full flex-col items-center gap-2 sm:items-end">
            <div
                v-for="t in toasts"
                :key="t.id"
                role="status"
                class="pointer-events-auto relative w-full max-w-sm overflow-hidden rounded-2xl bg-white/95 dark:bg-slate-900/95 backdrop-blur shadow-xl ring-1 ring-slate-900/5 dark:ring-white/10"
                @mouseenter="paused = t.id"
                @mouseleave="paused = null"
            >
                <div class="flex items-start gap-3 p-4">
                    <span :class="['mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', tones[t.tone].bg]">
                        <component :is="tones[t.tone].icon" :size="14" weight="bold" :class="tones[t.tone].fg" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ t.title }}</p>
                        <p v-if="t.description" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t.description }}</p>
                        <button v-if="t.action" type="button" class="mt-2 text-sm font-semibold text-brand-600 dark:text-brand-400 hover:underline" @click="t.action.onClick(); dismiss(t.id)">{{ t.action.label }}</button>
                    </div>
                    <button type="button" class="-m-1 p-1 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label="Fechar aviso" @click="dismiss(t.id)">
                        <PhX :size="14" weight="bold" />
                    </button>
                </div>
                <div
                    v-if="t.duration > 0"
                    :class="['absolute bottom-0 left-0 h-0.5', tones[t.tone].bar]"
                    :style="{ animation: `ui-toast-progress ${t.duration}ms linear forwards`, animationPlayState: paused === t.id ? 'paused' : 'running' }"
                />
            </div>
        </TransitionGroup>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { PhCheck, PhX, PhInfo, PhWarning } from '@phosphor-icons/vue';
import { toasts, dismiss } from './toast';

const paused = ref<number | null>(null);

const tones = {
    success: { icon: PhCheck, bg: 'bg-emerald-100 dark:bg-emerald-500/20', fg: 'text-emerald-600 dark:text-emerald-300', bar: 'bg-emerald-500' },
    error: { icon: PhX, bg: 'bg-red-100 dark:bg-red-500/20', fg: 'text-red-600 dark:text-red-300', bar: 'bg-red-500' },
    info: { icon: PhInfo, bg: 'bg-brand-100 dark:bg-brand-500/20', fg: 'text-brand-600 dark:text-brand-300', bar: 'bg-brand-500' },
    warning: { icon: PhWarning, bg: 'bg-amber-100 dark:bg-amber-500/20', fg: 'text-amber-600 dark:text-amber-300', bar: 'bg-amber-500' },
};
</script>

<style>
@keyframes ui-toast-progress {
    from { width: 100%; }
    to { width: 0%; }
}
</style>
