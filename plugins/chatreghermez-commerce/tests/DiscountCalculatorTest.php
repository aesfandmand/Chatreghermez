<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Orders\DiscountCalculator;
use Chatreghermez\Commerce\Orders\DiscountException;
use Chatreghermez\Commerce\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

final class DiscountCalculatorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @return list<array<string,mixed>> */
    private function lines(): array
    {
        return [
            ['product_id' => 1, 'group' => 'print', 'total_rials' => 1_000_000],
            ['product_id' => 2, 'group' => 'media', 'total_rials' => 3_000_000],
        ];
    }

    private function code(array $over = []): array
    {
        return array_merge(['active' => 1, 'type' => 'percent', 'value' => 1000, 'scope_type' => 'all'], $over);
    }

    private function err(array $code): string
    {
        try {
            DiscountCalculator::apply($code, $this->lines(), self::NOW);
        } catch (DiscountException $e) {
            return $e->errorCode;
        }
        return 'none';
    }

    public function testPercentOnAllLines(): void
    {
        $this->assertSame(400_000, DiscountCalculator::apply($this->code(), $this->lines(), self::NOW)->rials);
    }

    public function testScopedToOneProduct(): void
    {
        $c = $this->code(['scope_type' => 'product', 'scope_ids' => [2]]);
        $this->assertSame(300_000, DiscountCalculator::apply($c, $this->lines(), self::NOW)->rials);
    }

    public function testScopedToGroup(): void
    {
        $c = $this->code(['scope_type' => 'group', 'scope_ids' => ['print']]);
        $this->assertSame(100_000, DiscountCalculator::apply($c, $this->lines(), self::NOW)->rials);
    }

    public function testFixedIsCappedAtEligibleTotal(): void
    {
        $c = $this->code(['type' => 'fixed', 'value' => 9_000_000, 'scope_type' => 'product', 'scope_ids' => [1]]);
        $this->assertSame(1_000_000, DiscountCalculator::apply($c, $this->lines(), self::NOW)->rials);
    }

    public function testRejections(): void
    {
        $this->assertSame('code_inactive', $this->err($this->code(['active' => 0])));
        $this->assertSame('code_not_started', $this->err($this->code(['starts_at' => self::NOW + 10])));
        $this->assertSame('code_expired', $this->err($this->code(['ends_at' => self::NOW - 10])));
        $this->assertSame('code_exhausted', $this->err($this->code(['max_uses' => 5, 'used_count' => 5])));
        $this->assertSame('code_used_by_customer', $this->err($this->code(['per_customer' => 1, 'customer_uses' => 1])));
        $this->assertSame('below_min_order', $this->err($this->code(['min_order_rials' => 5_000_000])));
        $this->assertSame('not_applicable', $this->err($this->code(['scope_type' => 'product', 'scope_ids' => [99]])));
    }

    public function testRateLimiterBlocksAfterMax(): void
    {
        $counts = [];
        $rl = new RateLimiter(static function (string $k, int $w) use (&$counts): int {
            return $counts[$k] = ($counts[$k] ?? 0) + 1;
        });
        $this->assertTrue($rl->allow('a', 2, 60));
        $this->assertTrue($rl->allow('a', 2, 60));
        $this->assertFalse($rl->allow('a', 2, 60));
        $this->assertTrue($rl->allow('b', 2, 60));
    }
}
