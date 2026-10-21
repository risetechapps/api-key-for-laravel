<template>
    <Field v-bind="rootAttrs" :label="label" :hint="hint" :error="error" :required="required" :for-id="id">
        <textarea
            :id="id"
            v-bind="fieldAttrs"
            :value="modelValue ?? ''"
            :rows="rows"
            :required="required"
            :class="[...inputClasses({ invalid: !!error }), 'h-auto py-2.5 resize-y']"
            @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
        />
    </Field>
</template>

<script setup lang="ts">
import { computed, useAttrs, useId } from 'vue';
import Field from './Field.vue';
import { inputClasses } from './inputClasses';

defineOptions({ inheritAttrs: false });

withDefaults(defineProps<{ modelValue?: string | null; label?: string; hint?: string; error?: string; rows?: number; required?: boolean }>(), {
    modelValue: '', label: '', hint: '', error: '', rows: 4, required: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const id = useId();
// `class` e `style` vão para o contêiner (largura, grid, margem); o resto dos
// atributos (placeholder, autocomplete, min…) vai para o campo.
const attrs = useAttrs();
const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style as any }));
const fieldAttrs = computed(() => {
    const { class: _c, style: _s, ...rest } = attrs;
    return rest;
});

</script>
