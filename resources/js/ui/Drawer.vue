<template>
    <TransitionRoot :show="modelValue" as="template">
        <Dialog as="div" class="relative z-50" @close="close">
            <TransitionChild as="template" enter="duration-300 ease-out" enter-from="opacity-0" enter-to="opacity-100" leave="duration-200 ease-in" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-slate-950/40 backdrop-blur-[2px]" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-hidden">
                <div :class="['pointer-events-none fixed inset-y-0 flex max-w-full', side === 'left' ? 'left-0 pr-10' : 'right-0 pl-10']">
                    <TransitionChild
                        as="template"
                        enter="transform transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                        :enter-from="side === 'left' ? '-translate-x-full' : 'translate-x-full'"
                        enter-to="translate-x-0"
                        leave="transform transition duration-200 ease-in"
                        leave-from="translate-x-0"
                        :leave-to="side === 'left' ? '-translate-x-full' : 'translate-x-full'"
                    >
                        <DialogPanel :class="['pointer-events-auto w-screen flex flex-col bg-white dark:bg-slate-900 shadow-2xl ring-1 ring-slate-900/5 dark:ring-white/10', widths[size]]">
                            <div v-if="title || $slots.title" class="flex items-start justify-between gap-4 px-6 py-5 border-b border-slate-100 dark:border-slate-800">
                                <div class="min-w-0">
                                    <DialogTitle class="text-base font-semibold text-slate-900 dark:text-white"><slot name="title">{{ title }}</slot></DialogTitle>
                                    <p v-if="description" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ description }}</p>
                                </div>
                                <button type="button" class="p-2 -mr-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Fechar" @click="close">
                                    <PhX :size="18" weight="bold" />
                                </button>
                            </div>
                            <div :class="['flex-1 overflow-y-auto', padded ? 'px-6 py-5' : '']"><slot /></div>
                            <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2"><slot name="footer" /></div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup lang="ts">
/** Painel lateral para detalhes e formulários sem sair da página. */
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { PhX } from '@phosphor-icons/vue';

withDefaults(defineProps<{
    modelValue: boolean;
    title?: string;
    description?: string;
    side?: 'left' | 'right';
    size?: 'sm' | 'md' | 'lg';
    padded?: boolean;
}>(), { title: '', description: '', side: 'right', size: 'md', padded: true });

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

const widths = { sm: 'max-w-xs', md: 'max-w-md', lg: 'max-w-2xl' };

function close() {
    emit('update:modelValue', false);
}
</script>
