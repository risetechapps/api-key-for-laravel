<template>
    <div>
        <div
            ref="listEl"
            role="tablist"
            :aria-label="label"
            :class="[
                'relative flex gap-1 overflow-x-auto [scrollbar-width:none]',
                variant === 'pills' ? 'p-1 rounded-xl bg-slate-100 dark:bg-slate-800/70 w-fit max-w-full' : 'border-b border-slate-200 dark:border-slate-800',
            ]"
            @keydown="onKeydown"
        >
            <span
                aria-hidden="true"
                :class="[
                    'absolute transition-[transform,width] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]',
                    variant === 'pills' ? 'top-1 bottom-1 rounded-lg bg-white dark:bg-slate-900 shadow-sm' : 'bottom-0 h-0.5 rounded-full bg-brand-600 dark:bg-brand-400',
                ]"
                :style="{ width: `${indicator.width}px`, transform: `translateX(${indicator.left}px)`, opacity: indicator.width ? 1 : 0 }"
            />
            <button
                v-for="tab in tabs"
                :key="tab.key"
                :ref="(el) => setTabRef(tab.key, el as HTMLElement | null)"
                role="tab"
                type="button"
                :id="`${uid}-tab-${tab.key}`"
                :aria-selected="tab.key === modelValue"
                :aria-controls="`${uid}-panel`"
                :tabindex="tab.key === modelValue ? 0 : -1"
                :class="[
                    'relative z-10 inline-flex items-center gap-2 whitespace-nowrap text-sm font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded-lg',
                    variant === 'pills' ? 'px-3.5 py-1.5' : 'px-3 pb-3 pt-1',
                    tab.key === modelValue
                        ? (variant === 'pills' ? 'text-slate-900 dark:text-white' : 'text-brand-700 dark:text-brand-300')
                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200',
                ]"
                @click="select(tab.key)"
            >
                <component :is="tab.icon" v-if="tab.icon" :size="16" :weight="tab.key === modelValue ? 'fill' : 'regular'" />
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined && tab.count !== null"
                    :class="['min-w-5 px-1.5 py-px rounded-full text-[11px] font-semibold tabular-nums', tab.key === modelValue ? 'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300' : 'bg-slate-200/70 text-slate-600 dark:bg-slate-700 dark:text-slate-300']"
                >{{ tab.count }}</span>
            </button>
        </div>

        <div v-if="$slots.default" :id="`${uid}-panel`" role="tabpanel" :aria-labelledby="`${uid}-tab-${modelValue}`" class="mt-6">
            <Transition :name="animate ? 'ui-slide' : ''" mode="out-in">
                <div :key="modelValue">
                    <slot :active="modelValue" />
                </div>
            </Transition>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * Abas com indicador animado e navegação por setas.
 *
 *   <Tabs v-model="tab" :tabs="[{ key: 'plan', label: 'Plano' }, { key: 'cards', label: 'Cartões', count: 2 }]">
 *       <template #default="{ active }">
 *           <PlanPanel v-if="active === 'plan'" />
 *           <CardsPanel v-else />
 *       </template>
 *   </Tabs>
 */
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue';
import type { Component } from 'vue';

export interface TabItem {
    key: string;
    label: string;
    icon?: Component;
    count?: number | string | null;
}

const props = withDefaults(defineProps<{
    modelValue: string;
    tabs: TabItem[];
    variant?: 'underline' | 'pills';
    label?: string;
    animate?: boolean;
}>(), { variant: 'underline', label: undefined, animate: true });

const emit = defineEmits<{ 'update:modelValue': [key: string] }>();

const uid = useId();
const listEl = ref<HTMLElement | null>(null);
const tabEls = new Map<string, HTMLElement>();
const indicator = reactive({ left: 0, width: 0 });

function setTabRef(key: string, el: HTMLElement | null) {
    if (el) tabEls.set(key, el);
    else tabEls.delete(key);
}

function measure() {
    const el = tabEls.get(props.modelValue);
    if (!el) {
        indicator.width = 0;
        return;
    }
    indicator.left = el.offsetLeft;
    indicator.width = el.offsetWidth;
    el.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
}

function select(key: string) {
    if (key !== props.modelValue) emit('update:modelValue', key);
}

function onKeydown(event: KeyboardEvent) {
    const index = props.tabs.findIndex((t) => t.key === props.modelValue);
    const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: props.tabs.length - 1 }[event.key];
    if (next === undefined) return;
    event.preventDefault();
    const tab = props.tabs[(next + props.tabs.length) % props.tabs.length];
    if (!tab) return;
    select(tab.key);
    nextTick(() => tabEls.get(tab.key)?.focus());
}

let observer: ResizeObserver | null = null;

onMounted(() => {
    nextTick(measure);
    if (typeof ResizeObserver !== 'undefined' && listEl.value) {
        observer = new ResizeObserver(measure);
        observer.observe(listEl.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

watch(() => [props.modelValue, props.tabs.map((t) => `${t.key}${t.count ?? ''}`).join()], () => nextTick(measure));
</script>
