<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\Repository\Core\BaseRepository;

/**
 * @extends BaseRepository<Coupon>
 */
class CouponEloquentRepository extends BaseRepository implements CouponRepository
{
    protected array $filterable = ['code', 'is_active'];

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

    public function claimUse(Coupon $coupon, ?string $authenticationId = null): bool
    {
        $limit = $authenticationId === null ? 0 : self::perUserLimit();

        if ($limit === 0) {
            return $this->claimGlobal($coupon);
        }

        // The row lock serialises claims of this coupon, so two checkouts of the
        // same account cannot both read "one use left" and both pass.
        return DB::transaction(function () use ($coupon, $authenticationId, $limit) {
            Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->first();

            if ($this->redemptions($coupon, $authenticationId)->count() >= $limit) {
                return false;
            }

            if (! $this->claimGlobal($coupon)) {
                return false;
            }

            DB::table('coupon_redemptions')->insert([
                'coupon_id' => $coupon->getKey(),
                'authentication_id' => $authenticationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function usedUpBy(Coupon $coupon, string $authenticationId): bool
    {
        $limit = self::perUserLimit();

        return $limit > 0 && $this->redemptions($coupon, $authenticationId)->count() >= $limit;
    }

    private function claimGlobal(Coupon $coupon): bool
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

    public function releaseUse(Coupon $coupon, ?string $authenticationId = null): void
    {
        if ($authenticationId !== null) {
            $id = $this->redemptions($coupon, $authenticationId)->latest('id')->value('id');

            if ($id !== null) {
                DB::table('coupon_redemptions')->where('id', $id)->delete();
            }
        }

        Coupon::query()
            ->whereKey($coupon->getKey())
            ->where('uses', '>', 0)
            ->decrement('uses');

        $coupon->uses = max(0, (int) $coupon->uses - 1);
        $coupon->syncOriginal();

        $this->clearCacheForEntity();
    }

    private function redemptions(Coupon $coupon, string $authenticationId): Builder
    {
        return DB::table('coupon_redemptions')
            ->where('coupon_id', $coupon->getKey())
            ->where('authentication_id', $authenticationId);
    }

    private static function perUserLimit(): int
    {
        return max(0, (int) config('api-key.coupons.max_uses_per_user', 1));
    }
}
