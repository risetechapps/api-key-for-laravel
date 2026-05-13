<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

class MpCustomerService
{
    private string $baseUrl = 'https://api.mercadopago.com';
    private string $accessToken;

    public function __construct()
    {
        $this->accessToken = config('api-key.mercadopago.access_token');
    }

    public function getOrCreateCustomer(Authentication $user): string
    {
        $existing = UserCard::where('authentication_id', $user->getKey())
            ->whereNotNull('mp_customer_id')
            ->value('mp_customer_id');

        if ($existing) {
            return $existing;
        }

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/customers", [
                'email' => strtolower($user->email),
            ]);

        if (! $response->successful()) {
            Log::error('MP create customer failed', ['body' => $response->body()]);
            throw new \RuntimeException('Falha ao registrar cliente no Mercado Pago.');
        }

        return $response->json('id');
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
            Log::error('MP attach card failed', ['body' => $response->body()]);
            throw new \RuntimeException('Falha ao salvar cartão no Mercado Pago.');
        }

        return $response->json('id');
    }

    public function tokenizeCard(string $customerId, string $cardId, string $securityCode): string
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/card_tokens", [
                'customer_id'   => $customerId,
                'card_id'       => $cardId,
                'security_code' => $securityCode,
            ]);

        if (! $response->successful()) {
            Log::error('MP tokenize card failed', ['body' => $response->body()]);
            throw new \RuntimeException('Falha ao validar cartão. Verifique o CVV e tente novamente.');
        }

        return $response->json('id');
    }

    public function tokenizeRecurring(string $customerId, string $cardId): string
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/card_tokens", [
                'customer_id' => $customerId,
                'card_id'     => $cardId,
            ]);

        if (! $response->successful()) {
            Log::error('MP recurring token failed', ['body' => $response->body()]);
            throw new \RuntimeException('Falha ao gerar token recorrente.');
        }

        return $response->json('id');
    }
}
