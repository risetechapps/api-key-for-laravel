import { isDark } from '@/theme';

/**
 * Base para gráficos ApexCharts no visual do painel: cor da marca, fonte,
 * grade e tooltip que acompanham o tema. Junte com as opções do seu gráfico:
 *
 *   const options = computed(() => chartOptions({ xaxis: { categories } }));
 *
 * Leia `isDark` dentro do computed para o gráfico trocar de tema junto.
 */
export function brandColor(): string {
    if (typeof document === 'undefined') return '#6366f1';
    return getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#6366f1';
}

function merge<T extends Record<string, any>>(base: T, extra: Record<string, any>): T {
    const out: Record<string, any> = { ...base };
    for (const [key, value] of Object.entries(extra)) {
        out[key] = value && typeof value === 'object' && !Array.isArray(value) && base[key] && typeof base[key] === 'object'
            ? merge(base[key], value)
            : value;
    }
    return out as T;
}

export function chartOptions(extra: Record<string, any> = {}): Record<string, any> {
    const dark = isDark.value;
    const muted = dark ? '#64748b' : '#94a3b8';

    return merge({
        chart: { toolbar: { show: false }, zoom: { enabled: false }, background: 'transparent', fontFamily: 'Inter, sans-serif', animations: { enabled: true, speed: 500 } },
        colors: [brandColor()],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2.5 },
        grid: { borderColor: dark ? '#1e293b' : '#eef2f7', strokeDashArray: 4, padding: { left: 4, right: 4 } },
        xaxis: { labels: { style: { colors: muted } }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { labels: { style: { colors: muted } } },
        legend: { labels: { colors: muted } },
        tooltip: { theme: dark ? 'dark' : 'light' },
        theme: { mode: dark ? 'dark' : 'light' },
    }, extra);
}
