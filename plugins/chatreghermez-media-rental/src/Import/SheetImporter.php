<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Import;

use Chatreghermez\MediaRental\Domain\FiscalCalendar;

/**
 * Analyzes a calendar sheet (rows keyed by sheet row number) WITHOUT touching the database. Output feeds the admin preview.
 * Expected layout: a header row with «کد سیستم», «مکان» and twelve month columns named «<ماه> <سال>» in fiscal order.
 */
final class SheetImporter
{
    /**
     * @param array<int,list<string>> $rows
     * @return array{fiscal_year:int,items:list<array<string,mixed>>,issues:list<array{row:int,code:string,message:string}>,brands:array<string,list<string>>}
     */
    public static function analyze(array $rows): array
    {
        $issues = [];
        [$hRow, $cols] = self::findHeader($rows);
        if ($hRow === 0) {
            return ['fiscal_year' => 0, 'items' => [], 'issues' => [['row' => 0, 'code' => 'no_header', 'message' => 'ردیف سرستون (کد سیستم، مکان، ماه‌ها) پیدا نشد.']], 'brands' => []];
        }
        $year = $cols['year'];
        $items = [];
        $seen = [];
        $brands = [];
        $sizeMissing = 0;
        foreach ($rows as $n => $r) {
            if ($n <= $hRow) {
                continue;
            }
            $codeRaw = trim((string) ($r[$cols['code']] ?? ''));
            $locRaw = MediaSheetRules::clean((string) ($r[$cols['location']] ?? ''));
            if ($codeRaw === '' && $locRaw === '') {
                continue;
            }
            $code = MediaSheetRules::systemCode($codeRaw);
            if ($code === null) {
                $issues[] = ['row' => $n, 'code' => 'bad_code', 'message' => "کد سیستم «{$codeRaw}» شش‌رقمی نیست."];
                continue;
            }
            if (isset($seen[$code])) {
                $issues[] = ['row' => $n, 'code' => 'duplicate_code', 'message' => "کد {$code} تکراری است (ردیف {$seen[$code]})."];
                continue;
            }
            $seen[$code] = $n;
            $loc = MediaSheetRules::parseLocation($locRaw);
            if ($loc['type'] === '') {
                $issues[] = ['row' => $n, 'code' => 'location_unclear', 'message' => "نوع رسانه از متن مکان کد {$code} تشخیص داده نشد؛ بعد از ورود تکمیل شود."];
            } elseif ($loc['width_m'] === null) {
                $sizeMissing++;
            }
            $months = [];
            $empty = 0;
            foreach ($cols['months'] as $fiscalMonth => $idx) {
                $cell = MediaSheetRules::cell($r[$idx] ?? '');
                if ($cell['status'] === 'reserved') {
                    $brands[MediaSheetRules::dedupeKey($cell['brand'])][$cell['brand']] = true;
                }
                if ($cell['status'] === 'free') {
                    $empty++;
                }
                $months[$fiscalMonth] = $cell;
            }
            if ($empty === 12) {
                $issues[] = ['row' => $n, 'code' => 'all_free', 'message' => "هر ۱۲ ماه کد {$code} خالی است؛ ردیف ناقص نباشد؟"];
            }
            $items[] = ['row' => $n, 'code' => $code, 'location' => $locRaw, 'type' => $loc['type'], 'orientation' => $loc['orientation'], 'lit' => $loc['lit'], 'width_m' => $loc['width_m'], 'height_m' => $loc['height_m'], 'months' => $months];
        }
        if ($sizeMissing > 0) {
            $issues[] = ['row' => 0, 'code' => 'size_missing', 'message' => "اندازه در متن مکان {$sizeMissing} رسانه نیامده؛ بعد از ورود در پنل تکمیل شود."];
        }
        $variants = [];
        foreach ($brands as $key => $names) {
            if (count($names) > 1) {
                $variants[$key] = array_keys($names);
                $issues[] = ['row' => 0, 'code' => 'brand_variants', 'message' => 'نام برند به چند شکل نوشته شده: ' . implode(' / ', array_keys($names))];
            }
        }
        return ['fiscal_year' => $year, 'items' => $items, 'issues' => $issues, 'brands' => $variants];
    }

    /** @return array{0:int,1:array{code:int,location:int,year:int,months:array<int,int>}} */
    private static function findHeader(array $rows): array
    {
        foreach ($rows as $n => $r) {
            $code = $loc = null;
            $months = [];
            $year = 0;
            foreach ($r as $i => $cell) {
                $c = MediaSheetRules::clean((string) $cell);
                if ($c === 'کد سیستم') {
                    $code = $i;
                } elseif ($c === 'مکان') {
                    $loc = $i;
                } else {
                    foreach (FiscalCalendar::MONTHS as $k => $name) {
                        if (preg_match('/^' . preg_quote($name, '/') . '\s*(\d{4})$/u', strtr($c, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $m)) {
                            $months[$k + 1] = $i;
                            if ($k === 0) {
                                $year = (int) $m[1]; // fiscal year = the year of Ordibehesht
                            }
                        }
                    }
                }
            }
            if ($code !== null && $loc !== null && count($months) === 12 && $year > 0) {
                return [(int) $n, ['code' => $code, 'location' => $loc, 'year' => $year, 'months' => $months]];
            }
        }
        return [0, ['code' => 0, 'location' => 0, 'year' => 0, 'months' => []]];
    }
}
