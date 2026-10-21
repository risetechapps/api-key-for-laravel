<template>
    <TransitionRoot :show="paletteState.open" as="template" @after-leave="query = ''">
        <Dialog as="div" class="relative z-[55]" @close="paletteState.open = false">
            <TransitionChild as="template" enter="duration-200 ease-out" enter-from="opacity-0" enter-to="opacity-100" leave="duration-150 ease-in" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-slate-950/50 backdrop-blur-sm" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto p-4 pt-[12vh]">
                <TransitionChild as="template" enter="duration-200 ease-[cubic-bezier(0.22,1,0.36,1)]" enter-from="opacity-0 scale-[0.97] -translate-y-2" enter-to="opacity-100 scale-100 translate-y-0" leave="duration-100 ease-in" leave-from="opacity-100 scale-100" leave-to="opacity-0 scale-[0.97]">
                    <DialogPanel class="mx-auto max-w-xl overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-2xl ring-1 ring-slate-900/5 dark:ring-white/10">
                        <div class="relative border-b border-slate-100 dark:border-slate-800">
                            <PhMagnifyingGlass :size="18" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input
                                ref="inputEl"
                                v-model="query"
                                type="text"
                                placeholder="Buscar páginas e ações…"
                                class="h-14 w-full bg-transparent pl-11 pr-16 text-sm text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none"
                                @keydown.down.prevent="move(1)"
                                @keydown.up.prevent="move(-1)"
                                @keydown.enter.prevent="runActive"
                            />
                            <kbd class="absolute right-4 top-1/2 -translate-y-1/2 rounded-md border border-slate-200 dark:border-slate-700 px-1.5 py-0.5 text-[10px] font-medium text-slate-400">ESC</kbd>
                        </div>

                        <div ref="listEl" class="max-h-[60vh] overflow-y-auto p-2">
                            <p v-if="!results.length" class="px-4 py-10 text-center text-sm text-slate-500">Nada encontrado para “{{ query }}”.</p>
                            <template v-for="group in grouped" :key="group.name">
                                <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ group.name }}</p>
                                <button
                                    v-for="item in group.items"
                                    :key="item.id"
                                    type="button"
                                    :data-active="flat.indexOf(item) === active"
                                    :class="['flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition-colors', flat.indexOf(item) === active ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'text-slate-700 dark:text-slate-300']"
                                    @mousemove="active = flat.indexOf(item)"
                                    @click="run(item)"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800">
                                        <component :is="item.icon ?? PhArrowRight" :size="16" />
                                    </span>
                                    <span class="flex-1 truncate">{{ item.label }}</span>
                                    <span v-if="item.hint" class="text-xs text-slate-400">{{ item.hint }}</span>
                                    <PhArrowElbowDownLeft v-if="flat.indexOf(item) === active" :size="14" class="text-slate-400" />
                                </button>
                            </template>
                        </div>

                        <div class="flex items-center gap-4 border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 text-[11px] text-slate-400">
                            <span><kbd class="font-sans">↑↓</kbd> navegar</span>
                            <span><kbd class="font-sans">↵</kbd> abrir</span>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Dialog, DialogPanel, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { PhMagnifyingGlass, PhArrowRight, PhArrowElbowDownLeft } from '@phosphor-icons/vue';
import { commandRegistry, paletteState, type Command } from './commands';

const router = useRouter();
const query = ref('');
const active = ref(0);
const inputEl = ref<HTMLInputElement | null>(null);
const listEl = ref<HTMLElement | null>(null);

function normalize(s: string): string {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const results = computed(() => {
    const q = normalize(query.value.trim());
    const all = [...commandRegistry.values()];
    if (!q) return all;
    return all
        .map((c) => {
            const hay = normalize([c.label, c.group ?? '', ...(c.keywords ?? [])].join(' '));
            const label = normalize(c.label);
            const score = label.startsWith(q) ? 3 : label.includes(q) ? 2 : hay.includes(q) ? 1 : 0;
            return { c, score };
        })
        .filter((r) => r.score > 0)
        .sort((a, b) => b.score - a.score)
        .map((r) => r.c);
});

const grouped = computed(() => {
    const map = new Map<string, Command[]>();
    for (const c of results.value) {
        const g = c.group ?? 'Ações';
        if (!map.has(g)) map.set(g, []);
        map.get(g)!.push(c);
    }
    return [...map.entries()].map(([name, items]) => ({ name, items }));
});

const flat = computed(() => grouped.value.flatMap((g) => g.items));

watch(query, () => (active.value = 0));
watch(() => paletteState.open, (open) => open && nextTick(() => inputEl.value?.focus()));

function move(delta: number) {
    const n = flat.value.length;
    if (!n) return;
    active.value = (active.value + delta + n) % n;
    nextTick(() => listEl.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }));
}

async function run(item: Command) {
    paletteState.open = false;
    if (item.to) await router.push(item.to);
    if (item.run) await item.run();
}

function runActive() {
    const item = flat.value[active.value];
    if (item) run(item);
}

function onKey(event: KeyboardEvent) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteState.open = !paletteState.open;
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>
