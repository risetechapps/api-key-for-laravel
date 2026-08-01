<?php

namespace RiseTechApps\ApiKey\Repositories\Plan;

use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\Repository\Contracts\RepositoryInterface;

interface PlanRepository extends RepositoryInterface
{
    /**
     * Find a plan that is currently on sale.
     *
     * `findById()` resolves any plan, including one an administrator has taken
     * off sale. Every path that lets a user *acquire* a plan must go through
     * this method instead: the catalogue endpoint already filters on
     * `is_active`, but the plan id travels in the request body, so a
     * deactivated plan stayed purchasable by anyone who still knew its id.
     */
    public function findActiveById(string $id): ?Plan;
}
