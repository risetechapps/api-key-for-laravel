import axios from 'axios';
import { prompt } from '@/ui/dialog';
import { toast } from '@/ui/toast';

export interface AutomaticBillingCard {
    id: number;
    mp_card_id: string;
    last_four: string;
}

const SDK_URL = 'https://sdk.mercadopago.com/js/v2';

function loadSdk(): Promise<void> {
    if ((window as any).MercadoPago) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SDK_URL;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Não foi possível carregar o Mercado Pago.'));
        document.head.appendChild(script);
    });
}

/**
 * Token de uso único de um cartão salvo no Mercado Pago, gerado no navegador
 * com o CVV. O código nunca chega ao servidor.
 */
export async function createMpCvvToken(publicKey: string, mpCardId: string, cvv: string): Promise<string> {
    await loadSdk();
    const mp = new (window as any).MercadoPago(publicKey, { locale: 'pt-BR' });
    const token = await mp.createCardToken({ cardId: mpCardId, securityCode: cvv });
    return token.id as string;
}

/**
 * Ativa a renovação automática (Pagamentos Automáticos do Mercado Pago) num
 * cartão já salvo, pedindo só o CVV.
 *
 * O CVV vira um token no navegador, pelo SDK do gateway, como no pagamento com
 * cartão salvo: o código nunca chega ao servidor. O token cria o perfil de
 * pagamento do cartão, e a partir daí a renovação e o excedente são cobrados
 * sem pedir o CVV de novo.
 *
 * @returns Se o cartão terminou com a renovação automática ativa.
 */
export async function enableAutomaticBilling(card: AutomaticBillingCard, publicKey: string): Promise<boolean> {
    let message = '';

    const result = await prompt({
        title: 'Ativar renovação automática',
        description: `Digite o CVV do cartão final ${card.last_four}. Depois disso, a renovação é cobrada neste cartão sem pedir o código de novo.`,
        confirmText: 'Ativar',
        cancelText: 'Agora não',
        input: { type: 'password', label: 'CVV', placeholder: '•••' },
        onConfirm: async (value) => {
            const code = String(value ?? '').replace(/\D/g, '');
            if (code.length < 3) throw new Error('CVV inválido.');

            try {
                const token = await createMpCvvToken(publicKey, card.mp_card_id, code);
                const response = await axios.post(`dashboard/cards/${card.id}/automatic-billing`, { profile_token: token });
                message = response.data?.message ?? 'Renovação automática ativada.';
            } catch (err: any) {
                throw new Error(err?.response?.data?.message || err?.message || 'Não foi possível ativar a renovação automática.');
            }
        },
    });

    if (result === null) return false;

    toast.success('Renovação automática ativa', { description: message });
    return true;
}
