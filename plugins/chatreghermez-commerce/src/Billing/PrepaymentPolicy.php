<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

/**
 * Pre-payment (5% by cheque) on a media-rental invoice, in Rials:
 *   raw 5% < 500,000            -> 500,000 (minimum)
 *   500,000 <= raw < 1,000,000  -> nearest 100,000 (so 600,000 stays 600,000)
 *   raw >= 1,000,000            -> nearest 500,000 (58,885,938 -> 59,000,000; 32,418,514 -> 32,500,000)
 * Never above the invoice total. Mode 'nearest' (half up, default) or 'up' (ceiling to the same step).
 */
final class PrepaymentPolicy
{
    public const MIN_RIALS = 500000;
    public const STEP_RIALS = 500000;
    public const SMALL_STEP_RIALS = 100000;
    public const BAND_LIMIT_RIALS = 1000000;

    public static function compute(int $totalRials, int $bp = 500, string $mode = 'nearest'): int
    {
        if ($totalRials <= 0) {
            return 0;
        }
        $raw = intdiv($totalRials * $bp + 5000, 10000);
        if ($raw < self::MIN_RIALS) {
            $amount = self::MIN_RIALS;
        } else {
            $step = $raw < self::BAND_LIMIT_RIALS ? self::SMALL_STEP_RIALS : self::STEP_RIALS;
            $units = $mode === 'up' ? intdiv($raw + $step - 1, $step) : intdiv($raw + intdiv($step, 2), $step);
            $amount = max(self::MIN_RIALS, $units * $step);
        }
        return min($amount, $totalRials);
    }
}
