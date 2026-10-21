<template>
    <TextInput
        :model-value="modelValue"
        :label="label"
        :type="type"
        :placeholder="placeholder"
        :required="required"
        :disabled="disabled"
        :error="error"
        :hint="hint"
        :icon="icon"
        :size="size"
        :uppercase="uppercase"
        @update:model-value="(v) => $emit('update:modelValue', v)"
    >
        <template v-if="$slots.suffix" #suffix><slot name="suffix" /></template>
    </TextInput>
</template>

<script setup>
/** @deprecated Use `TextInput` de `@/ui`. Mantido para módulos que já importam este caminho. */
import TextInput from '@/ui/TextInput.vue';

defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    icon: { type: [Object, Function], default: null },
    size: { type: String, default: 'md' },
    uppercase: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>
