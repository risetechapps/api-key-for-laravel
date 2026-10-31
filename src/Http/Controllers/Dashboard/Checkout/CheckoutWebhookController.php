<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout;

use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Services\AutomaticPaymentService;
use RiseTechApps\ApiKey\Services\PaymentOutcomeService;
use RiseTechApps\ApiKey\Services\SystemHealthService;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Notificações dos gateways: webhook assinado e IPN do Mercado Pago, e o
 * webhook dos demais gateways.
 *
 * Separado do CheckoutController porque não tem nada do fluxo do comprador:
 * não há usuário autenticado, e a notificação nunca decide nada sozinha. Cada
 * pagamento apontado é consultado na API do gateway e o desfecho é aplicado
 * pelo PaymentOutcomeService, o mesmo da reconciliação.
 */
class CheckoutWebhookController extends Controller
{
    /** Status de pagamento que não mudam mais em segundos (ver ipnLookupRefusal). */
    private const FINAL_STATUSES = ['approved', 'rejected', 'cancelled', 'refunded', 'charged_back'];

    public function __construct(
        protected readonly PaymentOutcomeService $paymentOutcome,
    ) {}

    /**
     * Recebe as notificações do Mercado Pago.
     *
     * O gateway entrega em dois formatos, e o pacote precisa dos dois:
     *
     *   Webhook  — corpo `{type, data:{id}}` com os headers `x-signature` e
     *              `x-request-id`. Assinado, validado por HMAC.
     *   IPN      — query `?topic=payment&id=…` com corpo `{resource, topic}`.
     *              Sem assinatura nenhuma; é assim que o formato foi desenhado.
     *
     * O IPN chega porque o pagamento carrega `notification_url`, que a revisão
     * de qualidade do Mercado Pago exige. Rejeitá-lo não é opção: sem responder
     * 200 o gateway reentrega indefinidamente.
     *
     * A autenticidade do IPN vem de nós irmos buscar o pagamento na API com o
     * nosso access token — só agimos sobre pagamentos da nossa própria conta.
     * É verificação mais fraca que o HMAC, e por isso desligável.
     */
    public function webhook(Request $request): JsonResponse
    {
        $signature = (string) $request->header('x-signature', '');

        if ($signature !== '') {
            if (! $this->signatureIsValid($request, $signature)) {
                return response()->json(['message' => __('api-key::messages.invalid_webhook_signature')], 400);
            }
        } elseif (! $this->acceptsUnsignedNotification($request)) {
            logglyWarning()->withContext([
                'topic' => $request->input('topic'),
                'has_secret' => (bool) config('api-key.mercadopago.webhook_secret'),
            ])->log('MP notification rejected: unsigned and not a recognised IPN');

            return response()->json(['message' => __('api-key::messages.invalid_webhook_signature')], 400);
        }

        SystemHealthService::recordWebhook('mercadopago');

        $type = $request->input('type') ?? $request->input('topic');

        // Pagamentos Automáticos: a cobrança é uma order, e o perfil do cartão
        // muda de status por notificação própria.
        if ($type === 'order') {
            return $this->orderNotification($request);
        }

        if (is_string($type) && str_starts_with($type, 'payment_profile')) {
            $profileId = $request->input('data.id');

            if (is_string($profileId) && $profileId !== '') {
                try {
                    app(AutomaticPaymentService::class)->refreshProfile($profileId);
                } catch (\Exception $e) {
                    logglyWarning()->withContext(['error' => $e->getMessage()])->log('MP payment profile notification failed');
                }
            }

            return response()->json(['message' => 'ok']);
        }

        if ($type !== 'payment') {
            return response()->json(['message' => 'ok']);
        }

        // `resource` e a query `id` são os campos do IPN; `data.id` é o do
        // webhook assinado.
        $paymentId = $request->input('data.id')
            ?? $request->input('id')
            ?? $request->input('resource')
            ?? $request->query('id');

        // Notificação sem id utilizável nunca vai virar pagamento, por mais que
        // seja reentregue. Antes ia para `get(0)`, a exceção do SDK virava 500 e
        // o gateway reentregava para sempre.
        if (! is_scalar($paymentId) || ! ctype_digit((string) $paymentId)) {
            logglyWarning()->withContext([
                'payment_id' => is_scalar($paymentId) ? $paymentId : gettype($paymentId),
            ])->log('MP notification ignored: missing or malformed payment id');

            return response()->json(['message' => 'ok']);
        }

        if ($signature === '' && ($refusal = $this->ipnLookupRefusal($request, (string) $paymentId))) {
            return $refusal;
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $client = new PaymentClient;

        try {
            $payment = $client->get((int) $paymentId);
        } catch (MPApiException $e) {
            $statusCode = $e->getApiResponse()->getStatusCode();

            // 404: o pagamento não existe nesta conta. Reentregar não muda isso.
            // Qualquer outra falha (gateway fora, timeout, token inválido) pode
            // passar, então devolve erro e deixa o Mercado Pago tentar de novo.
            if ($statusCode === 404) {
                logglyWarning()->withContext(['payment_id' => $paymentId])
                    ->log('MP notification ignored: payment not found on this account');

                return response()->json(['message' => 'ok']);
            }

            logglyError()->withContext([
                'payment_id' => $paymentId,
                'status' => $statusCode,
            ])->log('MP notification: payment lookup failed, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        // O que fazer com cada desfecho vive no serviço, e não aqui: o comando
        // de reconciliação precisa decidir exatamente a mesma coisa quando o
        // webhook não chega. A resposta é sempre 'ok' — o Mercado Pago reentrega
        // enquanto não receber 200, e um pagamento que não nos diz respeito não
        // é motivo para pedir reentrega.
        $this->paymentOutcome->apply($payment);

        // Só depois de aplicado: se a consulta ou o serviço falhar, a
        // reentrega do Mercado Pago precisa consultar de novo. E só com status
        // final: de pending para approved em segundos é o caminho normal, e o
        // aviso seguinte precisa ser consultado.
        if ($signature === '' && in_array($payment->status ?? null, self::FINAL_STATUSES, true)) {
            Cache::put('api-key:mp-ipn:'.$paymentId, true, 60);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Webhook dos outros gateways (`/checkout/webhook/{gateway}`).
     *
     * O driver confere a assinatura (ou a credencial da URL) e diz quais
     * pagamentos a notificação aponta. Cada um é consultado na API do gateway
     * e o pacote aplica o que a API diz: o corpo da notificação nunca aprova
     * nada sozinho.
     */
    public function gatewayWebhook(Request $request, string $gateway): JsonResponse
    {
        $driver = GatewayRegistry::get($gateway);

        if (! $driver) {
            return response()->json(['message' => 'not found'], 404);
        }

        // Antes de webhookPaymentIds(): a Efí já consulta a API ali dentro.
        if (! $driver->webhooksAuthenticated() && ($refusal = $this->unsignedLookupRefusal($request, $gateway))) {
            return $refusal;
        }

        try {
            $ids = $driver->webhookPaymentIds($request);
        } catch (AuthenticationException) {
            logglyWarning()->withContext(['gateway' => $gateway])->log('Gateway notification refused: invalid signature or credentials');

            return response()->json(['message' => 'unauthorized'], 401);
        } catch (\RuntimeException $e) {
            // Gateway que precisa consultar a notificação (Efí) e não respondeu.
            logglyError()->withContext(['gateway' => $gateway, 'error' => $e->getMessage()])
                ->log('Gateway notification: lookup failed, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        SystemHealthService::recordWebhook($gateway);

        if (! $driver->configured()) {
            if ($ids !== []) {
                logglyWarning()->withContext(['gateway' => $gateway, 'ids' => $ids])->log('Gateway notification ignored: gateway not configured');
            }

            return response()->json(['message' => 'ok']);
        }

        foreach (array_unique($ids) as $id) {
            if (! $driver->ownsPaymentId($id)) {
                continue;
            }

            try {
                $payment = $driver->findPayment($id);
            } catch (\RuntimeException $e) {
                logglyError()->withContext(['gateway' => $gateway, 'payment_id' => $id, 'error' => $e->getMessage()])
                    ->log('Gateway notification: lookup failed, asking for redelivery');

                return response()->json(['message' => 'retry'], 503);
            }

            if ($payment === null) {
                logglyWarning()->withContext(['gateway' => $gateway, 'payment_id' => $id])->log('Gateway notification ignored: payment not found on this account');

                continue;
            }

            $this->paymentOutcome->apply($payment);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Notificação de order (Pagamentos Automáticos): consulta a order e aplica
     * o desfecho pelo mesmo serviço dos pagamentos comuns.
     */
    private function orderNotification(Request $request): JsonResponse
    {
        $orderId = $request->input('data.id') ?? $request->query('data_id');

        if (! is_string($orderId) || ! preg_match('/^[A-Za-z0-9]+$/', $orderId)) {
            logglyWarning()->log('MP order notification ignored: missing or malformed order id');

            return response()->json(['message' => 'ok']);
        }

        try {
            $order = app(AutomaticPaymentService::class)->findOrder($orderId);
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['order_id' => $orderId, 'error' => $e->getMessage()])
                ->log('MP order notification: lookup failed, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        if ($order === null) {
            logglyWarning()->withContext(['order_id' => $orderId])
                ->log('MP order notification ignored: order not found on this account');

            return response()->json(['message' => 'ok']);
        }

        $this->paymentOutcome->apply($order);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Confere o HMAC do webhook assinado.
     *
     * Continua fechando por falta de segredo: um endpoint que aceita qualquer
     * assinatura é o mesmo que não ter assinatura, e segredo ausente é erro de
     * configuração para gritar, não checagem a pular.
     */
    private function signatureIsValid(Request $request, string $signature): bool
    {
        $secret = config('api-key.mercadopago.webhook_secret');

        if (! $secret) {
            logglyError()->log('MP webhook rejected: no webhook secret configured');

            return false;
        }

        $xRequestId = (string) $request->header('x-request-id', '');
        // O Mercado Pago assina o id em minúsculas quando ele é alfanumérico.
        // Ids de pagamento são numéricos, mas outros tópicos não são.
        $dataId = strtolower((string) $request->query('data_id', $request->input('data.id', '')));

        $ts = $this->extractSignaturePart($signature, 'ts');
        $v1 = $this->extractSignaturePart($signature, 'v1');
        $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$xRequestId};ts:{$ts};", (string) $secret);

        if (! hash_equals($hash, $v1)) {
            return false;
        }

        return $this->timestampIsFresh($ts);
    }

    /**
     * Se o `ts` assinado está dentro da tolerância configurada.
     *
     * O HMAC prova que a notificação veio do Mercado Pago, não que é recente:
     * sem isto, uma notificação capturada poderia ser reenviada a qualquer
     * momento. Aceita `ts` em segundos ou em milissegundos.
     */
    private function timestampIsFresh(string $ts): bool
    {
        $tolerance = (int) config('api-key.mercadopago.webhook_tolerance_seconds', 0);

        if ($tolerance <= 0) {
            return true;
        }

        if (! ctype_digit($ts)) {
            return false;
        }

        $seconds = (int) $ts;

        if ($seconds > 99_999_999_999) {
            $seconds = intdiv($seconds, 1000);
        }

        if (abs(time() - $seconds) > $tolerance) {
            logglyWarning()->withContext(['ts' => $ts, 'tolerance' => $tolerance])
                ->log('MP webhook rejected: signature timestamp outside tolerance');

            return false;
        }

        return true;
    }

    /**
     * Se esta requisição sem assinatura é um IPN legítimo do Mercado Pago.
     *
     * Exige a forma do IPN — `topic` mais `resource` ou o `id` na query — para
     * que uma requisição qualquer sem assinatura não passe. Não é autenticação:
     * quem autentica é a consulta do pagamento na API logo adiante, com o nosso
     * access token. Serve para não abrir o endpoint a qualquer corpo JSON.
     *
     * Desligável por `api-key.mercadopago.accept_ipn` para quem recebe só
     * webhooks assinados e prefere recusar o resto.
     */
    private function acceptsUnsignedNotification(Request $request): bool
    {
        if (! config('api-key.mercadopago.accept_ipn', true)) {
            return false;
        }

        $hasTopic = $request->input('topic') !== null || $request->query('topic') !== null;
        $hasResource = $request->input('resource') !== null || $request->query('id') !== null;

        return $hasTopic && $hasResource;
    }

    /**
     * Freio das consultas que o IPN sem assinatura dispara. Ver
     * `api-key.mercadopago.ipn_lookups_per_minute`.
     *
     * O mesmo pagamento já aplicado, com status final, há menos de um minuto
     * responde 'ok' sem consultar: o Mercado Pago repete o aviso e manda o
     * webhook assinado junto. A marca só é gravada depois do apply(), então
     * uma consulta que falhou não bloqueia a reentrega, e pagamento ainda
     * pendente não é marcado, para a aprovação logo depois não se perder.
     */
    private function ipnLookupRefusal(Request $request, string $paymentId): ?JsonResponse
    {
        if (Cache::has('api-key:mp-ipn:'.$paymentId)) {
            return response()->json(['message' => 'ok']);
        }

        return $this->lookupBudgetRefusal(
            $request,
            'api-key:mp-ipn-lookups',
            (int) config('api-key.mercadopago.ipn_lookups_per_minute', 60),
            ['gateway' => 'mercadopago', 'payment_id' => $paymentId],
        );
    }

    /**
     * Teto das consultas que uma notificação sem prova de origem dispara nos
     * outros gateways (Efí, Pagar.me sem usuário e senha). Mesmo motivo do
     * ipnLookupRefusal(): sem ele, avisos falsos gastariam o limite da conta
     * no gateway.
     */
    private function unsignedLookupRefusal(Request $request, string $gateway): ?JsonResponse
    {
        return $this->lookupBudgetRefusal(
            $request,
            'api-key:gateway-unsigned-lookups:'.$gateway,
            (int) config('api-key.payment.unsigned_notification_lookups_per_minute', 60),
            ['gateway' => $gateway],
        );
    }

    /**
     * Gasta uma consulta do teto `$perMinute`, ou devolve 503 para o gateway
     * reentregar depois, em vez de perder o aviso.
     *
     * Um teto só, para todo mundo, deixaria um flood de avisos falsos barrar
     * os verdadeiros enquanto durasse. Por isso:
     *   - IP em `payment.notification_trusted_ips` (os IPs do gateway) não
     *     entra no teto: o aviso verdadeiro passa durante o flood;
     *   - cada IP tem um teto próprio (`unsigned_notification_lookups_per_ip_per_minute`),
     *     e só o que passa dele gasta o teto geral: um IP sozinho não esgota
     *     o geral.
     * A consulta à API continua valendo para todos: o IP confiável só pula o
     * teto, não aprova nada.
     *
     * @param  array<string, mixed>  $context
     */
    private function lookupBudgetRefusal(Request $request, string $key, int $perMinute, array $context): ?JsonResponse
    {
        if ($perMinute <= 0) {
            return null;
        }

        $ip = (string) $request->ip();
        $trusted = array_values(array_filter(array_map('trim', (array) config('api-key.payment.notification_trusted_ips', []))));

        if ($trusted !== [] && $ip !== '' && IpUtils::checkIp($ip, $trusted)) {
            return null;
        }

        $perIp = max(0, (int) config('api-key.payment.unsigned_notification_lookups_per_ip_per_minute', 20));
        $ipKey = $key.':ip:'.$ip;

        if (($perIp > 0 && RateLimiter::tooManyAttempts($ipKey, $perIp)) || RateLimiter::tooManyAttempts($key, $perMinute)) {
            logglyWarning()->withContext($context + ['ip' => $ip])
                ->log('Unsigned notification lookup budget exhausted, asking for redelivery');

            return response()->json(['message' => 'retry'], 503);
        }

        if ($perIp > 0) {
            RateLimiter::hit($ipKey, 60);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function extractSignaturePart(string $signature, string $key): string
    {
        if (preg_match("/{$key}=([^,]+)/", $signature, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
