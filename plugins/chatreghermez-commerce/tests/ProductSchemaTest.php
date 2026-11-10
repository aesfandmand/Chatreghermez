<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Catalog\ProductSchema;
use PHPUnit\Framework\TestCase;

final class ProductSchemaTest extends TestCase
{
    public function testFixedPublishedProductGetsOffer(): void
    {
        $d = ProductSchema::jsonLd(
            ['name' => 'کارت ویزیت', 'status' => 'published', 'price_model' => 'fixed', 'base_price_rials' => 500000],
            'https://example.test/p/card'
        );
        $this->assertSame('Offer', $d['offers']['@type']);
        $this->assertSame('IRR', $d['offers']['priceCurrency']);
        $this->assertSame('500000', $d['offers']['price']);
    }

    public function testTieredUsesLowestPriceAggregate(): void
    {
        $d = ProductSchema::jsonLd(
            ['name' => 'x', 'status' => 'published', 'price_model' => 'tiered', 'tiers' => [
                ['min_qty' => 1, 'unit_price_rials' => 900], ['min_qty' => 100, 'unit_price_rials' => 700],
            ]],
            'https://example.test/p/x'
        );
        $this->assertSame('AggregateOffer', $d['offers']['@type']);
        $this->assertSame('700', $d['offers']['lowPrice']);
    }

    public function testQuoteOrDraftProductHasNoPriceInSchema(): void
    {
        $quote = ProductSchema::jsonLd(['name' => 'site', 'status' => 'published', 'price_model' => 'quote'], 'https://e.test/s');
        $this->assertArrayNotHasKey('offers', $quote);
        $draft = ProductSchema::jsonLd(['name' => 'd', 'status' => 'draft', 'price_model' => 'fixed', 'base_price_rials' => 1], 'https://e.test/d');
        $this->assertArrayNotHasKey('offers', $draft);
    }
}
