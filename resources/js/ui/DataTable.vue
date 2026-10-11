<template>
    <div>
        <!-- Tabela (telas médias para cima) -->
        <div :class="['overflow-x-auto', stackOnMobile ? 'hidden md:block' : '']">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800">
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            scope="col"
                            :class="['py-3 px-4 first:pl-0 last:pr-0 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 whitespace-nowrap', alignClass(col.align), col.headerClass]"
                            :aria-sort="sortKey === col.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : undefined"
                        >
                            <button v-if="col.sortable" type="button" class="inline-flex items-center gap-1 hover:text-slate-800 dark:hover:text-slate-200 transition-colors" @click="toggleSort(col.key)">
                                {{ col.label }}
                                <PhCaretUpDown v-if="sortKey !== col.key" :size="12" class="opacity-50" />
                                <PhCaretUp v-else-if="sortDir === 'asc'" :size="12" weight="bold" class="text-brand-600" />
                                <PhCaretDown v-else :size="12" weight="bold" class="text-brand-600" />
                            </button>
                            <template v-else>{{ col.label }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                    <template v-if="loading">
                        <tr v-for="i in skeletonRows" :key="`s${i}`">
                            <td v-for="col in columns" :key="col.key" class="py-3.5 px-4 first:pl-0 last:pr-0"><Skeleton height="0.875rem" :width="i % 2 ? '70%' : '50%'" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="!sortedRows.length">
                        <td :colspan="columns.length">
                            <slot name="empty"><EmptyState :title="emptyTitle" :description="emptyText" :icon="emptyIcon" compact /></slot>
                        </td>
                    </tr>
                    <template v-else>
                        <tr
                            v-for="(row, index) in sortedRows"
                            :key="rowId(row, index)"
                            :class="['group transition-colors', clickable ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/40' : 'hover:bg-slate-50/60 dark:hover:bg-slate-800/30']"
                            @click="clickable && emit('row-click', row)"
                        >
                            <td v-for="col in columns" :key="col.key" :class="['py-3.5 px-4 first:pl-0 last:pr-0 text-slate-700 dark:text-slate-300', alignClass(col.align), col.class]">
                                <slot :name="`cell-${col.key}`" :row="row" :value="get(row, col.key)" :index="index">{{ col.format ? col.format(get(row, col.key), row) : (get(row, col.key) ?? '—') }}</slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Cartões (celular) -->
        <div v-if="stackOnMobile" class="md:hidden space-y-3">
            <template v-if="loading">
                <div v-for="i in 3" :key="`m${i}`" class="rounded-xl border border-slate-200 dark:border-slate-800 p-4"><Skeleton :lines="3" /></div>
            </template>
            <template v-else-if="!sortedRows.length">
                <slot name="empty"><EmptyState :title="emptyTitle" :description="emptyText" :icon="emptyIcon" compact /></slot>
            </template>
            <template v-else>
                <div
                    v-for="(row, index) in sortedRows"
                    :key="rowId(row, index)"
                    class="rounded-xl border border-slate-200 dark:border-slate-800 p-4 space-y-2 active:bg-slate-50 dark:active:bg-slate-800/40"
                    @click="clickable && emit('row-click', row)"
                >
                    <div v-for="col in columns" :key="col.key" class="flex items-start justify-between gap-4 text-sm">
                        <span v-if="col.label" class="text-xs font-medium uppercase tracking-wider text-slate-400 pt-0.5">{{ col.label }}</span>
                        <span class="text-right text-slate-700 dark:text-slate-300 min-w-0">
                            <slot :name="`cell-${col.key}`" :row="row" :value="get(row, col.key)" :index="index">{{ col.format ? col.format(get(row, col.key), row) : (get(row, col.key) ?? '—') }}</slot>
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts" generic="T extends Record<string, any>">
/**
 * Tabela do painel: ordenação, carregamento, estado vazio e, no celular,
 * cada linha vira um cartão. Célula customizada pelo slot `cell-<coluna>`.
 *
 *   <DataTable :columns="[{ key: 'name', label: 'Nome', sortable: true }, { key: 'amount', label: 'Valor', align: 'right', format: money }]"
 *              :rows="rows" :loading="loading" row-key="id">
 *       <template #cell-name="{ row }"><strong>{{ row.name }}</strong></template>
 *   </DataTable>
 *
 * Com `sort-mode="server"` a tabela só emite `sort` e não reordena sozinha.
 */
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { PhCaretUp, PhCaretDown, PhCaretUpDown } from '@phosphor-icons/vue';
import Skeleton from './Skeleton.vue';
import EmptyState from './EmptyState.vue';

export interface Column<R = any> {
    key: string;
    label: string;
    align?: 'left' | 'center' | 'right';
    sortable?: boolean;
    class?: string;
    headerClass?: string;
    format?: (value: any, row: R) => string;
}

const props = withDefaults(defineProps<{
    columns: Column<T>[];
    rows: T[];
    loading?: boolean;
    rowKey?: string | ((row: T) => string | number);
    emptyTitle?: string;
    emptyText?: string;
    emptyIcon?: Component;
    clickable?: boolean;
    stackOnMobile?: boolean;
    sortMode?: 'client' | 'server';
    skeletonRows?: number;
}>(), {
    loading: false,
    rowKey: 'id',
    emptyTitle: 'Nada por aqui ainda',
    emptyText: '',
    emptyIcon: undefined,
    clickable: false,
    stackOnMobile: true,
    sortMode: 'client',
    skeletonRows: 5,
});

const emit = defineEmits<{ 'row-click': [row: T]; sort: [key: string, dir: 'asc' | 'desc'] }>();

const sortKey = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');

function get(row: T, key: string): any {
    return key.split('.').reduce<any>((v, k) => (v == null ? v : v[k]), row);
}

function rowId(row: T, index: number): string | number {
    if (typeof props.rowKey === 'function') return props.rowKey(row);
    return get(row, props.rowKey) ?? index;
}

function alignClass(align?: string): string {
    return align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';
}

function toggleSort(key: string) {
    if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
    emit('sort', key, sortDir.value);
}

const sortedRows = computed(() => {
    if (props.sortMode === 'server' || !sortKey.value) return props.rows;
    const key = sortKey.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...props.rows].sort((a, b) => {
        const x = get(a, key);
        const y = get(b, key);
        if (x == null) return 1;
        if (y == null) return -1;
        return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'pt-BR', { numeric: true })) * dir;
    });
});
</script>
