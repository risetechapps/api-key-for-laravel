import { reactive } from 'vue';
import type { Component } from 'vue';

/**
 * Comandos da busca rápida (Ctrl+K / ⌘K).
 *
 * O painel registra as páginas do menu sozinho. Um módulo seu registra os
 * próprios comandos e recebe uma função para tirá-los depois:
 *
 *   import { registerCommands } from '@/ui';
 *   const off = registerCommands([
 *       { id: 'reports.new', label: 'Novo relatório', group: 'Relatórios', icon: PhFilePlus, to: '/dashboard/reports/new' },
 *       { id: 'reports.sync', label: 'Sincronizar relatórios', run: () => syncReports() },
 *   ]);
 */
export interface Command {
    id: string;
    label: string;
    group?: string;
    icon?: Component;
    keywords?: string[];
    hint?: string;
    to?: string;
    run?: () => void | Promise<void>;
}

export const commandRegistry = reactive(new Map<string, Command>());
export const paletteState = reactive({ open: false });

export function registerCommands(commands: Command[]): () => void {
    for (const c of commands) commandRegistry.set(c.id, c);
    return () => {
        for (const c of commands) commandRegistry.delete(c.id);
    };
}

export function openCommandPalette(): void {
    paletteState.open = true;
}
