<template>
    <div v-if="lastPage > 1" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            <template v-if="total !== null">{{ total.toLocaleString('pt-BR') }} {{ label }}</template>
            <template v-else>Página {{ page }} de {{ lastPage }}</template>
        </p>
        <nav class="flex items-center gap-1" aria-label="Paginação">
            <button type="button" :class="btn" :disabled="page <= 1" aria-label="Anterior" @click="go(page - 1)"><PhCaretLeft :size="14" weight="bold" /></button>
            <template v-for="p in pages" :key="p">
                <span v-if="typeof p === 'string'" class="px-1.5 text-slate-400">…</span>
                <button v-else type="button" :class="[btn, p === page ? 'bg-brand-600 text-white border-brand-600 hover:bg-brand-600 dark:border-brand-600' : '']" :aria-current="p === page ? 'page' : undefined" @click="go(p)">{{ p }}</button>
            </template>
            <button type="button" :class="btn" :disabled="page >= lastPage" aria-label="Próxima" @click="go(page + 1)"><PhCaretRight :size="14" weight="bold" /></button>
        </nav>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { PhCaretLeft, PhCaretRight } from '@phosphor-icons/vue';

const props = withDefaults(defineProps<{ page: number; lastPage: number; total?: number | null; label?: string }>(), {
    total: null, label: 'itens',
});

const emit = defineEmits<{ change: [page: number] }>();

const btn = 'min-w-9 h-9 px-2 inline-flex items-center justify-center rounded-lg border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none transition-colors tabular-nums';

const pages = computed<(number | string)[]>(() => {
    const { page, lastPage } = props;
    const set = new Set([1, lastPage, page - 1, page, page + 1].filter((p) => p >= 1 && p <= lastPage));
    const sorted = [...set].sort((a, b) => a - b);
    const out: (number | string)[] = [];
    sorted.forEach((p, i) => {
        if (i > 0 && p - sorted[i - 1]! > 1) out.push(`gap-${p}`);
        out.push(p);
    });
    return out;
});

function go(p: number) {
    if (p >= 1 && p <= props.lastPage && p !== props.page) emit('change', p);
}
</script>
