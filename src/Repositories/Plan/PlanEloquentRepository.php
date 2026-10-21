<?php

namespace RiseTechApps\ApiKey\Repositories\Plan;

use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\Repository\Core\BaseRepository;

/**
 * @extends BaseRepository<Plan>
 */
class PlanEloquentRepository extends BaseRepository implements PlanRepository
{
    protected array $filterable = ['name', 'is_active', 'billing_cycle'];

    public function entity(): string
    {
        return Plan::class;
    }

    public function entityOn(): Plan
    {
        return new Plan;
    }

    #[\Override]
    public function registerViews(): array
    {
        return [];
    }

    public function findActiveById(string $id): ?Plan
    {
        // O id vem do corpo da requisição. A chave é uuid, e no PostgreSQL um
        // valor que não é uuid derruba a consulta (500) em vez de não achar.
        if (! Str::isUuid($id)) {
            return null;
        }

        return Plan::query()->where('is_active', true)->find($id);
    }
}
