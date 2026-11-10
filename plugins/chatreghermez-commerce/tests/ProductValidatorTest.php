<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Catalog\ProductValidator;
use PHPUnit\Framework\TestCase;

final class ProductValidatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function valid(): array
    {
        return [
            'name' => 'کارت ویزیت', 'slug' => 'business-card', 'type' => 'options',
            'status' => 'draft', 'price_model' => 'tiered',
            'tiers' => [['min_qty' => 1, 'unit_price_rials' => 1000], ['min_qty' => 500, 'unit_price_rials' => 800]],
            'options' => [['name' => 'جنس', 'values' => [['label' => 'گلاسه', 'price_delta_rials' => 0, 'price_mult_bp' => 10000]]]],
        ];
    }

    public function testValidProductHasNoErrors(): void
    {
        $this->assertEmpty(ProductValidator::validate($this->valid()));
    }

    public function testBadSlugAndNameAreReported(): void
    {
        $d = $this->valid();
        $d['slug'] = 'Bad Slug!';
        $d['name'] = '  ';
        $e = ProductValidator::validate($d);
        $this->assertArrayHasKey('slug', $e);
        $this->assertArrayHasKey('name', $e);
    }

    public function testDuplicateTierQuantityIsRejected(): void
    {
        $d = $this->valid();
        $d['tiers'][] = ['min_qty' => 500, 'unit_price_rials' => 700];
        $this->assertArrayHasKey('tiers', ProductValidator::validate($d));
    }

    public function testAreaNeedsRate(): void
    {
        $d = ['name' => 'بنر', 'slug' => 'banner', 'type' => 'options', 'status' => 'draft', 'price_model' => 'area'];
        $this->assertArrayHasKey('area_rate_rials_per_m2', ProductValidator::validate($d));
    }

    public function testNegativeOptionDeltaIsRejected(): void
    {
        $d = $this->valid();
        $d['options'][0]['values'][0]['price_delta_rials'] = -1;
        $this->assertArrayHasKey('options.0.values.0.delta', ProductValidator::validate($d));
    }

    public function testQuoteOnlyProductNeedsNoPrice(): void
    {
        $d = ['name' => 'وب‌سایت', 'slug' => 'website', 'type' => 'inquiry', 'status' => 'published', 'price_model' => 'quote'];
        $this->assertEmpty(ProductValidator::validate($d));
    }
}
