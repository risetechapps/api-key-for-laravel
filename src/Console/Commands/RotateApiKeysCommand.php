<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Issues new API keys in bulk.
 *
 * ApiKey::resolveLegacyKey() recommends rotating legacy keys when a v1 -> v2
 * migration leaves a backlog large enough that the bcrypt fallback scan starts
 * costing real time on authentication — but the only rotation the package
 * offered was ProfileController::regenerateKey(), one user at a time, through
 * the dashboard. Advice with no tool behind it.
 *
 * Rotation is destructive by nature: the moment a key is replaced, every client
 * still holding the old one starts getting 401s. The command therefore refuses
 * to guess at scope, and refuses to discard the new keys it generates.
 */
class RotateApiKeysCommand extends Command
{
    protected $signature = 'api-key:rotate-keys
                            {--legacy : Only keys still missing a lookup_hash (the v1 -> v2 backlog)}
                            {--user= : A single owner, by authentication id or e-mail}
                            {--all : Every active key}
                            {--output= : CSV path to write the new keys to}
                            {--dry-run : Report what would be rotated, change nothing}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Issue new API keys in bulk and write them to a CSV';

    public function handle(): int
    {
        $query = $this->scopedQuery();

        if (! $query) {
            return self::FAILURE;
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No API keys matched; nothing to rotate.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$total} API key(s) would be rotated.");

            return self::SUCCESS;
        }

        $output = $this->option('output');

        // A rotated key exists in plain text exactly once, in memory, during this
        // command — afterwards only the bcrypt hash remains. Rotating without
        // somewhere to put them would lock every affected client out with no way
        // to let them back in.
        if (! $output && $total > 1) {
            $this->error("Refusing to rotate {$total} keys with nowhere to write them.");
            $this->line('The new keys cannot be recovered afterwards. Pass --output=path.csv.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirmRotation($total)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $handle = null;

        if ($output) {
            $handle = @fopen($output, 'w');

            if (! $handle) {
                $this->error("Unable to open {$output} for writing.");

                return self::FAILURE;
            }

            fputcsv($handle, ['authentication_id', 'email', 'api_key']);
        }

        $rotated = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->with('authentication')->chunkById(200, function ($apiKeys) use (&$rotated, $handle, $bar) {
            foreach ($apiKeys as $apiKey) {
                $plain = bin2hex(random_bytes(64));

                // The saving hook hashes the key and recomputes lookup_hash, so a
                // rotated row also leaves the legacy set on its own.
                $apiKey->update(['key' => $plain]);

                if ($handle) {
                    fputcsv($handle, [
                        $apiKey->authentication_id,
                        $apiKey->authentication?->email,
                        $plain,
                    ]);
                } else {
                    $this->newLine();
                    $this->line("{$apiKey->authentication?->email}: {$plain}");
                }

                $rotated++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);

        if ($handle) {
            fclose($handle);
            // 0600: the file holds live credentials in plain text. chmod is a
            // no-op on Windows, which is why the warning below is unconditional.
            @chmod($output, 0600);
            $this->info("Wrote {$rotated} key(s) to {$output}.");
            $this->warn('That file grants full API access. Distribute it and delete it.');
        }

        Log::warning('api-key: bulk key rotation completed', [
            'rotated' => $rotated,
            'scope' => $this->scopeLabel(),
        ]);

        $this->info("Rotated {$rotated} API key(s).");
        $this->warn('Clients using the previous keys now receive 401 until they are given the new ones.');
        $this->line('The package sends no notification for a rotation — telling the owners is on you.');

        return self::SUCCESS;
    }

    /**
     * Build the query for the requested scope.
     *
     * Exactly one selector is required. Defaulting to "everything" would make a
     * mistyped flag revoke every key in the installation.
     */
    private function scopedQuery()
    {
        $selectors = array_filter([
            $this->option('legacy'),
            (bool) $this->option('user'),
            $this->option('all'),
        ]);

        if (count($selectors) !== 1) {
            $this->error('Choose exactly one of --legacy, --user= or --all.');

            return null;
        }

        $query = ApiKey::query()->where('active', true);

        if ($this->option('legacy')) {
            return $query->whereNull('lookup_hash');
        }

        if ($this->option('all')) {
            return $query;
        }

        $user = $this->resolveUser((string) $this->option('user'));

        if (! $user) {
            $this->error('No user matched '.$this->option('user').'.');

            return null;
        }

        return $query->where('authentication_id', $user->getKey());
    }

    private function resolveUser(string $identifier): ?Authentication
    {
        return Authentication::where('id', $identifier)
            ->orWhereRaw('LOWER(email) = ?', [strtolower($identifier)])
            ->first();
    }

    private function confirmRotation(int $total): bool
    {
        $this->warn("About to rotate {$total} API key(s) ({$this->scopeLabel()}).");
        $this->warn('Every client still using the current keys will start getting 401 immediately.');

        return $this->confirm('Continue?', false);
    }

    private function scopeLabel(): string
    {
        return match (true) {
            (bool) $this->option('legacy') => 'legacy keys without lookup_hash',
            (bool) $this->option('all') => 'all active keys',
            default => 'user '.$this->option('user'),
        };
    }
}
