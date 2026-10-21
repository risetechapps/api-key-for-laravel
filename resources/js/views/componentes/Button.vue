<template>
    <UiButton
        :type="type"
        :variant="variant === 'secondary' ? 'success' : variant"
        :size="size"
        :disabled="disabled"
        :loading="loading"
        :icon="icon"
        @click="$emit('click', $event)"
    >
        <slot />
    </UiButton>
</template>

<script setup>
/**
 * @deprecated Use `Button` de `@/ui`. Mantido para módulos que já importam
 * este caminho; o visual é o mesmo. Aqui `secondary` continua sendo o verde
 * de antes (no `@/ui` ele é o neutro escuro; o verde é `success`).
 */
import UiButton from '@/ui/Button.vue';

defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    icon: { type: [Object, Function], default: null },
});

defineEmits(['click']);
</script>
