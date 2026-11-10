<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Catalog;

use Chatreghermez\Commerce\Support\Money;

/**
 * Server-side pricing. Pure (no WordPress): all inputs are plain arrays so it is fully unit-testable.
 *
 * Product keys: price_model (fixed|tiered|area|quote), base_price_rials, tiers[[min_qty,unit_price_rials]],
 *               area_rate_rials_per_m2, min_charge_rials, max_qty, min_dim_cm, max_dim_cm.
 * Selection keys: quantity, width_cm, height_cm, options[option_id => value_id].
 * Option defs: option_id => ['required'=>bool, 'values'=>[value_id => ['price_delta_rials'=>int,'price_mult_bp'=>int]]].
 *
 * Order of operations (fixed, deterministic): base unit price -> option multipliers (by option id, each rounded half up)
 * -> option fixed deltas -> x quantity. Client totals are never an input.
 */
final class PriceCalculator
{
    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $selection
     * @param array<int,array<string,mixed>> $optionDefs
     * @throws PricingException
     */
    public static function quote(array $product, array $selection, array $optionDefs = []): PriceQuote
    {
        $model = (string) ($product['price_model'] ?? '');
        if ($model === 'quote') {
            return new PriceQuote(true, Money::zero(), 0, Money::zero());
        }

        $qty    = (int) ($selection['quantity'] ?? 1);
        $maxQty = (int) ($product['max_qty'] ?? 100000);
        if ($qty < 1 || $qty > $maxQty) {
            throw new PricingException('qty_out_of_range');
        }

        $breakdown = [];
        $unit = match ($model) {
            'fixed'  => Money::fromRials((int) ($product['base_price_rials'] ?? 0)),
            'tiered' => self::tierUnit($product, $qty),
            'area'   => self::areaUnit($product, $selection),
            default  => throw new PricingException('bad_price_model'),
        };
        $breakdown['base_unit_rials'] = $unit->rials;

        $unit = self::applyOptions($unit, $selection, $optionDefs, $breakdown);
        $breakdown['final_unit_rials'] = $unit->rials;

        return new PriceQuote(false, $unit, $qty, $unit->mul($qty), $breakdown);
    }

    /** @param array<string,mixed> $product */
    private static function tierUnit(array $product, int $qty): Money
    {
        $tiers = (array) ($product['tiers'] ?? []);
        usort($tiers, static fn (array $a, array $b): int => (int) $a['min_qty'] <=> (int) $b['min_qty']);
        $chosen = null;
        foreach ($tiers as $tier) {
            if ((int) $tier['min_qty'] <= $qty) {
                $chosen = $tier;
            }
        }
        if ($chosen === null) {
            throw new PricingException('qty_below_minimum');
        }
        return Money::fromRials((int) $chosen['unit_price_rials']);
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $selection
     */
    private static function areaUnit(array $product, array $selection): Money
    {
        $w = (int) ($selection['width_cm'] ?? 0);
        $h = (int) ($selection['height_cm'] ?? 0);
        $min = (int) ($product['min_dim_cm'] ?? 1);
        $max = (int) ($product['max_dim_cm'] ?? 100000);
        if ($w < $min || $w > $max || $h < $min || $h > $max) {
            throw new PricingException('dimension_out_of_range');
        }
        $rate = (int) ($product['area_rate_rials_per_m2'] ?? 0);
        // 1 m2 = 10,000 cm2. Round half up to whole Rials.
        $rials = intdiv($w * $h * $rate + 5000, 10000);
        $rials = max($rials, (int) ($product['min_charge_rials'] ?? 0));
        return Money::fromRials($rials);
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<int,array<string,mixed>> $optionDefs
     * @param array<string,int> $breakdown
     */
    private static function applyOptions(Money $unit, array $selection, array $optionDefs, array &$breakdown): Money
    {
        $chosen = (array) ($selection['options'] ?? []);
        foreach (array_keys($chosen) as $optionId) {
            if (!isset($optionDefs[$optionId])) {
                throw new PricingException('option_unknown');
            }
        }
        ksort($optionDefs);

        $deltas = 0;
        foreach ($optionDefs as $optionId => $def) {
            if (!array_key_exists($optionId, $chosen)) {
                if (!empty($def['required'])) {
                    throw new PricingException('option_required');
                }
                continue;
            }
            $valueId = $chosen[$optionId];
            if (!isset($def['values'][$valueId])) {
                throw new PricingException('option_invalid');
            }
            $value = $def['values'][$valueId];
            $unit    = $unit->percentBp((int) ($value['price_mult_bp'] ?? 10000));
            $deltas += (int) ($value['price_delta_rials'] ?? 0);
        }
        $breakdown['option_deltas_rials'] = $deltas;
        return $unit->add(Money::fromRials($deltas));
    }
}
