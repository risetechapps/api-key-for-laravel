<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Events\ApiKeyExpiring;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;

/**
 * Avisa o dono de cada chave que vence dentro de `api_keys.expiry_warning_days`.
 *
 * Sem isto a chave vencia em silêncio: o cliente só descobria pelo 401 em
 * produção. Um aviso por chave e por data de validade — rodar de novo no mesmo
 * dia, ou no dia seguinte, não repete o e-mail; mudar o `expires_at` arma o
 * aviso outra vez.
 */
class NotifyExpiringApiKeysCommand extends Command
{
    protected $signature = 'api-key:notify-expiring
                            {--days= : Override the configured warning window}
                            {--dry-run : List the keys that would be warned about}';

    protected $description = 'Warn owners about API keys that expire soon';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('api-key.api_keys.expiry_warning_days', 7));

        if ($days <= 0) {
            $this->info('Expiry warnings are disabled (days = 0).');

            return self::SUCCESS;
        }

        $warned = 0;

        ApiKey::with('authentication')
            ->where('active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($days))
            ->chunkById(200, function ($keys) use (&$warned) {
                foreach ($keys as $key) {
                    $owner = $key->authentication;

                    if (! $owner || ! $owner->isEnabled()) {
                        continue;
                    }

                    $daysLeft = max(1, (int) ceil(now()->diffInSeconds($key->expires_at) / 86400));

                    if ($this->option('dry-run')) {
                        $this->line("{$key->getKey()}  {$owner->email}  {$key->expires_at->toDateTimeString()}  ({$daysLeft}d)");
                        $warned++;

                        continue;
                    }

                    // A data entra na chave do cache: validade estendida e que
                    // volta a se aproximar gera um aviso novo.
                    $once = 'api-key:expiring-notified:'.$key->getKey().':'.$key->expires_at->getTimestamp();

                    if (! Cache::add($once, true, $key->expires_at->copy()->addDay())) {
                        continue;
                    }

                    ApiKeyExpiring::dispatch($owner, $key, $daysLeft);
                    $warned++;
                }
            });

        $this->info($this->option('dry-run')
            ? "{$warned} key(s) would be warned about."
            : "{$warned} key(s) warned about.");

        return self::SUCCESS;
    }
}
