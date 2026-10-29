<template>
    <Menu as="div" class="relative inline-block text-left">
        <!-- A posição é medida antes de abrir (clique ou teclado). -->
        <div ref="anchor" class="inline-flex" @click.capture="track" @keydown.capture="onKeydown">
            <MenuButton as="template">
                <slot name="trigger">
                    <button type="button" class="p-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800 dark:hover:text-white transition-colors" :aria-label="label">
                        <PhDotsThree :size="20" weight="bold" />
                    </button>
                </slot>
            </MenuButton>
        </div>

        <!-- No body, e não dentro do gatilho: numa tabela com overflow o menu
             ficava preso dentro dela, criava barra de rolagem e sumia ao rolar. -->
        <Teleport to="body">
            <transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <MenuItems
                    ref="menu"
                    :style="position"
                    :class="[
                        'fixed z-[60] min-w-48 rounded-xl bg-white dark:bg-slate-900 p-1.5 shadow-xl ring-1 ring-slate-900/5 dark:ring-white/10 focus:outline-none',
                        placement === 'top'
                            ? (align === 'right' ? 'origin-bottom-right' : 'origin-bottom-left')
                            : (align === 'right' ? 'origin-top-right' : 'origin-top-left'),
                    ]"
                >
                    <slot>
                        <template v-for="(item, i) in items" :key="i">
                            <div v-if="item.divider" class="my-1.5 h-px bg-slate-100 dark:bg-slate-800" />
                            <MenuItem v-else v-slot="{ active }" :disabled="item.disabled">
                                <component
                                    :is="item.to ? RouterLink : 'button'"
                                    :to="item.to"
                                    :type="item.to ? undefined : 'button'"
                                    :class="[
                                        'group flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition-colors',
                                        item.danger ? (active ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'text-red-600 dark:text-red-400') : (active ? 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-white' : 'text-slate-700 dark:text-slate-300'),
                                        item.disabled ? 'opacity-50 cursor-not-allowed' : '',
                                    ]"
                                    @click="item.onClick?.()"
                                >
                                    <component :is="item.icon" v-if="item.icon" :size="16" />
                                    <span class="flex-1 text-left">{{ item.label }}</span>
                                    <kbd v-if="item.shortcut" class="text-[10px] text-slate-400">{{ item.shortcut }}</kbd>
                                </component>
                            </MenuItem>
                        </template>
                    </slot>
                </MenuItems>
            </transition>
        </Teleport>
    </Menu>
</template>

<script setup lang="ts">
/**
 *   <Dropdown :items="[{ label: 'Editar', icon: PhPencil, onClick: edit }, { divider: true }, { label: 'Excluir', danger: true, onClick: remove }]" />
 */
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue';
import { RouterLink } from 'vue-router';
import { PhDotsThree } from '@phosphor-icons/vue';
import { nextTick, onBeforeUnmount, ref } from 'vue';
import type { Component } from 'vue';

export interface DropdownItem {
    label?: string;
    icon?: Component;
    onClick?: () => void;
    to?: string;
    danger?: boolean;
    disabled?: boolean;
    divider?: boolean;
    shortcut?: string;
}

const props = withDefaults(defineProps<{ items?: DropdownItem[]; align?: 'left' | 'right'; label?: string }>(), {
    items: () => [],
    align: 'right',
    label: 'Mais ações',
});

const GAP = 8;
const MARGIN = 8;

const anchor = ref<HTMLElement | null>(null);
const menu = ref<{ $el?: HTMLElement } | null>(null);
const position = ref<Record<string, string>>({});
const placement = ref<'bottom' | 'top'>('bottom');

function menuEl(): HTMLElement | null {
    const el = menu.value?.$el;
    return el instanceof HTMLElement ? el : null;
}

// Fixo na tela, colado ao gatilho; abre para cima quando não cabe embaixo.
function place() {
    const trigger = anchor.value?.getBoundingClientRect();
    if (!trigger) return;

    const height = menuEl()?.offsetHeight ?? 0;
    const fitsBelow = trigger.bottom + GAP + height <= window.innerHeight - MARGIN;
    const fitsAbove = trigger.top - GAP - height >= MARGIN;
    placement.value = !fitsBelow && fitsAbove ? 'top' : 'bottom';

    const top = placement.value === 'top' ? trigger.top - GAP - height : trigger.bottom + GAP;
    const horizontal = props.align === 'right'
        ? { right: `${Math.max(MARGIN, window.innerWidth - trigger.right)}px` }
        : { left: `${Math.max(MARGIN, trigger.left)}px` };

    position.value = { top: `${top}px`, ...horizontal };
}

// Enquanto aberto, acompanha a rolagem (de qualquer contêiner, inclusive a
// tabela) e o redimensionamento. Fechado, solta os listeners.
let listening = false;

function onMove() {
    if (!menuEl()) {
        stop();
        return;
    }
    place();
}

function stop() {
    if (!listening) return;
    window.removeEventListener('scroll', onMove, true);
    window.removeEventListener('resize', onMove);
    listening = false;
}

async function track() {
    place();
    // Depois de renderizar o menu sabemos a altura dele e se cabe embaixo.
    await nextTick();
    if (!menuEl()) return;
    place();
    if (!listening) {
        window.addEventListener('scroll', onMove, { capture: true, passive: true });
        window.addEventListener('resize', onMove, { passive: true });
        listening = true;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) track();
}

onBeforeUnmount(stop);
</script>
