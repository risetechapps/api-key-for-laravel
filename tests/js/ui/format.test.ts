import { afterEach, describe, expect, it, vi } from 'vitest';
import { date, money, relative } from '@/ui/format';

// Intl separa "R$" do valor com espaço não separável.
const normalize = (s: string) => s.replace(/ /g, ' ');

describe('format', () => {
    afterEach(() => vi.useRealTimers());

    it('formats money in BRL and treats empty as zero', () => {
        expect(normalize(money(1234.5))).toBe('R$ 1.234,50');
        expect(normalize(money(null))).toBe('R$ 0,00');
        expect(normalize(money('19.9'))).toBe('R$ 19,90');
    });

    it('shows a dash for a missing or invalid date', () => {
        expect(date(null)).toBe('—');
        expect(date('não é data')).toBe('—');
        expect(date('2026-10-21T12:00:00')).toBe('21/10/2026');
    });

    it('describes time relative to now', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-21T12:00:00Z'));

        expect(relative('2026-10-21T11:57:00Z')).toBe('há 3 minutos');
        expect(relative('2026-10-23T12:00:00Z')).toBe('depois de amanhã');
        expect(relative(null)).toBe('—');
    });
});
