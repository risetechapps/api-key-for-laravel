import { reactive } from 'vue';

/**
 * Avisos rápidos no canto da tela. Não interrompem: somem sozinhos.
 *
 *   import { toast } from '@/ui';
 *   toast.success('Cartão salvo');
 *   toast.error('Não foi possível salvar', { description: err.message });
 *
 * Para confirmações que pedem decisão, use `confirm()`.
 */
export type ToastTone = 'success' | 'error' | 'info' | 'warning';

export interface ToastItem {
    id: number;
    tone: ToastTone;
    title: string;
    description?: string;
    duration: number;
    action?: { label: string; onClick: () => void };
}

export interface ToastOptions {
    description?: string;
    duration?: number;
    action?: { label: string; onClick: () => void };
}

export const toasts = reactive<ToastItem[]>([]);

let seq = 0;
const timers = new Map<number, ReturnType<typeof setTimeout>>();

function push(tone: ToastTone, title: string, opts: ToastOptions = {}): number {
    const id = ++seq;
    const duration = opts.duration ?? (tone === 'error' ? 6000 : 3500);
    toasts.push({ id, tone, title, description: opts.description, duration, action: opts.action });
    if (toasts.length > 5) dismiss(toasts[0]!.id);
    if (duration > 0) timers.set(id, setTimeout(() => dismiss(id), duration));
    return id;
}

export function dismiss(id: number): void {
    clearTimeout(timers.get(id));
    timers.delete(id);
    const index = toasts.findIndex((t) => t.id === id);
    if (index >= 0) toasts.splice(index, 1);
}

export const toast = {
    success: (title: string, opts?: ToastOptions) => push('success', title, opts),
    error: (title: string, opts?: ToastOptions) => push('error', title, opts),
    info: (title: string, opts?: ToastOptions) => push('info', title, opts),
    warning: (title: string, opts?: ToastOptions) => push('warning', title, opts),
    dismiss,
};

/** Mensagem de erro de uma resposta do axios, com reserva. */
export function errorMessage(err: any, fallback = 'Não foi possível concluir. Tente novamente.'): string {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === 'object') {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first[0]) return String(first[0]);
    }
    return err?.response?.data?.message || err?.message || fallback;
}
