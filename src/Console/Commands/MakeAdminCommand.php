<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\ApiKey\Console\Concerns\WarnsDeprecatedAlias;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class MakeAdminCommand extends Command
{
    use WarnsDeprecatedAlias;

    protected $signature = 'api-key:admin-make {email : E-mail do usuário}';

    /** Nome até a 1.0; sai na próxima versão maior. */
    protected $aliases = ['admin:make'];

    protected $description = 'Concede a role de administrador a um usuário';

    public function handle(): int
    {
        $this->warnIfDeprecatedAlias();

        $email = $this->argument('email');

        $user = Authentication::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if (! $user) {
            $this->error("Usuário com e-mail '{$email}' não encontrado.");

            return self::FAILURE;
        }

        $user->role = 'admin';
        $user->save();

        $this->info("Usuário {$user->name} ({$email}) agora é administrador.");

        return self::SUCCESS;
    }
}
