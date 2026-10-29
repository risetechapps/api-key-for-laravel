<?php

namespace RiseTechApps\ApiKey\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Requisições por dia, a partir do `request_logs`.
 *
 * Fonte do gráfico do painel (`/dashboard/stats?days=`) e do
 * `GET /usage/daily` da API do cliente.
 *
 * Conta o que foi registrado, e o registro inclui o que não consumiu cota (o
 * 429 de cota esgotada, os 5xx): a soma do período não é o `requests_used`.
 * Por isso a divisão por classe de status vem junto.
 */
class UsageHistory
{
    public const MAX_DAYS = 90;

    /**
     * Agregado no banco, numa janela limitada. Todos os dias da janela saem,
     * inclusive os vazios, para quem consome não ter que reconstruir buracos.
     * Datas no fuso da aplicação.
     *
     * @return list<array{date: string, total: int, success: int, client_error: int, server_error: int}>
     */
    public static function daily(Authentication $user, int $days): array
    {
        $days = max(1, min($days, self::MAX_DAYS));
        $since = now()->subDays($days - 1)->startOfDay();

        // `response_code` é texto: classificar pelo primeiro dígito funciona
        // igual em PostgreSQL, MySQL e SQLite, sem cast.
        $rows = Cache::remember(
            "api_key_usage_daily:{$user->getKey()}:{$days}:".now()->toDateString(),
            config('api-key.cache_ttl.stats', 30),
            fn () => $user->requestLog()
                ->where('requested_at', '>=', $since)
                ->selectRaw(self::dayExpression().' as day')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN response_code LIKE '2%' OR response_code LIKE '3%' THEN 1 ELSE 0 END) as success")
                ->selectRaw("SUM(CASE WHEN response_code LIKE '4%' THEN 1 ELSE 0 END) as client_error")
                ->selectRaw("SUM(CASE WHEN response_code LIKE '5%' THEN 1 ELSE 0 END) as server_error")
                ->groupBy('day')
                ->toBase()
                ->get()
                ->keyBy('day')
                ->map(fn ($row) => [
                    'total' => (int) $row->total,
                    'success' => (int) $row->success,
                    'client_error' => (int) $row->client_error,
                    'server_error' => (int) $row->server_error,
                ])
                ->all()
        );

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                ...($rows[$date] ?? ['total' => 0, 'success' => 0, 'client_error' => 0, 'server_error' => 0]),
            ];
        }

        return $series;
    }

    /**
     * Portable "truncate timestamp to day" expression.
     * Postgres has no DATE() function for timestamps; SQLite has no CAST to date.
     */
    private static function dayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(requested_at AS date)',
            'sqlite' => "strftime('%Y-%m-%d', requested_at)",
            default => 'DATE(requested_at)',
        };
    }
}
