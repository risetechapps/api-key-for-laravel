/**
 * Impressão do dispositivo exigida pela análise de risco do Mercado Pago.
 *
 * O `security.js` do Mercado Pago coleta características do navegador e publica
 * o resultado em `window.MP_DEVICE_SESSION_ID`. Esse valor viaja com o pagamento
 * e é sinal de antifraude: sem ele a análise decide com menos informação, e
 * recusas `cc_rejected_high_risk` em cartão legítimo ficam mais frequentes.
 *
 * É melhor esforço de propósito. Bloqueador de script, rede instável ou o script
 * simplesmente demorar não podem impedir alguém de pagar — nesses casos o
 * pagamento segue sem o identificador, exatamente como era antes.
 */

const SCRIPT_ID = 'mp-device-session';

/** Tempo máximo de espera pelo identificador antes de seguir sem ele. */
const TIMEOUT_MS = 5000;

declare global {
    interface Window {
        MP_DEVICE_SESSION_ID?: string;
    }
}

function injectScript(): void {
    if (document.getElementById(SCRIPT_ID)) return;

    const script = document.createElement('script');
    script.id = SCRIPT_ID;
    script.src = 'https://www.mercadopago.com/v2/security.js';
    // O script lê este atributo para saber onde publicar o resultado.
    script.setAttribute('view', 'checkout');
    script.setAttribute('output', 'MP_DEVICE_SESSION_ID');

    document.head.appendChild(script);
}

/**
 * Dispara a coleta assim que a tela de pagamento abre.
 *
 * Chamar cedo importa: o script precisa de um instante para rodar, e pedir o
 * valor no clique do botão costuma chegar antes de ele existir.
 */
export function primeDeviceSession(): void {
    injectScript();
}

/**
 * Identificador coletado, ou null se ele não ficou pronto a tempo.
 *
 * Nunca lança e nunca espera para sempre: o pagamento é mais importante que o
 * sinal de risco.
 */
export async function getDeviceSessionId(): Promise<string | null> {
    injectScript();

    const deadline = Date.now() + TIMEOUT_MS;

    while (Date.now() < deadline) {
        const id = window.MP_DEVICE_SESSION_ID;

        if (typeof id === 'string' && id !== '') {
            return id;
        }

        await new Promise((resolve) => setTimeout(resolve, 100));
    }

    return null;
}
