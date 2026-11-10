<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

/**
 * Order-line type registry. Extensions (e.g. chatreghermez-media-rental) register their own type
 * and which invoice kind it belongs to; cart, order and payment stay untouched.
 */
final class LineTypes
{
    public const PRODUCT = 'product_purchase';
    public const SERVICE = 'service_addon';
    public const RENTAL  = 'media_rental';

    /** @var array<string,string> type => invoice kind (product|services|rental) */
    private static array $kinds = [
        self::PRODUCT => 'product',
        self::SERVICE => 'services',
    ];

    public static function register(string $type, string $invoiceKind): void
    {
        if (!in_array($invoiceKind, ['product', 'services', 'rental'], true)) {
            throw new \InvalidArgumentException('Unknown invoice kind.');
        }
        self::$kinds[$type] = $invoiceKind;
    }

    public static function isKnown(string $type): bool
    {
        return isset(self::$kinds[$type]);
    }

    public static function invoiceKind(string $type): string
    {
        if (!isset(self::$kinds[$type])) {
            throw new \InvalidArgumentException("Unknown line type {$type}");
        }
        return self::$kinds[$type];
    }
}
