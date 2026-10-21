import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GATEWAY_SCRIPTS, gatewayLabel, isDriverGateway, tokenizeCard, type CardInput } from '@/utils/gateways';

const card: CardInput = { number: '4111111111111111', holder: 'ANA', cpf: '52998224725', month: 3, year: 2031, cvv: '123' };

// loadScript() resolve na hora quando a tag do script já está na página: é
// assim que os testes "carregam" o SDK sem rede.
function scriptAlreadyLoaded(src: string) {
    const script = document.createElement('script');
    script.src = src;
    document.head.appendChild(script);
}

describe('gateway names', () => {
    it('labels each gateway and falls back to Mercado Pago', () => {
        expect(gatewayLabel('efi')).toBe('Efí');
        expect(gatewayLabel(null)).toBe('Mercado Pago');
        expect(gatewayLabel('desconhecido')).toBe('desconhecido');
    });

    it('treats every gateway but Mercado Pago as a package driver', () => {
        expect(isDriverGateway('stripe')).toBe(true);
        expect(isDriverGateway('mercadopago')).toBe(false);
        expect(isDriverGateway(undefined)).toBe(false);
    });
});

describe('PagBank', () => {
    beforeEach(() => scriptAlreadyLoaded(GATEWAY_SCRIPTS.pagbank));
    afterEach(() => {
        delete window.PagSeguro;
        document.head.innerHTML = '';
    });

    it('sends the CVV along only when the token pays now', async () => {
        window.PagSeguro = { encryptCard: vi.fn(() => ({ hasErrors: false, encryptedCard: 'enc-1' })) };

        const saving = await tokenizeCard('pagbank', { public_key: 'pk' }, '', card);
        const paying = await tokenizeCard('pagbank', { public_key: 'pk' }, '', card, true);

        expect(saving).toEqual({ token: 'enc-1', extra: {} });
        expect(paying.extra).toEqual({ security_code: '123' });
        expect(window.PagSeguro.encryptCard).toHaveBeenCalledWith(expect.objectContaining({ expMonth: '03', expYear: '2031' }));
    });

    it('asks to check the card when encryption fails', async () => {
        window.PagSeguro = { encryptCard: () => ({ hasErrors: true }) };

        await expect(tokenizeCard('pagbank', { public_key: 'pk' }, '', card)).rejects.toThrow('Confira os dados do cartão.');
    });

    it('is unavailable without a public key', async () => {
        await expect(tokenizeCard('pagbank', {}, '', card)).rejects.toThrow('Pagamento com cartão indisponível no momento.');
    });
});

describe('Efí', () => {
    beforeEach(() => {
        scriptAlreadyLoaded(GATEWAY_SCRIPTS.efi);
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });
    afterEach(() => {
        delete window.EfiPay;
        document.head.innerHTML = '';
    });

    function efi(getPaymentToken: () => Promise<unknown>) {
        const chain: any = {
            setCardNumber: () => chain,
            verifyCardBrand: async () => 'mastercard',
            setAccount: () => chain,
            setEnvironment: () => chain,
            setCreditCardData: vi.fn(() => chain),
            getPaymentToken,
        };
        window.EfiPay = { CreditCard: chain };
        return chain;
    }

    it('returns the reusable token with the card mask and detected brand', async () => {
        const chain = efi(async () => ({ payment_token: 'tok-1', card_mask: 'XXXX1111' }));

        const result = await tokenizeCard('efi', { account_id: 'acc' }, '', card);

        expect(result).toEqual({ token: 'tok-1', extra: { card_mask: 'XXXX1111', brand: 'mastercard', expiry_month: 3, expiry_year: 2031 } });
        expect(chain.setCreditCardData).toHaveBeenCalledWith(expect.objectContaining({ reuse: true, expirationMonth: '03' }));
    });

    it('never shows the technical Efí error to the buyer', async () => {
        efi(async () => { throw { error: 'identificador_invalido', error_description: 'Identificador de conta inválido' }; });

        await expect(tokenizeCard('efi', { account_id: 'acc' }, '', card)).rejects.toThrow('Pagamento com cartão indisponível no momento.');
    });
});

describe('unknown gateway', () => {
    it('refuses to tokenize', async () => {
        await expect(tokenizeCard('mercadopago', {}, '', card)).rejects.toThrow('Pagamento com cartão indisponível no momento.');
    });
});
