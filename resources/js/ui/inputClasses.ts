/**
 * Classes compartilhadas dos campos de formulário (TextInput, Select,
 * Textarea). Exportadas para quem monta um campo próprio manter o visual.
 */
export function inputClasses(opts: { invalid?: boolean; size?: 'sm' | 'md' | 'lg'; withIcon?: boolean; withSuffix?: boolean } = {}): string[] {
    const sizes = { sm: 'h-9 text-sm', md: 'h-10 text-sm', lg: 'h-12 text-base' };

    return [
        'block w-full rounded-xl border bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs',
        'placeholder:text-slate-400 dark:placeholder:text-slate-500',
        'transition-[border-color,box-shadow] duration-150',
        'focus:outline-none focus:ring-4',
        'disabled:opacity-60 disabled:cursor-not-allowed disabled:bg-slate-50 dark:disabled:bg-slate-800/60',
        opts.invalid
            ? 'border-red-300 focus:border-red-500 focus:ring-red-500/15 dark:border-red-500/60'
            : 'border-slate-200 hover:border-slate-300 focus:border-brand-500 focus:ring-brand-500/15 dark:border-slate-700 dark:hover:border-slate-600',
        sizes[opts.size ?? 'md'],
        opts.withIcon ? 'pl-10' : 'pl-3.5',
        opts.withSuffix ? 'pr-10' : 'pr-3.5',
    ];
}
