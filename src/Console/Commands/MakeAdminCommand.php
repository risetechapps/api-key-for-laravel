<?php

namespace RiseTechApps\ApiKey\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class MakeAdminCommand extends Command
{
    protected $signature = 'admin:make {email : E-mail do usuário}';
    protected $description = 'Concede a role de administrador a um usuário';

    public function handle(): int
    {
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
