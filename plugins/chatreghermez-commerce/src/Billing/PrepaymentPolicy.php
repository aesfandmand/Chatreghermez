<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

/**
 * Pre-payment (5% by cheque) on a media-rental invoice. Amount is always a multiple of STEP (500,000 Rials = 50,000 Toman),
 * never less than one step, never more than the invoice. Direction is configurable: 'nearest' (half up, default) or 'up'.
 */
final class PrepaymentPolicy
{
    public const STEP_RIALS = 500000;

    public static function compute(int $totalRials, int $bp = 500, string $mode = 'nearest', int $step = self::STEP_RIALS): int
    {
        if ($totalRials <= 0 || $step <= 0) {
            return 0;
        }
        $num = $totalRials * $bp;                    // exact: total * bp / 10000
        $den = 10000 * $step;
        $units = $mode === 'up' ? intdiv($num + $den - 1, $den) : intdiv($num + intdiv($den, 2), $den);
        $units = max(1, $units);
        return min($units * $step, $totalRials);
    }
}
