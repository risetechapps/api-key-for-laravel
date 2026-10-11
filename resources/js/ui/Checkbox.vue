<template>
    <label :class="['inline-flex items-start gap-2.5 cursor-pointer select-none', disabled ? 'opacity-60 cursor-not-allowed' : '']">
        <input
            type="checkbox"
            :checked="modelValue"
            :disabled="disabled"
            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 accent-[var(--brand)] focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800"
            @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />
        <span v-if="label || $slots.default" class="text-sm text-slate-700 dark:text-slate-300"><slot>{{ label }}</slot></span>
    </label>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ modelValue: boolean; label?: string; disabled?: boolean }>(), { label: '', disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>
