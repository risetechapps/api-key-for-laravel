<template>
    <span :class="['inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white overflow-hidden ring-2 ring-white dark:ring-slate-900', sizes[size]]" :style="src ? undefined : { background: gradient }">
        <img v-if="src" :src="src" :alt="name" class="h-full w-full object-cover" />
        <span v-else>{{ initials }}</span>
    </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name?: string | null; src?: string | null; size?: 'xs' | 'sm' | 'md' | 'lg' }>(), {
    name: '', src: null, size: 'md',
});

const sizes = { xs: 'h-6 w-6 text-[10px]', sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm', lg: 'h-14 w-14 text-lg' };

const initials = computed(() => (props.name ?? '')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('') || '?');

// Cor estável por nome: a mesma pessoa tem sempre a mesma cor.
const gradient = computed(() => {
    let hash = 0;
    for (const c of props.name ?? '') hash = (hash * 31 + c.charCodeAt(0)) >>> 0;
    const hue = hash % 360;
    return `linear-gradient(135deg, hsl(${hue} 70% 55%), hsl(${(hue + 40) % 360} 70% 45%))`;
});
</script>
