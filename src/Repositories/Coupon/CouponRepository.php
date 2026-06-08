<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;

use RiseTechApps\Repository\Contracts\RepositoryInterface;

interface CouponRepository extends RepositoryInterface
{
    public function incrementUses(\RiseTechApps\ApiKey\Models\Coupon\Coupon $coupon): void;

    /**
     * Ignora o cache na próxima leitura, indo direto ao banco.
     * Necessário porque, com cache driver sem suporte a tags (file/database),
     * o clearCacheForEntity() não invalida a lista após store/update/delete.
     */
    public function withoutCache(): static;
}
