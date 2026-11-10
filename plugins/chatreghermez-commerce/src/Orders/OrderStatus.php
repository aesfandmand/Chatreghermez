<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

/** Order lifecycle and the only allowed transitions. */
final class OrderStatus
{
    public const DRAFT           = 'draft';
    public const PENDING_PAYMENT = 'pending_payment';
    public const PARTIALLY_PAID  = 'partially_paid';
    public const PAID            = 'paid';
    public const IN_PROGRESS     = 'in_progress';
    public const DELIVERED       = 'delivered';
    public const CANCELLED       = 'cancelled';
    public const EXPIRED         = 'expired';

    private const MAP = [
        self::DRAFT           => [self::PENDING_PAYMENT, self::CANCELLED],
        self::PENDING_PAYMENT => [self::PARTIALLY_PAID, self::PAID, self::EXPIRED, self::CANCELLED],
        self::PARTIALLY_PAID  => [self::PAID, self::EXPIRED, self::CANCELLED],
        self::PAID            => [self::IN_PROGRESS, self::CANCELLED],
        self::IN_PROGRESS     => [self::DELIVERED, self::CANCELLED],
        self::DELIVERED       => [],
        self::CANCELLED       => [],
        self::EXPIRED         => [],
    ];

    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::MAP[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function next(string $from): array
    {
        return self::MAP[$from] ?? [];
    }

    public static function isFinal(string $status): bool
    {
        return (self::MAP[$status] ?? null) === [];
    }
}
