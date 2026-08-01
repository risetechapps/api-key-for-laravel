<?php

namespace RiseTechApps\ApiKey\Repositories\Coupon;

use RiseTechApps\Repository\Contracts\RepositoryInterface;

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
     * @return bool False when the coupon is inactive, expired or exhausted.
     */
    public function claimUse(\RiseTechApps\ApiKey\Models\Coupon\Coupon $coupon): bool;

    /**
     * Give a claimed use back, for when the payment it was claimed for never
     * completed. Never drops below zero.
     */
    public function releaseUse(\RiseTechApps\ApiKey\Models\Coupon\Coupon $coupon): void;

    /**
     * Ignora o cache na próxima leitura, indo direto ao banco.
     * Necessário porque, com cache driver sem suporte a tags (file/database),
     * o clearCacheForEntity() não invalida a lista após store/update/delete.
     */
    public function withoutCache(): static;
}
