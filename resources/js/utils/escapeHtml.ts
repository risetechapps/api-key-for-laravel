/**
 * Escapa texto para interpolar no `html` do SweetAlert.
 *
 * Nome de usuário é digitado pelo próprio usuário no cadastro; entrar cru no
 * HTML de um diálogo do painel admin é XSS com a sessão do administrador, cujo
 * token fica no localStorage.
 */
export function escapeHtml(value: unknown): string {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
