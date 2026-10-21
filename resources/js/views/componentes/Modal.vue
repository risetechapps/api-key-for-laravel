<template>
    <UiModal :model-value="modelValue" :title="title" @update:model-value="(v) => $emit('update:modelValue', v)">
        <template v-if="$slots.title" #title><slot name="title" /></template>
        <slot />
        <template v-if="$slots.footer" #footer><slot name="footer" /></template>
    </UiModal>
</template>

<script setup>
/** @deprecated Use `Modal` de `@/ui`. Mantido para módulos que já importam este caminho. */
import UiModal from '@/ui/Modal.vue';

defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
});

defineEmits(['update:modelValue']);
</script>
