/**
 * Formatação compartilhada do painel (pt-BR).
 */
export function money(value: number | string | null | undefined, currency = 'BRL'): string {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency });
}

export function number(value: number | string | null | undefined, digits = 0): string {
    return Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

export function compact(value: number | string | null | undefined): string {
    return Number(value || 0).toLocaleString('pt-BR', { notation: 'compact', maximumFractionDigits: 1 });
}

export function date(iso: string | Date | null | undefined, withTime = false): string {
    if (!iso) return '—';
    const d = iso instanceof Date ? iso : new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return withTime
        ? d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
        : d.toLocaleDateString('pt-BR');
}

/** "há 3 minutos", "em 2 dias". */
export function relative(iso: string | Date | null | undefined): string {
    if (!iso) return '—';
    const d = iso instanceof Date ? iso : new Date(iso);
    const seconds = Math.round((d.getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }
    return rtf.format(seconds, 'second');
}
