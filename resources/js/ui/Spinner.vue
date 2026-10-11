<template>
    <svg :class="['animate-spin', sizes[size]]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="9.5" stroke="currentColor" stroke-width="3" class="opacity-20" />
        <path d="M21.5 12a9.5 9.5 0 0 0-9.5-9.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    </svg>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ size?: 'xs' | 'sm' | 'md' | 'lg' }>(), { size: 'sm' });

const sizes = { xs: 'w-3 h-3', sm: 'w-4 h-4', md: 'w-6 h-6', lg: 'w-10 h-10' };
</script>
