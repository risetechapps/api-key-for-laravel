<?php

namespace RiseTechApps\ApiKey\Tests\Support;

use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

/**
 * Gateway de pagamento falso, instalado via MercadoPagoConfig::setHttpClient().
 *
 * O CheckoutController instancia `new PaymentClient` direto, sem injeção, então
 * não há como trocar o client por um mock pelo container. O SDK, porém, resolve
 * o transporte HTTP a partir de um singleton global, e é esse ponto que este
 * duplo ocupa: o PaymentClient real continua montando o payload, os headers e a
 * chave de idempotência: só a chamada de rede é interceptada.
 *
 * Trocar o transporte (e não o client) também é o que permite asserção sobre o
 * que *saiu* — `X-Idempotency-Key`, `transaction_amount`, `external_reference` —,
 * que é justamente onde estavam os defeitos de cobrança corrigidos no pacote.
 */
class FakeMercadoPagoHttpClient implements MPHttpClient
{
    /** @var list<MPRequest> Toda requisição que o SDK tentou enviar, em ordem. */
    public array $requests = [];

    /** @var list<array{status: int, body: array<string, mixed>}> Fila de respostas. */
    private array $responses = [];

    /** @var array<string, mixed> Resposta usada quando a fila esvazia. */
    private array $fallback = ['status' => 200, 'body' => []];

    /**
     * Enfileira uma resposta de sucesso. Chamadas em sequência são consumidas na
     * ordem em que foram registradas.
     *
     * @param  array<string, mixed>  $body
     */
    public function pushResponse(array $body, int $status = 200): static
    {
        $this->responses[] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /**
     * Enfileira uma recusa do gateway — o SDK converte um não-2xx em
     * MPApiException, e é assim que o `catch (MPApiException)` do controller é
     * exercitado.
     *
     * @param  array<string, mixed>  $body
     */
    public function pushFailure(array $body, int $status = 400): static
    {
        $this->responses[] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /**
     * Resposta padrão para qualquer chamada além das enfileiradas. Útil para as
     * chamadas acessórias (tokenização de cartão, por exemplo) que o teste não
     * está examinando mas que não podem sair para a rede.
     *
     * @param  array<string, mixed>  $body
     */
    public function fallbackResponse(array $body, int $status = 200): static
    {
        $this->fallback = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->responses) ?? $this->fallback;
        $response = new MPResponse($next['status'], $next['body']);

        // Espelha o MPDefaultHttpClient: quem transforma um não-2xx em exceção é
        // o transporte, não o PaymentClient. Sem isto o controller receberia um
        // Payment desserializado a partir de um corpo de erro.
        if ($next['status'] < 200 || $next['status'] >= 300) {
            throw new MPApiException('api error', $response);
        }

        return $response;
    }

    /** O payload JSON da requisição de índice `$index`, já decodificado. */
    public function payload(int $index = 0): array
    {
        return json_decode($this->requests[$index]->getPayload() ?? '[]', true) ?: [];
    }

    /** O valor de um header da requisição de índice `$index`, ou null. */
    public function header(string $name, int $index = 0): ?string
    {
        foreach ($this->requests[$index]->getHeaders() ?? [] as $header) {
            [$key, $value] = array_pad(explode(':', $header, 2), 2, '');

            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }

        return null;
    }
}
