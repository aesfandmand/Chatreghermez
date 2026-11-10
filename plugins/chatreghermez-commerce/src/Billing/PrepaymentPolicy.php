<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

/**
 * Pre-payment (5% by cheque) on a media-rental invoice, in Rials: the 5% is rounded UP to a multiple of 500,000 Rials
 * (50,000 Toman). Exact multiples stay as they are. Minimum 500,000. Never above the invoice total.
 * 12,000,000 stays; 58,885,938 -> 59,000,000; 32,418,514 -> 32,500,000; 600,000 -> 1,000,000.
 */
final class PrepaymentPolicy
{
    public const STEP_RIALS = 500000;

    public static function compute(int $totalRials, int $bp = 500): int
    {
        if ($totalRials <= 0) {
            return 0;
        }
        $raw    = intdiv($totalRials * $bp + 9999, 10000);
        $amount = max(1, intdiv($raw + self::STEP_RIALS - 1, self::STEP_RIALS)) * self::STEP_RIALS;
        return min($amount, $totalRials);
    }
}
