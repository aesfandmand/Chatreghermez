<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

/**
 * Pre-payment (5% by cheque) on a media-rental invoice, in Rials. The 5% result is rounded UP, and only when it is not
 * already a multiple of the step (multiples are left untouched):
 *   raw < 500,000      -> 500,000 (minimum)
 *   raw < 10,000,000   -> next multiple of 50,000   (1,120,000 -> 1,150,000; 858,000 -> 900,000; 850,000 stays)
 *   raw >= 10,000,000  -> next multiple of 500,000  (58,885,938 -> 59,000,000; 32,418,514 -> 32,500,000; 12,000,000 stays)
 * The 10,000,000 switch point is an assumption pending owner confirmation. Never above the invoice total.
 */
final class PrepaymentPolicy
{
    public const MIN_RIALS = 500000;
    public const SMALL_STEP_RIALS = 50000;
    public const LARGE_STEP_RIALS = 500000;
    public const SWITCH_RIALS = 10000000;

    public static function compute(int $totalRials, int $bp = 500): int
    {
        if ($totalRials <= 0) {
            return 0;
        }
        $raw = intdiv($totalRials * $bp + 9999, 10000); // exact 5%, fractions of a Rial go up
        if ($raw < self::MIN_RIALS) {
            return min(self::MIN_RIALS, $totalRials);
        }
        $step   = $raw < self::SWITCH_RIALS ? self::SMALL_STEP_RIALS : self::LARGE_STEP_RIALS;
        $amount = intdiv($raw + $step - 1, $step) * $step;
        return min($amount, $totalRials);
    }
}
