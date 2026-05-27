<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;

use RiseTechApps\Repository\Contracts\RepositoryInterface;

interface CouponRepository extends RepositoryInterface
{
    public function incrementUses(\RiseTechApps\ApiKey\Models\Coupon\Coupon $coupon): void;
}
