<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;

use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\Repository\Contracts\RepositoryInterface;

/**
 * @extends RepositoryInterface<Coupon>
 */
interface CouponRepository extends RepositoryInterface
{
    /**
     * Claim one use of the coupon, atomically.
     *
     * Replaces the previous read-then-increment pair (`isValid()` followed by
     * `incrementUses()` after the charge went through). Between those two calls
     * every concurrent checkout observed the same `uses` value, so N buyers
     * redeeming a last-slot coupon at once all passed the check and `max_uses`
     * was overrun by N-1 — and, because the increment only happened after the
     * payment, there was no way to refuse the extra ones.
     *
     * Validity and the increment are one conditional UPDATE that the database
     * serialises on the row, so exactly `max_uses` claims can ever succeed.
     * Callers must claim *before* charging and release on failure.
     *
     * With `$authenticationId`, the claim also counts against the account's
     * own limit (`api-key.coupons.max_uses_per_user`) and is recorded in
     * `coupon_redemptions`.
     *
     * @return bool False when the coupon is inactive, expired or exhausted, or
     *              the account already used it as many times as allowed.
     */
    public function claimUse(Coupon $coupon, ?string $authenticationId = null): bool;

    /** Whether the account already used the coupon as many times as allowed. */
    public function usedUpBy(Coupon $coupon, string $authenticationId): bool;

    /**
     * Give a claimed use back, for when the payment it was claimed for never
     * completed. Never drops below zero.
     */
    public function releaseUse(Coupon $coupon, ?string $authenticationId = null): void;

    /**
     * Ignora o cache na próxima leitura, indo direto ao banco.
     * Necessário porque, com cache driver sem suporte a tags (file/database),
     * o clearCacheForEntity() não invalida a lista após store/update/delete.
     */
    public function withoutCache(): static;
}
