import { ref } from 'vue';

/**
 * Tema claro/escuro, compartilhado entre cabeçalho, busca rápida e páginas
 * públicas. A escolha fica no localStorage; sem escolha, vale o sistema.
 */
export const isDark = ref(typeof document !== 'undefined' && document.documentElement.classList.contains('dark'));

export function setDark(value: boolean): void {
    isDark.value = value;
    document.documentElement.classList.toggle('dark', value);
    try {
        localStorage.setItem('darkMode', String(value));
    } catch {
        // Navegação privada sem storage: vale só nesta aba.
    }
}

export function toggleTheme(): void {
    setDark(!isDark.value);
}
