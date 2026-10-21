<template>
    <UiCard :title="title" :subtitle="subtitle" :padding="padding" :interactive="hover">
        <template v-if="$slots.header" #header><slot name="header" /></template>
        <slot />
        <template v-if="$slots.footer" #footer><slot name="footer" /></template>
    </UiCard>
</template>

<script setup>
/** @deprecated Use `Card` de `@/ui`. Mantido para módulos que já importam este caminho. */
import UiCard from '@/ui/Card.vue';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    shadow: { type: Boolean, default: true },
    hover: { type: Boolean, default: false },
    padding: { type: Boolean, default: true },
});
</script>
