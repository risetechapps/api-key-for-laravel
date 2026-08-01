<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;

use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\Repository\Core\BaseRepository;

class CouponEloquentRepository extends BaseRepository implements CouponRepository
{
    public function entity(): string
    {
        return Coupon::class;
    }

    public function entityOn(): Coupon
    {
        return new Coupon;
    }

    #[\Override]
    public function registerViews(): array
    {
        return [];
    }

    public function claimUse(Coupon $coupon): bool
    {
        // The WHERE clause mirrors Coupon::isValid(). `expires_at` is a date cast
        // (midnight), and isValid() rejects it once isPast() — so a coupon whose
        // expiry is today is already spent. `> now()` reproduces that in SQL.
        $claimed = Coupon::query()
            ->whereKey($coupon->getKey())
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'))
            ->increment('uses');

        if ($claimed <= 0) {
            return false;
        }

        // Keep the in-memory model in step with the row the UPDATE just moved.
        $coupon->uses = (int) $coupon->uses + 1;
        $coupon->syncOriginal();

        $this->clearCacheForEntity();

        return true;
    }

    public function releaseUse(Coupon $coupon): void
    {
        Coupon::query()
            ->whereKey($coupon->getKey())
            ->where('uses', '>', 0)
            ->decrement('uses');

        $coupon->uses = max(0, (int) $coupon->uses - 1);
        $coupon->syncOriginal();

        $this->clearCacheForEntity();
    }
}
