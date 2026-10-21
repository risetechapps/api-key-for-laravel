<?php

namespace RiseTechApps\ApiKey\Traits;

use RiseTechApps\Address\Models\Address;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Monta o bloco `payer` enviado ao Mercado Pago.
 *
 * A analise de risco do gateway pontua o quanto ele sabe sobre quem esta
 * pagando: nome, sobrenome e endereco do comprador estao na lista de fatores de
 * aprovacao do proprio Mercado Pago. O pacote enviava apenas e-mail e CPF, e
 * repetia a montagem em tres lugares — checkout, validacao de cartao e
 * renovacao —, entao qualquer melhoria precisava ser feita tres vezes.
 *
 * Todo campo e opcional: perfil incompleto nao pode impedir alguem de pagar,
 * apenas envia menos informacao ao antifraude.
 */
trait BuildsPaymentPayer
{
    /**
     * O assinante autenticado, com o tipo do pacote.
     *
     * `auth()->user()` devolve o contrato generico do framework, porque o model
     * de usuario e configurado pela aplicacao hospedeira em tempo de execucao e
     * a analise estatica nao enxerga isso. A checagem tambem tem valor real: as
     * rotas de pagamento existem sob o guard do pacote, e um usuario de outro
     * model chegando aqui e defeito de configuracao que precisa aparecer, nao
     * seguir adiante montando um payload incompleto.
     */
    protected function payerUser(): Authentication
    {
        $user = auth()->user();

        if (! $user instanceof Authentication) {
            throw new \RuntimeException('Payment routes require the package Authentication model.');
        }

        return $user;
    }

    /**
     * Nome e sobrenome separados a partir do nome completo.
     *
     * @return array{first_name: string, last_name: string}
     */
    protected function payerNames(?string $fullName): array
    {
        $parts = preg_split('/\s+/', trim((string) $fullName), 2) ?: [];

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Endereco do comprador no formato do Mercado Pago, ou null quando o perfil
     * nao tem endereco cadastrado.
     *
     * Lido pela relacao e nao pelo accessor: o usuario pode nunca ter preenchido
     * o endereco, e a analise estatica tipa a relacao como sempre presente.
     *
     * @return array<string, string>|null
     */
    protected function payerAddress(Authentication $user): ?array
    {
        $address = $user->address()->first();

        // instanceof e nao apenas null-check: a relacao vem do pacote de
        // endereco sem anotacao generica, entao a analise estatica so a enxerga
        // como Model. A checagem tambem vale em execucao, para o caso de o
        // pacote de endereco nao estar instalado na aplicacao hospedeira.
        if (! $address instanceof Address) {
            return null;
        }

        // getAttribute e nao acesso por propriedade: o model do pacote de
        // endereco nao declara as colunas, entao a analise estatica nao tem como
        // conhece-las. Ler pela API do Eloquent e honesto sobre isso.
        $field = fn (string $key): string => trim((string) $address->getAttribute($key));

        $mapped = array_filter([
            'zip_code' => preg_replace('/\D/', '', $field('zip_code')),
            'street_name' => $field('address'),
            'street_number' => $field('number'),
            'neighborhood' => $field('district'),
            'city' => $field('city'),
            'federal_unit' => $field('state'),
        ], fn ($value) => $value !== null && $value !== '');

        // Endereco pela metade informa pouco e ainda arrisca conflitar com o que
        // o emissor tem cadastrado; so vale enviar com o minimo que identifica o
        // local.
        return isset($mapped['zip_code'], $mapped['street_name']) ? $mapped : null;
    }

    /**
     * Bloco `additional_info.payer` completo.
     *
     * @return array<string, mixed>
     */
    protected function payerAdditionalInfo(Authentication $user): array
    {
        $info = $this->payerNames($user->name);
        $info['registration_date'] = $user->created_at?->toIso8601String();

        if ($address = $this->payerAddress($user)) {
            $info['address'] = $address;
        }

        return $info;
    }
}
