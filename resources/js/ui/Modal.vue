<template>
    <TransitionRoot appear :show="open" as="template">
        <Dialog as="div" class="relative z-50" :initial-focus="initialFocus" @close="onClose">
            <TransitionChild as="template" enter="duration-300 ease-out" enter-from="opacity-0" enter-to="opacity-100" leave="duration-200 ease-in" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-slate-950/50 backdrop-blur-[2px]" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto">
                <div class="flex min-h-full items-end sm:items-center justify-center p-0 sm:p-4">
                    <TransitionChild
                        as="template"
                        enter="duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                        enter-from="opacity-0 translate-y-6 sm:translate-y-2 sm:scale-[0.97]"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="duration-150 ease-in"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-[0.97]"
                    >
                        <DialogPanel :class="['w-full transform bg-white dark:bg-slate-900 text-left shadow-2xl ring-1 ring-slate-900/5 dark:ring-white/10 rounded-t-3xl sm:rounded-2xl max-h-[92vh] flex flex-col', widths[size]]">
                            <div v-if="title || $slots.title" class="flex items-start justify-between gap-4 px-6 pt-6">
                                <div class="min-w-0">
                                    <DialogTitle as="h3" class="text-lg font-semibold text-slate-900 dark:text-white">
                                        <slot name="title">{{ title }}</slot>
                                    </DialogTitle>
                                    <DialogDescription v-if="description" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ description }}</DialogDescription>
                                </div>
                                <button
                                    v-if="closable"
                                    type="button"
                                    class="-mr-2 -mt-1 p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
                                    aria-label="Fechar"
                                    @click="onClose"
                                >
                                    <PhX :size="18" weight="bold" />
                                </button>
                            </div>

                            <div class="px-6 py-5 overflow-y-auto">
                                <slot />
                            </div>

                            <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                                <slot name="footer" />
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup lang="ts">
/**
 * Modal acessível (foco preso, Esc fecha). No celular abre como folha de baixo.
 *
 *   <Modal v-model="open" title="Novo cupom" size="lg">
 *       …
 *       <template #footer><Button @click="save">Salvar</Button></template>
 *   </Modal>
 */
import { computed } from 'vue';
import { Dialog, DialogPanel, DialogTitle, DialogDescription, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { PhX } from '@phosphor-icons/vue';

const props = withDefaults(defineProps<{
    modelValue: boolean;
    title?: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    closable?: boolean;
    initialFocus?: HTMLElement | null;
}>(), { title: '', description: '', size: 'md', closable: true, initialFocus: null });

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; close: [] }>();

const open = computed(() => props.modelValue);

const widths = { sm: 'sm:max-w-sm', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl', '2xl': 'sm:max-w-6xl' };

function onClose() {
    if (!props.closable) return;
    emit('update:modelValue', false);
    emit('close');
}
</script>
