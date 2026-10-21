<template>
    <div class="w-full">
        <div v-if="label || $slots.labelRight" class="flex items-center justify-between mb-1.5">
            <label v-if="label" :for="forId" class="text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ label }}<span v-if="required" class="text-red-500 ml-0.5">*</span>
            </label>
            <slot name="labelRight" />
        </div>
        <slot />
        <Transition name="ui-fade" mode="out-in">
            <p v-if="error" key="e" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ error }}</p>
            <p v-else-if="hint" key="h" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ hint }}</p>
        </Transition>
    </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ label?: string; hint?: string; error?: string; required?: boolean; forId?: string }>(), {
    label: '', hint: '', error: '', required: false, forId: undefined,
});
</script>
