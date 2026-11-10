<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

use Chatreghermez\Commerce\Support\Money;

/**
 * Pure discount rules.
 * Code keys: type (percent|fixed), value (basis points | rials), scope_type (all|product|group), scope_ids,
 *   min_order_rials, max_uses, used_count, per_customer, customer_uses, starts_at, ends_at (unix), active.
 * Lines: [['product_id'=>int,'group'=>string,'total_rials'=>int], ...]
 * A discount applies only to eligible lines and never exceeds their total. One code per order (no stacking).
 */
final class DiscountCalculator
{
    /**
     * @param array<string,mixed> $code
     * @param list<array<string,mixed>> $lines
     * @throws DiscountException
     */
    public static function apply(array $code, array $lines, int $now): Money
    {
        if (empty($code['active'])) {
            throw new DiscountException('code_inactive');
        }
        if (!empty($code['starts_at']) && $now < (int) $code['starts_at']) {
            throw new DiscountException('code_not_started');
        }
        if (!empty($code['ends_at']) && $now > (int) $code['ends_at']) {
            throw new DiscountException('code_expired');
        }
        $maxUses = (int) ($code['max_uses'] ?? 0);
        if ($maxUses > 0 && (int) ($code['used_count'] ?? 0) >= $maxUses) {
            throw new DiscountException('code_exhausted');
        }
        $perCustomer = (int) ($code['per_customer'] ?? 0);
        if ($perCustomer > 0 && (int) ($code['customer_uses'] ?? 0) >= $perCustomer) {
            throw new DiscountException('code_used_by_customer');
        }

        $orderTotal = 0;
        foreach ($lines as $l) {
            $orderTotal += (int) $l['total_rials'];
        }
        if ($orderTotal < (int) ($code['min_order_rials'] ?? 0)) {
            throw new DiscountException('below_min_order');
        }

        $eligible = 0;
        $scope    = (string) ($code['scope_type'] ?? 'all');
        $ids      = (array) ($code['scope_ids'] ?? []);
        foreach ($lines as $l) {
            $ok = match ($scope) {
                'all'     => true,
                'product' => in_array((int) $l['product_id'], array_map('intval', $ids), true),
                'group'   => in_array((string) ($l['group'] ?? ''), array_map('strval', $ids), true),
                default   => false,
            };
            if ($ok) {
                $eligible += (int) $l['total_rials'];
            }
        }
        if ($eligible <= 0) {
            throw new DiscountException('not_applicable');
        }

        $value  = (int) ($code['value'] ?? 0);
        $amount = ($code['type'] ?? '') === 'percent'
            ? Money::fromRials($eligible)->percentBp($value)->rials
            : $value;
        return Money::fromRials(min($amount, $eligible));
    }
}
