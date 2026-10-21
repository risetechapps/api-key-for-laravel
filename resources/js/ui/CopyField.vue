<template>
    <div class="flex items-stretch gap-2">
        <div :class="['min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-mono text-xs text-slate-800 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200', wrap ? 'break-all' : 'truncate']">
            {{ masked && !revealed ? '•'.repeat(Math.min(32, value.length)) : value }}
        </div>
        <Button v-if="masked" variant="outline" size="sm" square :icon="revealed ? PhEyeSlash : PhEye" :aria-label="revealed ? 'Ocultar' : 'Mostrar'" class="!h-auto" @click="revealed = !revealed" />
        <Button variant="outline" size="sm" :icon="copied ? PhCheck : PhCopy" class="!h-auto" @click="copy">{{ copied ? 'Copiado' : 'Copiar' }}</Button>
    </div>
</template>

<script setup lang="ts">
/** Valor para copiar (chave de API, segredo, PIX copia e cola). */
import { ref } from 'vue';
import { PhCopy, PhCheck, PhEye, PhEyeSlash } from '@phosphor-icons/vue';
import Button from './Button.vue';
import { toast } from './toast';

const props = withDefaults(defineProps<{ value: string; masked?: boolean; wrap?: boolean }>(), { masked: false, wrap: true });

const copied = ref(false);
const revealed = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        toast.error('Não foi possível copiar', { description: 'Selecione o texto e copie manualmente.' });
    }
}
</script>
