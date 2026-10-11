<template>
    <Field v-bind="rootAttrs" :label="label" :hint="hint" :error="error" :required="required" :for-id="id">
        <div class="relative">
            <select
                :id="id"
                v-bind="fieldAttrs"
                :value="modelValue ?? ''"
                :required="required"
                :class="[...inputClasses({ invalid: !!error, size, withSuffix: true }), 'appearance-none cursor-pointer']"
                @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
            >
                <option v-if="placeholder" value="">{{ placeholder }}</option>
                <option v-for="o in normalized" :key="o.value" :value="o.value" :disabled="o.disabled">{{ o.label }}</option>
                <slot />
            </select>
            <PhCaretDown :size="16" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
        </div>
    </Field>
</template>

<script setup lang="ts">
/**
 *   <Select v-model="status" :options="[{ value: 'paid', label: 'Pago' }]" placeholder="Todos" />
 *   <Select v-model="status" :options="{ paid: 'Pago', open: 'Em aberto' }" />
 */
import { computed, useAttrs, useId } from 'vue';
import { PhCaretDown } from '@phosphor-icons/vue';
import Field from './Field.vue';
import { inputClasses } from './inputClasses';

defineOptions({ inheritAttrs: false });

type Option = { value: string | number; label: string; disabled?: boolean };

const props = withDefaults(defineProps<{
    modelValue?: string | number | null;
    options?: Option[] | Record<string, string>;
    label?: string;
    hint?: string;
    error?: string;
    placeholder?: string;
    required?: boolean;
    size?: 'sm' | 'md' | 'lg';
}>(), { modelValue: '', options: () => [], label: '', hint: '', error: '', placeholder: '', required: false, size: 'md' });

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


const normalized = computed<Option[]>(() => Array.isArray(props.options)
    ? props.options
    : Object.entries(props.options).map(([value, label]) => ({ value, label })));
</script>
