<template>
    <span class="tabular-nums">{{ display }}</span>
</template>

<script setup lang="ts">
/**
 * Número que conta até o valor ao aparecer e ao mudar. Quem pede menos
 * movimento no sistema vê o valor final direto.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { money, number, compact } from './format';

const props = withDefaults(defineProps<{
    value: number;
    format?: 'number' | 'money' | 'percent' | 'compact';
    digits?: number;
    duration?: number;
}>(), { format: 'number', digits: 0, duration: 800 });

const current = ref(0);
let frame = 0;

function animate(from: number, to: number) {
    cancelAnimationFrame(frame);
    const reduce = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduce || props.duration <= 0) {
        current.value = to;
        return;
    }
    const start = performance.now();
    const step = (now: number) => {
        const t = Math.min(1, (now - start) / props.duration);
        const eased = 1 - Math.pow(1 - t, 4);
        current.value = from + (to - from) * eased;
        if (t < 1) frame = requestAnimationFrame(step);
    };
    frame = requestAnimationFrame(step);
}

onMounted(() => animate(0, Number(props.value) || 0));
watch(() => props.value, (to, from) => animate(Number(from) || 0, Number(to) || 0));
onBeforeUnmount(() => cancelAnimationFrame(frame));

const display = computed(() => {
    const v = current.value;
    switch (props.format) {
        case 'money': return money(v);
        case 'percent': return `${number(v, props.digits)}%`;
        case 'compact': return compact(v);
        default: return number(v, props.digits);
    }
});
</script>
