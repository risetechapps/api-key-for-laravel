<?php

namespace RiseTechApps\ApiKey\Repositories\Plan;


use RiseTechApps\ApiKey\Models\Plan;
use RiseTechApps\Repository\Core\BaseRepository;

class PlanEloquentRepository extends BaseRepository implements PlanRepository
{
    public function entity(): string
    {
        return Plan::class;
    }

    public function entityOn(): Plan
    {
        return new Plan();
    }

    public function registerViews(): array
    {
        return [];
    }
}
