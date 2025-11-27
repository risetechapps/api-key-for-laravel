<?php

namespace RiseTechApps\ApiKey\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use RiseTechApps\ApiKey\Models\Module;
use RiseTechApps\ApiKey\Repositories\Module\ModuleRepository;

class SyncModulesCommand extends Command
{
    protected $signature = 'plans:modules-sync';
    protected $description = "Automatically synchronizes modules from the system's Controllers.";

    // packages/RiseTech/ApiKey/src/Commands/SyncModulesCommand.php

    public function handle(ModuleRepository $moduleRepository): void
    {
        $this->info('🔍 Searching for modules in controllers...');
        $allModules = $this->findAllModules();
        $existingModules = Module::pluck('module')->toArray();

        $newModules = array_diff($allModules, $existingModules);
        $registeredCount = 0;

        if (empty($newModules)) {
            $this->info('✅ All modules are already synchronized. Nothing to do.');
            return;
        }

        $this->warn('Found ' . count($newModules) . ' new potential modules.');

        $registeredCount = $this->handleInteractiveMode($newModules, $moduleRepository);

        if ($registeredCount > 0) {
            $this->info("🚀 Successfully registered {$registeredCount} new modules!");
        } else {
            $this->info("No new modules were registered.");
        }
    }

    /**
     * Encontra todos os módulos (Controller@method) no projeto.
     */
    private function findAllModules(): array
    {
        $controllers = $this->getProjectAndPackageControllers(); // Usando o método robusto que criamos antes
        $allModules = [];

        foreach ($controllers as $controller) {
            $methods = $this->getControllerMethods($controller);
            foreach ($methods as $method) {
                $allModules[] = $controller . '@' . $method;
            }
        }
        return $allModules;
    }

    /**
     * Lida com o registro de módulos no modo interativo.
     */
    private function handleInteractiveMode(array $newModules, ModuleRepository $moduleRepository): int
    {
        $count = 0;
        foreach ($newModules as $moduleName) {
            $this->line(''); // Espaço em branco para legibilidade
            $this->line("📌 Found new module: <fg=yellow>{$moduleName}</>");

            $status = $this->confirm("Do you want to register this module?", true);

            $friendlyName = $moduleName;
            $description = "";

            if($status){
                $friendlyName = $this->ask("📝 Enter a friendly name", $moduleName);
                $description = $this->ask("📖 Enter a short description (optional)");
            }

            $moduleRepository->store([
                'name' => $friendlyName,
                'module' => $moduleName,
                'description' => $description,
                'status' => $status,
            ]);

            $this->info("✅ Module '{$friendlyName}' registered.");
            $count++;
        }
        return $count;
    }

    /**
     * Lida com o registro de módulos no modo automático.
     */
    private function handleAutomaticMode(array $newModules): int
    {
        if (!$this->option('all')) {
            $this->line("\nNew modules found. To register them, run with:");
            $this->line("  <fg=cyan>--interactive</> to register them one by one.");
            $this->line("  <fg=cyan>--all</> to register all of them with default names.");
            return 0;
        }

        $count = 0;
        foreach ($newModules as $moduleName) {
            Module::create([
                'name' => $moduleName, // Nome padrão
                'module' => $moduleName,
                'description' => 'Automatically registered module.', // Descrição padrão
            ]);
            $this->line("✅ Registered: {$moduleName}");
            $count++;
        }
        return $count;
    }

// ... mantenha os outros métodos (getProjectAndPackageControllers, getControllerMethods, etc.)


    /**
     * Pergunta ao usuário um nome amigável para o módulo antes de salvar.
     */
    private function askFriendlyName(string $moduleKey): string
    {
        return $this->ask("📝 Enter a friendly name for the module '{$moduleKey}'", $moduleKey);
    }

    /**
     * Obtém todos os controllers do projeto e dos pacotes instalados (exceto Laravel e Spatie).
     */
    // ... (dentro da sua classe SyncModulesCommand)

    private function getProjectAndPackageControllers(): array
    {
        $controllers = [];
        // Define o caminho base do namespace 'App'
        $appPath = app_path();

        // Define os caminhos a serem escaneados
        $paths = [
            $appPath . '/Http/Controllers',
            // Adicione aqui outros caminhos se necessário
        ];

        // Escaneia dinamicamente os pacotes em 'vendor'
        // Esta lógica para encontrar pacotes está boa, vamos mantê-la.
        foreach (glob(base_path('vendor/*/*/src/Http/Controllers'), GLOB_ONLYDIR) as $packagePath) {
            if (!str_contains($packagePath, 'vendor/laravel') && !str_contains($packagePath, 'vendor/spatie')) {
                $paths[] = $packagePath;
            }
        }

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                continue;
            }

            $files = File::allFiles($path);

            foreach ($files as $file) {
                // --- INÍCIO DA LÓGICA CORRIGIDA ---

                // Constrói o namespace dinamicamente a partir do caminho do arquivo
                $className = Str::of($file->getRealPath())
                    ->replace([base_path(), '.php'], '') // Remove o caminho base e a extensão
                    ->trim('/\\') // Remove barras no início/fim
                    ->replace('/', '\\') // Converte barras de diretório em barras de namespace
                    ->ucfirst() // Garante que o primeiro caractere (geralmente 'App' ou nome do Vendor) seja maiúsculo
                    ->value();

                // --- FIM DA LÓGICA CORRIGIDA ---

                // Pula a classe base Controller, se encontrada.
                if ($className === 'App\Http\Controllers\Controller') {
                    continue;
                }

                // Agora, a verificação deve funcionar corretamente, desde que o autoloader esteja atualizado.
                // A melhor prática é usar Reflection para garantir que é uma classe instanciável.
                try {
                    $reflection = new ReflectionClass($className);

                    // Garante que é uma classe concreta (não abstrata, interface ou trait)
                    // e que ela estende a classe Controller base.
                    if ($reflection->isInstantiable() && $reflection->isSubclassOf(\App\Http\Controllers\Controller::class)) {
                        $controllers[] = $className;
                    }
                } catch (\ReflectionException $e) {
                    continue;
                }
            }
        }

        return $controllers;
    }

    /**
     * Formata os nomes dos módulos corretamente no formato "Controller@Método".
     */
    private function formatModuleNames(string $controller, array $methods): array
    {
        $modules = [];
        foreach ($methods as $method) {
            $modules[$controller . "@" . $method] = $controller . "@" . $method;
        }
        return $modules;
    }

    /**
     * Obtém os métodos públicos de um controller, ignorando os herdados e os métodos indesejados.
     */
    private function getControllerMethods($controller): array
    {
        try {
            $reflection = new ReflectionClass($controller);
            return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
                ->reject(fn($method) => $method->class === 'Illuminate\Routing\Controller' ||
                    in_array($method->name, $this->ignoredMethods())
                )
                ->pluck('name')
                ->toArray();
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    /**
     * Define métodos que devem ser ignorados ao registrar módulos.
     */
    private function ignoredMethods(): array
    {
        return [
            '__construct',
            'authorize',
            'authorizeForUser',
            'authorizeResource',
            'validateWith',
            'validate',
            'validateWithBag',
            'middleware'
        ];
    }
}
