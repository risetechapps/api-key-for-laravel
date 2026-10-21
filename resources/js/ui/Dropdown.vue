<template>
    <Menu as="div" class="relative inline-block text-left">
        <MenuButton as="template">
            <slot name="trigger">
                <button type="button" class="p-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800 dark:hover:text-white transition-colors" :aria-label="label">
                    <PhDotsThree :size="20" weight="bold" />
                </button>
            </slot>
        </MenuButton>

        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 scale-95 -translate-y-1"
            enter-to-class="opacity-100 scale-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <MenuItems :class="['absolute z-40 mt-2 min-w-48 rounded-xl bg-white dark:bg-slate-900 p-1.5 shadow-xl ring-1 ring-slate-900/5 dark:ring-white/10 focus:outline-none', align === 'right' ? 'right-0 origin-top-right' : 'left-0 origin-top-left']">
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
    </Menu>
</template>

<script setup lang="ts">
/**
 *   <Dropdown :items="[{ label: 'Editar', icon: PhPencil, onClick: edit }, { divider: true }, { label: 'Excluir', danger: true, onClick: remove }]" />
 */
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue';
import { RouterLink } from 'vue-router';
import { PhDotsThree } from '@phosphor-icons/vue';
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

withDefaults(defineProps<{ items?: DropdownItem[]; align?: 'left' | 'right'; label?: string }>(), {
    items: () => [],
    align: 'right',
    label: 'Mais ações',
});
</script>
