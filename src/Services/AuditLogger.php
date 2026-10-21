<?php

namespace RiseTechApps\ApiKey\Services;

use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use Throwable;

/**
 * Grava a trilha de auditoria.
 *
 * Nunca derruba a operação auditada: se a gravação falhar, a ação já
 * aconteceu, e transformar isso em erro para o usuário só esconderia o que foi
 * feito. A falha vai para o report() e para o log de aplicação.
 */
class AuditLogger
{
    /**
     * @param  string  $action  Verbo no formato `area.acao` (ex.: `admin.user_suspended`).
     * @param  Authentication|null  $subject  Conta afetada.
     * @param  Authentication|null  $actor  Quem agiu; por padrão o usuário autenticado.
     */
    public static function record(
        string $action,
        ?Authentication $subject = null,
        array $context = [],
        ?Authentication $actor = null,
    ): void {
        $actor ??= auth()->user() instanceof Authentication ? auth()->user() : null;
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        try {
            AuditLog::create([
                'actor_id' => $actor?->getKey(),
                'subject_id' => $subject?->getKey(),
                'action' => $action,
                'context' => $context ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        logglyInfo()->withContext([
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'subject_id' => $subject?->getKey(),
            ...$context,
        ])->log('Audit: '.$action);
    }
}
