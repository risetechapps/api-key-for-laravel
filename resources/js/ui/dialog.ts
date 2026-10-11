import { reactive } from 'vue';

/**
 * Confirmação e pergunta com API de promessa, no visual do painel.
 *
 *   if (await confirm({ title: 'Estornar pagamento?', tone: 'danger', confirmText: 'Estornar' })) { … }
 *
 *   const days = await prompt({ title: 'Estender vencimento', input: { type: 'number', value: 7, label: 'Dias' } });
 *   if (days !== null) { … }
 */
export interface DialogInput {
    type?: 'text' | 'number' | 'select' | 'password' | 'textarea';
    label?: string;
    placeholder?: string;
    value?: string | number;
    options?: Record<string, string>;
    min?: number;
    max?: number;
    required?: boolean;
}

export interface DialogOptions {
    title: string;
    description?: string;
    tone?: 'default' | 'danger' | 'warning';
    confirmText?: string;
    cancelText?: string;
    input?: DialogInput;
    /** Roda ao confirmar; lançar Error mantém o diálogo aberto com a mensagem. */
    onConfirm?: (value: string | null) => Promise<void> | void;
}

export interface DialogState extends DialogOptions {
    open: boolean;
    value: string;
    error: string;
    busy: boolean;
    resolve: ((value: string | boolean | null) => void) | null;
}

export const dialogState = reactive<DialogState>({
    open: false, title: '', value: '', error: '', busy: false, resolve: null,
});

function show(opts: DialogOptions): Promise<string | boolean | null> {
    dialogState.resolve?.(opts.input ? null : false);

    return new Promise((resolve) => {
        Object.assign(dialogState, {
            description: undefined, tone: 'default', confirmText: undefined, cancelText: undefined, input: undefined, onConfirm: undefined,
            ...opts,
            open: true,
            value: opts.input?.value !== undefined ? String(opts.input.value) : '',
            error: '',
            busy: false,
            resolve,
        });
    });
}

export async function confirm(opts: DialogOptions | string): Promise<boolean> {
    const options = typeof opts === 'string' ? { title: opts } : opts;
    return (await show({ ...options, input: undefined })) === true;
}

export async function prompt(opts: DialogOptions): Promise<string | null> {
    const result = await show({ ...opts, input: opts.input ?? { type: 'text' } });
    return typeof result === 'string' ? result : null;
}
