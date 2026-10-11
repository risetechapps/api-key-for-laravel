<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\Http;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

class MpCustomerService
{
    private string $baseUrl = 'https://api.mercadopago.com';

    private readonly string $accessToken;

    public function __construct()
    {
        $this->accessToken = (string) config('api-key.mercadopago.access_token');
    }

    public function getOrCreateCustomer(Authentication $user): string
    {
        $existing = UserCard::where('authentication_id', $user->getKey())
            ->whereNotNull('mp_customer_id')
            ->value('mp_customer_id');

        if ($existing) {
            return $existing;
        }

        $email = strtolower($user->email);
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/customers", ['email' => $email]);

        if ($response->successful()) {
            return $response->json('id');
        }

        $causeCode = $response->json('cause.0.code') ?? $response->json('cause.code') ?? '';

        // Cliente já existe no MP — busca pelo e-mail
        if ((string) $causeCode === '101') {
            return $this->findCustomerByEmail($email);
        }

        logglyError()->withContext(['body' => $response->body()])->log('MP create customer failed');
        throw new \RuntimeException('Falha ao registrar cliente no Mercado Pago.');
    }

    private function findCustomerByEmail(string $email): string
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/v1/customers/search", ['email' => $email]);

        $id = $response->json('results.0.id');

        if (! $id) {
            logglyError()->withContext(['body' => $response->body()])->log('MP find customer by email failed');
            throw new \RuntimeException('Falha ao localizar cliente no Mercado Pago.');
        }

        return $id;
    }

    /**
     * Attach a payment token to an MP customer, returning the saved mp_card_id.
     * Returns null if the card already exists (MP returns 400 with cause 106).
     */
    public function attachCard(string $customerId, string $token): ?string
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/customers/{$customerId}/cards", [
                'token' => $token,
            ]);

        if ($response->status() === 400) {
            $cause = $response->json('cause.0.code') ?? $response->json('cause.code');
            if ($cause == 106) {
                return null;
            }
        }

        if (! $response->successful()) {
            logglyError()->withContext(['body' => $response->body()])->log('MP attach card failed');
            throw new \RuntimeException('Falha ao salvar cartão no Mercado Pago.');
        }

        return $response->json('id');
    }

    /**
     * Tira o cartão do cofre do Mercado Pago, junto do perfil de Pagamentos
     * Automáticos. Melhor esforço: a remoção local já vale para o pacote, que
     * não cobra cartão que não está no banco. Sem isto, porém, o cartão
     * continuaria guardado no gateway depois de o cliente pedir para tirar.
     *
     * @return bool Se o gateway confirmou (ou o cartão já não estava lá).
     */
    public function removeCard(UserCard $card): bool
    {
        if ($driver = GatewayRegistry::get((string) $card->gateway)) {
            return $driver->deleteCard($card);
        }

        if (! $card->mp_customer_id) {
            return true;
        }

        $removed = true;

        if ($card->mp_payment_profile_id) {
            $removed = app(AutomaticPaymentService::class)->deleteProfile($card);
        }

        if ($card->mp_card_id) {
            try {
                $response = Http::withToken($this->accessToken)
                    ->delete("{$this->baseUrl}/v1/customers/{$card->mp_customer_id}/cards/{$card->mp_card_id}");

                if (! $response->successful() && $response->status() !== 404) {
                    logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status(), 'body' => $response->json()])
                        ->log('MP card removal failed');

                    $removed = false;
                }
            } catch (\Exception $e) {
                logglyWarning()->withContext(['card_id' => $card->getKey(), 'error' => $e->getMessage()])->log('MP card removal failed');

                $removed = false;
            }
        }

        return $removed;
    }

    public function tokenizeCard(string $customerId, string $cardId, string $securityCode): string
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/card_tokens", [
                'customer_id' => $customerId,
                'card_id' => $cardId,
                'security_code' => $securityCode,
            ]);

        if (! $response->successful()) {
            logglyError()->withContext(['body' => $response->body()])->log('MP tokenize card failed');
            throw new \RuntimeException('Falha ao validar cartão. Verifique o CVV e tente novamente.');
        }

        return $response->json('id');
    }

    public function tokenizeRecurring(string $customerId, string $cardId): string
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/card_tokens", [
                'customer_id' => $customerId,
                'card_id' => $cardId,
            ]);

        if (! $response->successful()) {
            logglyError()->withContext(['body' => $response->body()])->log('MP recurring token failed');
            throw new \RuntimeException('Falha ao gerar token recorrente.');
        }

        return $response->json('id');
    }
}
