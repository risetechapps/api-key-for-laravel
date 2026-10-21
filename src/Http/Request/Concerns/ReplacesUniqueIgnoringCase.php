<?php

namespace RiseTechApps\ApiKey\Http\Request\Concerns;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\ApiKey\Rules\Validation\UniqueIgnoringCase;

/**
 * Troca o `unique:` que vem do RulesRegistry por uma checagem insensível a caixa.
 *
 * As regras são compartilhadas com outros pacotes, então não dá para alterá-las
 * na origem só por causa da normalização do to-upper. A substituição acontece
 * aqui, no request, e é local a este pacote.
 */
trait ReplacesUniqueIgnoringCase
{
    /**
     * @param  array<string, mixed>  $rules  Regras vindas do registry.
     * @param  class-string<Model>  $model
     * @return array<string, mixed>
     */
    protected function uniqueIgnoringCase(
        array $rules,
        string $field,
        string $model,
        Model|string|null $ignore = null,
        ?string $message = null,
    ): array {
        $current = $rules[$field] ?? '';
        $list = is_array($current) ? $current : explode('|', (string) $current);

        // O `unique:` sai porque a regra nova o cobre: comparação insensível a
        // caixa inclui a igualdade exata. Manter os dois faria a mesma consulta
        // duas vezes.
        $list = array_values(array_filter(
            $list,
            fn ($rule) => ! is_string($rule) || ! str_starts_with($rule, 'unique:')
        ));

        // Entra no fim, depois de required/min/max: com o `bail` da regra
        // original, um campo vazio ou curto falha antes e não consulta o banco.
        $list[] = new UniqueIgnoringCase($model, $field, $ignore, $message);

        $rules[$field] = $list;

        return $rules;
    }
}
