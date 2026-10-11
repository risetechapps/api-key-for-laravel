/**
 * Gateways além do Mercado Pago: nomes, e como cada um transforma o cartão
 * digitado em algo que o servidor pode guardar. Número e CVV vão direto do
 * navegador para o gateway (ou são criptografados aqui, no PagBank); o
 * servidor do pacote recebe só o token.
 *
 * O Stripe não passa por aqui: o cartão fica num iframe do Stripe Elements
 * (ver GatewayCardFields.vue).
 */
import { tokenizePagarmeCard } from '@/utils/pagarme';

export const GATEWAY_LABELS: Record<string, string> = {
    mercadopago: 'Mercado Pago',
    stripe: 'Stripe',
    efi: 'Efí',
    pagbank: 'PagBank',
    pagarme: 'Pagar.me',
};

export function gatewayLabel(gateway?: string | null): string {
    return GATEWAY_LABELS[gateway ?? 'mercadopago'] ?? gateway ?? 'Mercado Pago';
}

/** Gateway com driver do pacote (tudo que não é o Mercado Pago). */
export function isDriverGateway(gateway?: string | null): boolean {
    return !!gateway && gateway !== 'mercadopago';
}

/** URLs dos scripts oficiais. Mude aqui para fixar uma versão. */
export const GATEWAY_SCRIPTS = {
    stripe: 'https://js.stripe.com/v3/',
    efi: 'https://cdn.jsdelivr.net/npm/payment-token-efi/dist/payment-token-efi-umd.min.js',
    pagbank: 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js',
};

const loading: Record<string, Promise<void>> = {};

export function loadScript(src: string): Promise<void> {
    if (!loading[src]) {
        loading[src] = new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) return resolve();
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => {
                delete loading[src];
                reject(new Error('Não foi possível carregar o pagamento com cartão. Confira sua conexão.'));
            };
            document.head.appendChild(script);
        });
    }
    return loading[src];
}

export interface CardInput {
    number: string;
    holder: string;
    cpf: string;
    month: number;
    year: number;
    cvv: string;
}

export interface CardTokenResult {
    token: string;
    /** O que o gateway pede além do token (ver `extra` no backend). */
    extra: Record<string, unknown>;
}

declare global {
    interface Window {
        EfiPay?: any;
        PagSeguro?: any;
        Stripe?: any;
    }
}

const pad2 = (n: number) => String(n).padStart(2, '0');

async function tokenizeEfi(config: Record<string, any>, card: CardInput): Promise<CardTokenResult> {
    if (!config?.account_id) throw new Error('Pagamento com cartão indisponível no momento.');
    await loadScript(GATEWAY_SCRIPTS.efi);
    const efi = window.EfiPay;
    if (!efi?.CreditCard) throw new Error('Não foi possível carregar o pagamento com cartão.');

    let brand = 'visa';
    try {
        const detected = await efi.CreditCard.setCardNumber(card.number).verifyCardBrand();
        if (detected && detected !== 'undefined' && detected !== 'unsupported') brand = String(detected);
    } catch {
        throw new Error('Bandeira do cartão não aceita.');
    }

    try {
        const result = await efi.CreditCard
            .setAccount(config.account_id)
            .setEnvironment(config.sandbox ? 'sandbox' : 'production')
            .setCreditCardData({
                brand,
                number: card.number,
                cvv: card.cvv,
                expirationMonth: pad2(card.month),
                expirationYear: String(card.year),
                holderName: card.holder,
                holderDocument: card.cpf,
                reuse: true,
            })
            .getPaymentToken();

        if (!result?.payment_token) throw new Error('Confira os dados do cartão.');

        return {
            token: result.payment_token,
            extra: { card_mask: result.card_mask, brand, expiry_month: card.month, expiry_year: card.year },
        };
    } catch (err: any) {
        // A Efí devolve { code, error, error_description }. Erro de
        // configuração da conta não é culpa de quem digitou o cartão.
        console.error('Efí payment token', err?.error ?? err, err?.error_description ?? '');
        // O texto da Efí pode ser técnico; a tela recebe só mensagens nossas.
        if (err?.error === 'identificador_invalido') throw new Error('Pagamento com cartão indisponível no momento.');
        throw new Error('Confira os dados do cartão.');
    }
}

async function tokenizePagbank(config: Record<string, any>, card: CardInput, forPayment: boolean): Promise<CardTokenResult> {
    if (!config?.public_key) throw new Error('Pagamento com cartão indisponível no momento.');
    await loadScript(GATEWAY_SCRIPTS.pagbank);
    if (!window.PagSeguro?.encryptCard) throw new Error('Não foi possível carregar o pagamento com cartão.');

    const result = window.PagSeguro.encryptCard({
        publicKey: config.public_key,
        holder: card.holder,
        number: card.number,
        expMonth: pad2(card.month),
        expYear: String(card.year),
        securityCode: card.cvv,
    });

    if (result?.hasErrors || !result?.encryptedCard) {
        throw new Error('Confira os dados do cartão.');
    }

    // O CVV só segue quando há cobrança agora: o PagBank exige na primeira
    // cobrança da recorrência. O servidor repassa e não guarda.
    return { token: result.encryptedCard, extra: forPayment ? { security_code: card.cvv } : {} };
}

/**
 * Tokeniza o cartão no gateway ativo. `forPayment`: o token vai pagar agora
 * (checkout), não só ser salvo.
 */
export async function tokenizeCard(gateway: string, config: Record<string, any> | null | undefined, legacyPagarmeKey: string, card: CardInput, forPayment = false): Promise<CardTokenResult> {
    switch (gateway) {
        case 'efi':
            return tokenizeEfi(config ?? {}, card);
        case 'pagbank':
            return tokenizePagbank(config ?? {}, card, forPayment);
        case 'pagarme':
            return {
                token: await tokenizePagarmeCard(config?.public_key || legacyPagarmeKey, {
                    number: card.number,
                    holder_name: card.holder,
                    exp_month: card.month,
                    exp_year: card.year,
                    cvv: card.cvv,
                }),
                extra: {},
            };
        default:
            throw new Error('Pagamento com cartão indisponível no momento.');
    }
}

/** Instância do Stripe.js para a chave publicável, carregada uma vez. */
let stripeInstance: { key: string; stripe: any } | null = null;

export async function getStripe(publishableKey: string): Promise<any> {
    if (!publishableKey) throw new Error('Pagamento com cartão indisponível no momento.');
    await loadScript(GATEWAY_SCRIPTS.stripe);
    if (!window.Stripe) throw new Error('Não foi possível carregar o pagamento com cartão.');
    if (stripeInstance?.key !== publishableKey) {
        stripeInstance = { key: publishableKey, stripe: window.Stripe(publishableKey, { locale: 'pt-BR' }) };
    }
    return stripeInstance.stripe;
}
