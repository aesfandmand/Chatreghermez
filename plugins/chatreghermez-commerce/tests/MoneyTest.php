<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testTomanToRialsAndBack(): void
    {
        $m = Money::fromToman(1_234_567);
        $this->assertSame(12_345_670, $m->rials);
        $this->assertSame(1_234_567, $m->toToman());
        $this->assertSame('1,234,567', $m->formatToman());
    }

    public function testFivePercentPrepaymentRoundsHalfUp(): void
    {
        // 5% of 1,234,567 Toman = 61,728.35 Toman = 617,283.5 Rials -> 617,284 Rials
        $this->assertSame(617_284, Money::fromToman(1_234_567)->percentBp(500)->rials);
        $this->assertSame(500_000, Money::fromToman(1_000_000)->percentBp(500)->rials);
    }

    public function testAddSubMul(): void
    {
        $a = Money::fromToman(100);
        $b = Money::fromToman(40);
        $this->assertSame(1400, $a->add($b)->rials);
        $this->assertSame(600, $a->sub($b)->rials);
        $this->assertSame(3000, $a->mul(3)->rials);
    }

    public function testNegativeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromToman(10)->sub(Money::fromToman(11));
    }
}
