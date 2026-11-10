<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Admin\DiscountsPage;
use Chatreghermez\Commerce\Admin\ProductFormParser;
use Chatreghermez\Commerce\Catalog\ProductValidator;
use PHPUnit\Framework\TestCase;

final class ProductFormParserTest extends TestCase
{
    public function testParsesTomanToRialsTiersAndOptions(): void
    {
        $d = ProductFormParser::parse([
            'name' => ' کارت ویزیت ', 'slug' => 'Business-Card', 'type' => 'options', 'status' => 'published', 'price_model' => 'tiered',
            'tiers' => "۱۰۰ | ۸٬۵۰۰\n500 | 7000\nخراب",
            'options' => "جنس کاغذ *\n- گلاسه | 0 | 100\n- کرافت | 5000 | 110\nسلفون\n- مات | ۲۰۰۰",
        ]);
        $this->assertSame('کارت ویزیت', $d['name']);
        $this->assertSame('business-card', $d['slug']);
        $this->assertSame([['min_qty' => 100, 'unit_price_rials' => 85000], ['min_qty' => 500, 'unit_price_rials' => 70000]], $d['tiers']);
        $this->assertCount(2, $d['options']);
        $this->assertTrue($d['options'][0]['required']);
        $this->assertFalse($d['options'][1]['required']);
        $this->assertSame(50000, $d['options'][0]['values'][1]['price_delta_rials']);
        $this->assertSame(11000, $d['options'][0]['values'][1]['price_mult_bp']);
        $this->assertSame(10000, $d['options'][1]['values'][0]['price_mult_bp']);
        $this->assertSame([], ProductValidator::validate($d));
    }

    public function testFormatRoundTrips(): void
    {
        $in = ['name' => 'x', 'slug' => 'x', 'price_model' => 'tiered', 'base_price' => 1000, 'tiers' => "100 | 8500", 'options' => "کاغذ *\n- گلاسه | 0 | 100\n- کرافت | 5000 | 110"];
        $d = ProductFormParser::parse($in);
        $f = ProductFormParser::format($d);
        $this->assertSame('100 | 8500', $f['tiers']);
        $this->assertSame("کاغذ *\n- گلاسه | 0 | 100\n- کرافت | 5000 | 110", $f['options']);
        $this->assertSame('1000', $f['base_price']);
    }

    public function testBadInputIsCaughtByValidator(): void
    {
        $d = ProductFormParser::parse(['name' => '', 'slug' => 'نامعتبر', 'price_model' => 'tiered', 'tiers' => '']);
        $e = ProductValidator::validate($d);
        $this->assertArrayHasKey('name', $e);
        $this->assertArrayHasKey('slug', $e);
        $this->assertArrayHasKey('tiers', $e);
    }

    public function testDiscountFormParsing(): void
    {
        $d = DiscountsPage::parse(['code' => ' nov-10 ', 'type' => 'percent', 'value' => '10', 'min_order' => '500000', 'starts' => '2026-11-10']);
        $this->assertSame('NOV-10', $d['code']);
        $this->assertSame(1000, $d['value']);                 // basis points
        $this->assertSame(5000000, $d['min_order_rials']);    // Toman -> Rials
        $d2 = DiscountsPage::parse(['code' => 'A', 'type' => 'fixed', 'value' => '50000']);
        $this->assertSame(500000, $d2['value']);              // 50,000 Toman -> 500,000 Rials
        $this->assertNull(DiscountsPage::parse(['code' => 'A', 'type' => 'percent', 'value' => '150']));
        $this->assertNull(DiscountsPage::parse(['code' => '', 'type' => 'fixed', 'value' => '5']));
    }
}
