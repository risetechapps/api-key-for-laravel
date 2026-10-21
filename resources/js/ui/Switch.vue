<template>
    <SwitchGroup as="div" class="flex items-start justify-between gap-4">
        <span v-if="label || description" class="flex-1 min-w-0">
            <SwitchLabel v-if="label" as="span" class="block text-sm font-medium text-slate-900 dark:text-white cursor-pointer" passive>{{ label }}</SwitchLabel>
            <SwitchDescription v-if="description" as="span" class="block text-sm text-slate-500 dark:text-slate-400">{{ description }}</SwitchDescription>
        </span>
        <HSwitch
            :model-value="modelValue"
            :disabled="disabled"
            :class="[
                modelValue ? 'bg-brand-600' : 'bg-slate-200 dark:bg-slate-700',
                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900',
                'disabled:opacity-50 disabled:cursor-not-allowed',
            ]"
            @update:model-value="(v: boolean) => emit('update:modelValue', v)"
        >
            <span
                aria-hidden="true"
                :class="[
                    modelValue ? 'translate-x-5' : 'translate-x-0',
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-[cubic-bezier(0.22,1,0.36,1)]',
                ]"
            />
        </HSwitch>
    </SwitchGroup>
</template>

<script setup lang="ts">
import { Switch as HSwitch, SwitchGroup, SwitchLabel, SwitchDescription } from '@headlessui/vue';

withDefaults(defineProps<{ modelValue: boolean; label?: string; description?: string; disabled?: boolean }>(), {
    label: '', description: '', disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>
