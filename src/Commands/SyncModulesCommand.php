<?php

namespace RiseTechApps\ApiKey\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;
use RiseTechApps\ApiKey\Models\Module;

class SyncModulesCommand extends Command
{
    protected $signature = 'sync:modules';
    protected $description = 'Sincroniza automaticamente os módulos a partir dos Controllers do sistema.';

    public function handle(): void
    {
        $controllers = $this->getProjectAndPackageControllers();

        foreach ($controllers as $controller) {
            $methods = $this->getControllerMethods($controller);
            $modules = $this->formatModuleNames($controller, $methods);

            foreach ($modules as $moduleKey => $methodName) {

                if (!Module::where('module', $methodName)->exists()) {
                    $friendlyName = $this->askFriendlyName($moduleKey);

                    Module::create([
                        'name' => $friendlyName,
                        'module' => $methodName,
                    ]);

                    $this->line("📌 Módulo Registrado: {$friendlyName} ({$methodName})");
                } else {
                    $this->line("✅ Módulo já existente: " . $methodName);
                }
            }
        }

        $this->info('🚀 Módulos sincronizados com sucesso!');
    }

    /**
     * Pergunta ao usuário um nome amigável para o módulo antes de salvar.
     */
    private function askFriendlyName(string $moduleKey): string
    {
        return $this->ask("📝 Digite um nome amigável para o módulo '{$moduleKey}'", $moduleKey);
    }

    /**
     * Obtém todos os controllers do projeto e dos pacotes instalados (exceto Laravel e Spatie).
     */
    private function getProjectAndPackageControllers(): array
    {
        $controllers = [];
        $paths = [app_path('Http/Controllers')];

        foreach (glob(base_path('vendor/*/*/src/Http/Controllers'), GLOB_ONLYDIR) as $packagePath) {
            if (!str_contains($packagePath, 'vendor/laravel') && !str_contains($packagePath, 'vendor/spatie')) {
                $paths[] = $packagePath;
            }
        }

        foreach ($paths as $path) {
            if (!File::exists($path)) continue;

            $files = File::allFiles($path);
            foreach ($files as $file) {
                $namespace = $this->getNamespaceFromPath($file->getPath());
                $className = $namespace . '\\' . $file->getFilenameWithoutExtension();

                if (str_contains($className, 'App\Http\Controllers\Controller')) {
                    continue;
                }

                if (class_exists($className)) {
                    $controllers[] = $className;
                }
            }
        }

        return $controllers;
    }

    /**
     * Obtém o namespace correto para os controllers dos pacotes.
     */
    private function getNamespaceFromPath($path): string
    {
        $baseNamespace = 'App\\Http\\Controllers';

        if (str_contains($path, base_path('app/Http/Controllers'))) {
            return $baseNamespace;
        }

        foreach (glob(base_path('vendor/*/*/src/Http/Controllers'), GLOB_ONLYDIR) as $packagePath) {
            if (str_starts_with($path, $packagePath)) {
                $namespaceParts = explode('/', str_replace(base_path('vendor/'), '', $packagePath));
                return ucfirst($namespaceParts[0]) . '\\' . ucfirst($namespaceParts[1]) . '\\Http\\Controllers';
            }
        }

        return $baseNamespace;
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
                ->reject(fn($method) =>
                    $method->class === 'Illuminate\Routing\Controller' ||
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
