<template>
    <div class="w-full">
        <label v-if="label" :for="inputId" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">
            {{ label }}
            <span v-if="required" class="text-red-500">*</span>
        </label>

        <div class="relative">
            <div v-if="icon" class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <component :is="icon" class="text-slate-400" :size="20" />
            </div>

            <input
                :id="inputId"
                :type="type"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :class="[
                    'block w-full rounded-xl border-2 bg-white dark:bg-slate-800 text-slate-900 dark:text-white',
                    'placeholder:text-slate-400 dark:placeholder:text-slate-500',
                    'focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500',
                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:bg-slate-50 dark:disabled:bg-slate-900',
                    'transition-all duration-200',
                    icon ? 'pl-10' : 'pl-4',
                    error ? 'border-red-300 focus:border-red-500 focus:ring-red-500/20' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600',
                    sizeClasses[size],
                ]"
            />

            <div v-if="$slots.suffix" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <slot name="suffix" />
            </div>
        </div>

        <p v-if="error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{{ hint }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    hint: {
        type: String,
        default: '',
    },
    icon: {
        type: [Object, Function],
        default: null,
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
});

defineEmits(['update:modelValue']);

const inputId = computed(() => `input-${Math.random().toString(36).substr(2, 9)}`);

const sizeClasses = {
    sm: 'py-2 text-sm',
    md: 'py-3 text-base',
    lg: 'py-4 text-lg',
};
</script>
