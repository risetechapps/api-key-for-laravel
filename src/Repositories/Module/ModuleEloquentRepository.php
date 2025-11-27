<?php

namespace RiseTechApps\ApiKey\Repositories\Module;

use RiseTechApps\ApiKey\Models\Module;
use RiseTechApps\Repository\Core\BaseRepository;

class ModuleEloquentRepository extends BaseRepository implements ModuleRepository
{

    public function entity(): string
    {
        return Module::class;
    }

    public function entityOn(): Module
    {
        return new Module();
    }

    public function registerViews(): array
    {
        return [];
    }
}
