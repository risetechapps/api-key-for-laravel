<template>
    <Modal :model-value="dialogState.open" size="sm" :closable="!dialogState.busy" @update:model-value="(v) => !v && cancel()">
        <div class="flex items-start gap-4">
            <span v-if="dialogState.tone !== 'default'" :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', dialogState.tone === 'danger' ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300' : 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300']">
                <PhWarning :size="20" weight="bold" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ dialogState.title }}</h3>
                <p v-if="dialogState.description" class="mt-1.5 text-sm text-slate-500 dark:text-slate-400 whitespace-pre-line">{{ dialogState.description }}</p>

                <form v-if="dialogState.input" class="mt-4" @submit.prevent="accept">
                    <Select
                        v-if="dialogState.input.type === 'select'"
                        v-model="dialogState.value"
                        :label="dialogState.input.label"
                        :options="dialogState.input.options ?? {}"
                        :error="dialogState.error"
                    />
                    <Textarea
                        v-else-if="dialogState.input.type === 'textarea'"
                        v-model="dialogState.value"
                        :label="dialogState.input.label"
                        :placeholder="dialogState.input.placeholder"
                        :error="dialogState.error"
                    />
                    <TextInput
                        v-else
                        ref="inputRef"
                        v-model="dialogState.value"
                        :type="dialogState.input.type ?? 'text'"
                        :label="dialogState.input.label"
                        :placeholder="dialogState.input.placeholder"
                        :min="dialogState.input.min"
                        :max="dialogState.input.max"
                        :error="dialogState.error"
                        autofocus
                    />
                </form>
                <p v-else-if="dialogState.error" class="mt-3 text-sm font-medium text-red-600 dark:text-red-400">{{ dialogState.error }}</p>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" :disabled="dialogState.busy" @click="cancel">{{ dialogState.cancelText ?? 'Cancelar' }}</Button>
            <Button :variant="dialogState.tone === 'danger' ? 'danger' : 'primary'" :loading="dialogState.busy" @click="accept">{{ dialogState.confirmText ?? 'Confirmar' }}</Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { PhWarning } from '@phosphor-icons/vue';
import Modal from './Modal.vue';
import Button from './Button.vue';
import TextInput from './TextInput.vue';
import Select from './Select.vue';
import Textarea from './Textarea.vue';
import { dialogState } from './dialog';

function finish(value: string | boolean | null) {
    const resolve = dialogState.resolve;
    dialogState.open = false;
    dialogState.resolve = null;
    resolve?.(value);
}

function cancel() {
    if (dialogState.busy) return;
    finish(dialogState.input ? null : false);
}

async function accept() {
    const input = dialogState.input;
    const value = input ? String(dialogState.value ?? '').trim() : null;

    if (input?.required !== false && input && value === '') {
        dialogState.error = 'Preencha o campo.';
        return;
    }
    if (input?.type === 'number' && value !== null) {
        const n = Number(value);
        if (Number.isNaN(n) || (input.min !== undefined && n < input.min) || (input.max !== undefined && n > input.max)) {
            dialogState.error = input.min !== undefined && input.max !== undefined ? `Informe um número entre ${input.min} e ${input.max}.` : 'Número inválido.';
            return;
        }
    }

    if (dialogState.onConfirm) {
        dialogState.busy = true;
        dialogState.error = '';
        try {
            await dialogState.onConfirm(value);
        } catch (err: any) {
            dialogState.error = err?.response?.data?.message || err?.message || 'Não foi possível concluir.';
            dialogState.busy = false;
            return;
        }
        dialogState.busy = false;
    }

    finish(input ? (value ?? '') : true);
}
</script>
