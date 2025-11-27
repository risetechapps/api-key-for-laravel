<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;
use RiseTechApps\ApiKey\Models\Coupon;
use RiseTechApps\Repository\Core\BaseRepository;
class CouponEloquentRepository extends BaseRepository implements CouponRepository
{
    public function entity(): string
    {
        return Coupon::class;
    }

    public function entityOn(): Coupon
    {
        return new Coupon();
    }

    public function registerViews(): array
    {
        return [];
    }
}
