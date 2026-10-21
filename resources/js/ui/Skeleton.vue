<template>
    <div v-if="lines > 1" class="space-y-2.5" aria-hidden="true">
        <div v-for="i in lines" :key="i" class="ui-shimmer rounded-md" :style="{ height, width: i === lines ? '70%' : '100%' }" />
    </div>
    <div v-else class="ui-shimmer" :class="rounded" :style="{ height, width }" aria-hidden="true" />
</template>

<script setup lang="ts">
withDefaults(defineProps<{ height?: string; width?: string; lines?: number; rounded?: string }>(), {
    height: '1rem',
    width: '100%',
    lines: 1,
    rounded: 'rounded-md',
});
</script>
