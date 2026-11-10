<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Tests;

use Chatreghermez\MediaRental\Import\SheetImporter;
use PHPUnit\Framework\TestCase;

final class SheetImporterTest extends TestCase
{
    private function header(): array
    {
        return ['ردیف', 'کد سیستم ', 'مکان', 'اردیبهشت 1405', 'خرداد 1405', 'تیر 1405', 'مرداد 1405', 'شهریور 1405', 'مهر 1405', 'آبان 1405', 'آذر 1405', 'دی 1405', 'بهمن 1405', 'اسفند 1405 ', 'فروردین 1406'];
    }

    public function testAnalyzesSyntheticSheet(): void
    {
        $rows = [
            1 => ['برنامه'],
            2 => $this->header(),
            3 => ['1', '100001', 'بیلبورد 12*6 میدان نمونه', 'برند الف', 'برند الف', '', '-', '_', '', '', '', '', '', '', ''],
            4 => ['2', '100002', 'میدان بدون نوع', 'پر سفید', 'پرسفید', '', '', '', '', '', '', '', '', '', ''],
            5 => ['3', '100001', 'تکراری', '', '', '', '', '', '', '', '', '', '', '', ''],
            6 => ['4', '12', 'کد خراب', '', '', '', '', '', '', '', '', '', '', '', ''],
            7 => ['5', '100005', 'استرابورد 5*3 جای دیگر', '', '', '', '', '', '', '', '', '', '', '', ''],
        ];
        $r = SheetImporter::analyze($rows);
        $this->assertSame(1405, $r['fiscal_year']);
        $this->assertCount(3, $r['items']);
        $first = $r['items'][0];
        $this->assertSame('بیلبورد', $first['type']);
        $this->assertSame('reserved', $first['months'][1]['status']);
        $this->assertSame('free', $first['months'][3]['status']);
        $this->assertSame('blocked', $first['months'][4]['status']);
        $codes = array_column($r['issues'], 'code');
        $this->assertContains('duplicate_code', $codes);
        $this->assertContains('bad_code', $codes);
        $this->assertContains('location_unclear', $codes);
        $this->assertContains('brand_variants', $codes);
        $this->assertContains('all_free', $codes);
    }

    public function testMissingHeaderIsReported(): void
    {
        $r = SheetImporter::analyze([1 => ['x', 'y']]);
        $this->assertSame('no_header', $r['issues'][0]['code']);
    }
}
