<?php

namespace RiseTechApps\ApiKey\Console\Concerns;

/**
 * Comando renomeado que ainda atende pelo nome antigo (`$aliases`). Chamado
 * pelo nome antigo, roda normalmente e avisa para trocar: um cron ou script
 * que usava o nome antigo não quebra na 1.x.
 */
trait WarnsDeprecatedAlias
{
    protected function warnIfDeprecatedAlias(): void
    {
        $invoked = $this->input->getFirstArgument();

        if (is_string($invoked) && in_array($invoked, (array) $this->aliases, true)) {
            $this->warn("O comando {$invoked} foi renomeado para {$this->getName()} e o nome antigo sai na próxima versão maior. Atualize o seu cron ou script.");
        }
    }
}
