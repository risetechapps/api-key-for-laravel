<template>
    <span class="relative inline-flex group/tooltip" @focusin="shown = true" @focusout="shown = false">
        <slot />
        <span
            role="tooltip"
            :class="[
                'pointer-events-none absolute z-50 whitespace-nowrap rounded-lg bg-slate-900 dark:bg-slate-700 px-2 py-1 text-xs font-medium text-white shadow-lg',
                'opacity-0 scale-95 transition duration-150 group-hover/tooltip:opacity-100 group-hover/tooltip:scale-100',
                shown ? 'opacity-100 scale-100' : '',
                positions[position],
            ]"
        >{{ text }}</span>
    </span>
</template>

<script setup lang="ts">
import { ref } from 'vue';

withDefaults(defineProps<{ text: string; position?: 'top' | 'bottom' | 'left' | 'right' }>(), { position: 'top' });

const shown = ref(false);

const positions = {
    top: 'bottom-full left-1/2 -translate-x-1/2 mb-2 origin-bottom',
    bottom: 'top-full left-1/2 -translate-x-1/2 mt-2 origin-top',
    left: 'right-full top-1/2 -translate-y-1/2 mr-2 origin-right',
    right: 'left-full top-1/2 -translate-y-1/2 ml-2 origin-left',
};
</script>
