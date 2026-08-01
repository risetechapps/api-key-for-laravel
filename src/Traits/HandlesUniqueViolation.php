<?php

namespace RiseTechApps\ApiKey\Traits;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Traduz a recusa do índice único em erro de campo.
 *
 * Validação não resolve corrida: duas requisições com o mesmo valor chegando
 * juntas passam as duas pela checagem, porque nenhuma vê a linha que a outra
 * ainda não gravou. O índice é o único árbitro nesse instante, e sem esta
 * tradução a violação cai no catch genérico e vira "tente novamente mais tarde"
 * — conselho errado para um conflito que não se resolve sozinho.
 */
trait HandlesUniqueViolation
{
    /**
     * Resposta 422 quando a exceção for violação de unicidade, null caso
     * contrário — para o chamador seguir com o tratamento genérico.
     *
     * Os códigos são os de unique violation por driver: 23000 no MySQL e no
     * SQLite, 23505 no PostgreSQL. O mapeamento para um campo só é honesto
     * porque nas tabelas em questão existe um único índice único; numa tabela
     * com vários, o código sozinho não diz qual deles recusou.
     */
    protected function uniqueViolationResponse(QueryException $e, string $field, string $message): ?JsonResponse
    {
        if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => __('api-key::messages.validation_failed'),
            'errors' => [$field => [$message]],
        ], 422);
    }
}
