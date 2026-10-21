import { describe, expect, it, vi } from 'vitest';
import { tokenizePagarmeCard } from '@/utils/pagarme';

const card = { number: '4111111111111111', holder_name: 'ANA', exp_month: 3, exp_year: 2031, cvv: '123' };

describe('Pagar.me tokenization', () => {
    it('posts the card straight to Pagar.me, without the panel session header', async () => {
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ id: 'token_1' }), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(tokenizePagarmeCard('pk_1', card)).resolves.toBe('token_1');

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('https://api.pagar.me/core/v5/tokens?appId=pk_1');
        expect(init.headers).toEqual({ 'Content-Type': 'application/json' });
        expect(JSON.parse(init.body as string)).toEqual({ type: 'card', card });

        vi.unstubAllGlobals();
    });

    it('asks to check the card when Pagar.me refuses it', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 422 })));

        await expect(tokenizePagarmeCard('pk_1', card)).rejects.toThrow('Confira os dados do cartão.');

        vi.unstubAllGlobals();
    });

    it('is unavailable without a public key', async () => {
        await expect(tokenizePagarmeCard('', card)).rejects.toThrow('Pagamento com cartão indisponível no momento.');
    });
});
