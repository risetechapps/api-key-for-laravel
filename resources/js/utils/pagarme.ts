/**
 * Tokeniza o cartão direto na API do Pagar.me, no navegador, com a chave
 * pública. Número e CVV nunca passam pelo servidor do pacote: ele recebe só o
 * token, de uso único.
 *
 * `fetch` e não axios: a instância do axios do painel leva o token de sessão
 * no header Authorization, e a rota de tokens do Pagar.me recusa qualquer
 * Authorization (e o token de sessão não tem por que sair para terceiros).
 */
export interface PagarmeCardInput {
    number: string;
    holder_name: string;
    exp_month: number;
    exp_year: number;
    cvv: string;
}

export async function tokenizePagarmeCard(publicKey: string, card: PagarmeCardInput): Promise<string> {
    if (!publicKey) {
        throw new Error('Pagamento com cartão indisponível no momento.');
    }

    const response = await fetch(`https://api.pagar.me/core/v5/tokens?appId=${encodeURIComponent(publicKey)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'card', card }),
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok || !body?.id) {
        throw new Error('Confira os dados do cartão.');
    }

    return body.id as string;
}
