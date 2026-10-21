import appConfig from '@/api-key.config';

/**
 * Identidade visual em uso: o que o Laravel entregou no shell Blade
 * (`api-key.spa.brand`, editável em Admin → Aparência) e, na falta, o que
 * está em `api-key.config.ts`.
 */
export interface Brand {
    name: string;
    color: string;
    logo: string | null;
}

const injected = (typeof window !== 'undefined' ? (window as any).__API_KEY_BRAND__ : null) ?? {};

export const brand: Brand = {
    name: injected.name || appConfig.app.logoText || 'Dashboard',
    color: injected.color || '#6366f1',
    logo: injected.logo || appConfig.app.logoImage || null,
};

/** Troca a cor da marca na hora, sem recarregar (pré-visualização em Aparência). */
export function applyBrandColor(color: string): void {
    if (/^#[0-9a-fA-F]{6}$/.test(color)) {
        document.documentElement.style.setProperty('--brand', color);
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color);
    }
}
