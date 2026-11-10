<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

/** Rental reservation hold: 5 days, extendable at most 2 more times by 5 days each. */
final class ReservationPolicy
{
    public const HOLD_DAYS = 5;
    public const MAX_EXTENSIONS = 2;

    public static function initialExpiry(int $now): int
    {
        return $now + self::HOLD_DAYS * 86400;
    }

    public static function canExtend(int $extensionsUsed, int $expiresAt, int $now): bool
    {
        return $extensionsUsed < self::MAX_EXTENSIONS && $expiresAt > $now;
    }

    /** New expiry counts from the current expiry (no days lost or gained). */
    public static function extendedExpiry(int $expiresAt): int
    {
        return $expiresAt + self::HOLD_DAYS * 86400;
    }
}
