<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Domain;

/**
 * One slot = one media × one fiscal month. States:
 *   free -> locked (temporary, while a customer selects/pays) -> reserved (5-day hold after cheque pre-payment) -> final
 *   blocked = not rentable (from import "−"/"_" or manual). Locks and reservations lapse back to free by themselves.
 * Pure functions on plain arrays: slot = ['state'=>..., 'locked_until'=>ts|0, 'reserved_until'=>ts|0, 'holder'=>int].
 */
final class SlotRules
{
    public const FREE = 'free';
    public const LOCKED = 'locked';
    public const RESERVED = 'reserved';
    public const FINAL = 'final';
    public const BLOCKED = 'blocked';

    public const LOCK_SECONDS = 900;      // initial guaranteed lock: 15 minutes
    public const PRESENCE_SECONDS = 180;  // after that the lock lives only while the customer's page keeps pinging
    public const MAX_HOLD_SECONDS = 10800; // hard stop: 3 hours from the first lock, so an open tab can't block a board forever

    /** The state a customer would see "now" (expired locks/reservations are free). */
    public static function effectiveState(array $slot, int $now): string
    {
        $state = (string) ($slot['state'] ?? self::FREE);
        if ($state === self::LOCKED && (int) ($slot['locked_until'] ?? 0) <= $now) {
            return self::FREE;
        }
        if ($state === self::RESERVED && (int) ($slot['reserved_until'] ?? 0) <= $now) {
            return self::FREE;
        }
        return $state;
    }

    public static function canLock(array $slot, int $holder, int $now): bool
    {
        $s = self::effectiveState($slot, $now);
        return $s === self::FREE || ($s === self::LOCKED && (int) ($slot['holder'] ?? 0) === $holder);
    }

    /** Reservation is allowed from free, or from this holder's own lock. */
    public static function canReserve(array $slot, int $holder, int $now): bool
    {
        return self::canLock($slot, $holder, $now);
    }

    public static function canFinalize(array $slot, int $holder, int $now): bool
    {
        $s = self::effectiveState($slot, $now);
        return in_array($s, [self::LOCKED, self::RESERVED], true) && (int) ($slot['holder'] ?? 0) === $holder;
    }

    /** Calendar colour key the front end maps to a visual style. */
    public static function colour(string $effectiveState): string
    {
        return match ($effectiveState) {
            self::FREE => 'free', self::LOCKED => 'locked', self::RESERVED => 'reserved',
            self::FINAL => 'final', default => 'grey',
        };
    }

    /** Whole days left on a reservation, for the "N days left" message (0 when none). */
    public static function daysLeft(array $slot, int $now): int
    {
        $until = (int) ($slot['reserved_until'] ?? 0);
        return $until > $now ? (int) ceil(($until - $now) / 86400) : 0;
    }

    /**
     * Heartbeat from the selection/payment pages. Returns the new locked_until, or null when the customer has held the slot
     * past the hard limit (the lock then lapses normally and others can take it).
     */
    public static function heartbeatUntil(int $lockedAt, int $lockedUntil, int $now): ?int
    {
        if ($lockedAt > 0 && $now - $lockedAt > self::MAX_HOLD_SECONDS) {
            return null;
        }
        return max($lockedUntil, $now + self::PRESENCE_SECONDS);
    }
}
