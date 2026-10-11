<template>
    <Field v-bind="rootAttrs" :label="label" :hint="hint" :error="error" :required="required" :for-id="id">
        <div class="relative">
            <component :is="icon" v-if="icon" :size="18" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
            <input
                :id="id"
                ref="inputEl"
                v-bind="fieldAttrs"
                :type="type"
                :value="modelValue"
                :required="required"
                :aria-invalid="!!error || undefined"
                :class="[...inputClasses({ invalid: !!error, size, withIcon: !!icon, withSuffix: !!$slots.suffix }), mono ? 'font-mono' : '']"
                @input="onInput"
            />
            <div v-if="$slots.suffix" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <slot name="suffix" />
            </div>
        </div>
    </Field>
</template>

<script setup lang="ts">
/**
 *   <TextInput v-model="form.email" label="E-mail" type="email" :icon="PhEnvelope" :error="errors.email" />
 */
import { computed, ref, useAttrs, useId } from 'vue';
import type { Component } from 'vue';
import Field from './Field.vue';
import { inputClasses } from './inputClasses';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    modelValue?: string | number | null;
    label?: string;
    hint?: string;
    error?: string;
    type?: string;
    required?: boolean;
    icon?: Component | null;
    size?: 'sm' | 'md' | 'lg';
    mono?: boolean;
    uppercase?: boolean;
}>(), { modelValue: '', label: '', hint: '', error: '', type: 'text', required: false, icon: null, size: 'md', mono: false, uppercase: false });

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

const inputEl = ref<HTMLInputElement | null>(null);

function onInput(event: Event) {
    const target = event.target as HTMLInputElement;
    let value = target.value;
    if (props.uppercase) {
        value = value.toUpperCase();
        target.value = value;
    }
    emit('update:modelValue', value);
}

defineExpose({ focus: () => inputEl.value?.focus() });
</script>
