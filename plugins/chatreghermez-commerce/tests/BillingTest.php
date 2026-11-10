<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Billing\ChequePolicy;
use Chatreghermez\Commerce\Billing\InvoiceSplitter;
use Chatreghermez\Commerce\Billing\PrepaymentPolicy;
use Chatreghermez\Commerce\Billing\ReservationPolicy;
use Chatreghermez\Commerce\Orders\LineTypes;
use Chatreghermez\Commerce\Payments\FakeGateway;
use PHPUnit\Framework\TestCase;

final class BillingTest extends TestCase
{
    public function testPrepaymentRounding(): void
    {
        // Owner examples: "5% result" values, expressed through the invoice totals that produce them (total = raw * 20).
        $this->assertSame(600000, PrepaymentPolicy::compute(12000000));        // 600,000 stays
        $this->assertSame(12000000, PrepaymentPolicy::compute(240000000));     // 12,000,000 stays
        $this->assertSame(1100000, PrepaymentPolicy::compute(22000000));       // 1,100,000 stays
        $this->assertSame(1150000, PrepaymentPolicy::compute(22400000));       // 1,120,000 -> 1,150,000
        $this->assertSame(850000, PrepaymentPolicy::compute(17000000));        // 850,000 stays
        $this->assertSame(900000, PrepaymentPolicy::compute(17160000));        // 858,000 -> 900,000
        $this->assertSame(59000000, PrepaymentPolicy::compute(1177718760));    // 58,885,938 -> 59,000,000
        $this->assertSame(32500000, PrepaymentPolicy::compute(648370280));     // 32,418,514 -> 32,500,000
        $this->assertSame(500000, PrepaymentPolicy::compute(3000000));         // 150,000 -> minimum
        $this->assertSame(300000, PrepaymentPolicy::compute(300000));          // never above the invoice
        $this->assertSame(0, PrepaymentPolicy::compute(0));
    }

    public function testReservationExtensionLimit(): void
    {
        $now = 1000000;
        $exp = ReservationPolicy::initialExpiry($now);
        $this->assertSame($now + 5 * 86400, $exp);
        $this->assertTrue(ReservationPolicy::canExtend(0, $exp, $now));
        $this->assertTrue(ReservationPolicy::canExtend(1, $exp, $now));
        $this->assertFalse(ReservationPolicy::canExtend(2, $exp, $now));
        $this->assertFalse(ReservationPolicy::canExtend(0, $now - 1, $now));
        $this->assertSame($exp + 5 * 86400, ReservationPolicy::extendedExpiry($exp));
    }

    public function testChequeRules(): void
    {
        $this->assertTrue(ChequePolicy::validNationalCode('0499370899'));
        $this->assertFalse(ChequePolicy::validNationalCode('0499370890'));
        $this->assertFalse(ChequePolicy::validNationalCode('1111111111'));
        $this->assertTrue(ChequePolicy::validSayad('1234567890123456'));
        $this->assertFalse(ChequePolicy::validSayad('12345'));
        $now = 1000000;
        $ok = ['sayad_id' => '1234567890123456', 'payee_national_code' => '0499370899', 'amount_rials' => 5, 'due_at' => $now + 40 * 86400];
        $this->assertSame([], ChequePolicy::validate($ok, 'services', $now));
        $this->assertSame(['due_too_far'], ChequePolicy::validate(['due_at' => $now + 46 * 86400] + $ok, 'services', $now));
        $this->assertSame([], ChequePolicy::validate(['due_at' => $now + 90 * 86400] + $ok, 'rental', $now));
    }

    public function testInvoiceSplit(): void
    {
        LineTypes::register('rental_test', 'rental');
        $g = InvoiceSplitter::split([
            ['line_type' => 'product_purchase', 'total_rials' => 100],
            ['line_type' => 'service_addon', 'total_rials' => 50],
            ['line_type' => 'rental_test', 'total_rials' => 1000, 'meta' => ['city' => 'mobarakeh']],
            ['line_type' => 'rental_test', 'total_rials' => 2000, 'meta' => ['city' => 'mobarakeh']],
            ['line_type' => 'rental_test', 'total_rials' => 500, 'meta' => ['city' => 'isfahan']],
        ]);
        $this->assertCount(4, $g);
        $totals = array_column($g, 'total_rials');
        sort($totals);
        $this->assertSame([50, 100, 500, 3000], $totals);
    }

    public function testFakeGatewayRequiresServerVerification(): void
    {
        $gw = new FakeGateway();
        $i = $gw->initiate(1000, 'CRC-1', 'https://x/cb');
        $this->assertFalse($gw->verify($i['ref'])['paid']);
        $gw->markPaid($i['ref']);
        $v = $gw->verify($i['ref']);
        $this->assertTrue($v['paid']);
        $this->assertSame(1000, $v['amount_rials']);
    }
}
