<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;

/**
 * Gateways de pagamento pelo painel admin: qual está ativo, credenciais e o
 * teste de conexão. O que for salvo aqui vale por cima do .env.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(private readonly PaymentSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->jsonSuccess($this->settings->forPanel());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'values' => ['required', 'array'],
        ]);

        $actor = $request->user();

        $changed = $this->settings->update(
            (array) $data['values'],
            $actor instanceof Authentication ? $actor : null,
        );

        return response()->jsonSuccess([...$this->settings->forPanel(), 'changed' => $changed]);
    }

    /**
     * Confere as credenciais salvas de um gateway, sem cobrar nada.
     */
    public function test(string $gateway): JsonResponse
    {
        $result = $gateway === 'mercadopago'
            ? $this->testMercadoPago()
            : (GatewayRegistry::get($gateway) ?? abort(404))->testConnection();

        return response()->jsonSuccess($result);
    }

    /**
     * @return array{ok: bool, message: string, automatic_payments?: bool|null}
     */
    private function testMercadoPago(): array
    {
        $token = (string) config('api-key.mercadopago.access_token');

        if ($token === '') {
            return ['ok' => false, 'message' => 'Access token não configurado.'];
        }

        try {
            $me = Http::withToken($token)->timeout(15)->get('https://api.mercadopago.com/users/me');
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => 'Sem resposta do Mercado Pago: '.$e->getMessage()];
        }

        if (! $me->successful()) {
            return ['ok' => false, 'message' => $me->status() === 401
                ? 'Access token recusado pelo Mercado Pago.'
                : "Mercado Pago respondeu HTTP {$me->status()}."];
        }

        // Pagamentos Automáticos: um customer inexistente dá 400 quando a
        // conta tem acesso e 403 quando não tem. Nada é criado.
        $automatic = null;

        try {
            $probe = Http::withToken($token)->timeout(15)
                ->get('https://api.mercadopago.com/v1/customers/000000000-XXXXXXXXXXXXXX/payment-profiles');
            $automatic = $probe->status() !== 403;
        } catch (\Exception) {
            $automatic = null;
        }

        return [
            'ok' => true,
            'message' => 'Conexão com o Mercado Pago funcionando (conta '.$me->json('id').').',
            'automatic_payments' => $automatic,
        ];
    }
}
