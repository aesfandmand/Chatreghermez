<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Catalog\PriceCalculator;
use Chatreghermez\Commerce\Catalog\PricingException;
use Chatreghermez\Commerce\Support\Money;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (PricingException $e) {
            return $e->errorCode;
        }
        return 'no_exception';
    }

    public function testFixedPriceTimesQuantity(): void
    {
        $p = ['price_model' => 'fixed', 'base_price_rials' => Money::fromToman(50_000)->rials];
        $q = PriceCalculator::quote($p, ['quantity' => 3]);
        $this->assertSame(Money::fromToman(150_000)->rials, $q->total->rials);
        $this->assertFalse($q->isQuote);
    }

    public function testTieredUsesHighestReachedTier(): void
    {
        $p = ['price_model' => 'tiered', 'tiers' => [
            ['min_qty' => 1000, 'unit_price_rials' => Money::fromToman(12_000)->rials],
            ['min_qty' => 1, 'unit_price_rials' => Money::fromToman(20_000)->rials],
            ['min_qty' => 500, 'unit_price_rials' => Money::fromToman(15_000)->rials],
        ]];
        $this->assertSame(Money::fromToman(9_000_000)->rials, PriceCalculator::quote($p, ['quantity' => 600])->total->rials);
        $this->assertSame(Money::fromToman(20_000)->rials, PriceCalculator::quote($p, ['quantity' => 1])->unit->rials);
        $this->assertSame(Money::fromToman(12_000_000)->rials, PriceCalculator::quote($p, ['quantity' => 1000])->total->rials);
    }

    public function testTieredBelowSmallestTierIsRejected(): void
    {
        $p = ['price_model' => 'tiered', 'tiers' => [['min_qty' => 100, 'unit_price_rials' => 1000]]];
        $this->assertSame('qty_below_minimum', $this->code(fn () => PriceCalculator::quote($p, ['quantity' => 5])));
    }

    public function testAreaPricingForBanner(): void
    {
        // 300 x 200 cm = 6 m2 at 450,000 Toman/m2 = 2,700,000 Toman
        $p = ['price_model' => 'area', 'area_rate_rials_per_m2' => Money::fromToman(450_000)->rials, 'min_charge_rials' => 0];
        $q = PriceCalculator::quote($p, ['quantity' => 1, 'width_cm' => 300, 'height_cm' => 200]);
        $this->assertSame(Money::fromToman(2_700_000)->rials, $q->total->rials);
    }

    public function testAreaMinimumChargeApplies(): void
    {
        $p = ['price_model' => 'area', 'area_rate_rials_per_m2' => Money::fromToman(450_000)->rials, 'min_charge_rials' => 500_000];
        $q = PriceCalculator::quote($p, ['quantity' => 2, 'width_cm' => 20, 'height_cm' => 10]);
        $this->assertSame(500_000, $q->unit->rials);
        $this->assertSame(1_000_000, $q->total->rials);
    }

    public function testAreaDimensionLimits(): void
    {
        $p = ['price_model' => 'area', 'area_rate_rials_per_m2' => 1000, 'min_dim_cm' => 10, 'max_dim_cm' => 500];
        $this->assertSame('dimension_out_of_range', $this->code(fn () => PriceCalculator::quote($p, ['width_cm' => 5, 'height_cm' => 100])));
        $this->assertSame('dimension_out_of_range', $this->code(fn () => PriceCalculator::quote($p, ['width_cm' => 600, 'height_cm' => 100])));
    }

    public function testOptionMultiplierThenDelta(): void
    {
        $p    = ['price_model' => 'fixed', 'base_price_rials' => 1_000_000];
        $defs = [7 => ['required' => true, 'values' => [
            71 => ['price_mult_bp' => 12000, 'price_delta_rials' => 50_000],
            72 => ['price_mult_bp' => 10000, 'price_delta_rials' => 0],
        ]]];
        // 1,000,000 x 1.2 = 1,200,000 + 50,000 = 1,250,000
        $q = PriceCalculator::quote($p, ['quantity' => 2, 'options' => [7 => 71]], $defs);
        $this->assertSame(1_250_000, $q->unit->rials);
        $this->assertSame(2_500_000, $q->total->rials);
    }

    public function testOptionValidation(): void
    {
        $p    = ['price_model' => 'fixed', 'base_price_rials' => 1000];
        $defs = [7 => ['required' => true, 'values' => [71 => []]]];
        $this->assertSame('option_required', $this->code(fn () => PriceCalculator::quote($p, ['quantity' => 1], $defs)));
        $this->assertSame('option_invalid', $this->code(fn () => PriceCalculator::quote($p, ['options' => [7 => 99]], $defs)));
        $this->assertSame('option_unknown', $this->code(fn () => PriceCalculator::quote($p, ['options' => [8 => 1, 7 => 71]], $defs)));
    }

    public function testQuantityLimits(): void
    {
        $p = ['price_model' => 'fixed', 'base_price_rials' => 1000, 'max_qty' => 10];
        $this->assertSame('qty_out_of_range', $this->code(fn () => PriceCalculator::quote($p, ['quantity' => 0])));
        $this->assertSame('qty_out_of_range', $this->code(fn () => PriceCalculator::quote($p, ['quantity' => 11])));
    }

    public function testQuoteOnlyProductHasNoOnlinePrice(): void
    {
        $q = PriceCalculator::quote(['price_model' => 'quote'], ['quantity' => 1]);
        $this->assertTrue($q->isQuote);
        $this->assertSame(0, $q->total->rials);
    }

    public function testUnknownModelIsRejected(): void
    {
        $this->assertSame('bad_price_model', $this->code(fn () => PriceCalculator::quote(['price_model' => 'x'], ['quantity' => 1])));
    }
}
