<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Domain;

/**
 * The rental calendar follows the fiscal year starting 1 Ordibehesht: fiscal month 1 = اردیبهشت … 12 = فروردین.
 * A fiscal year is named after the Jalali year in which it starts (Ordibehesht 1405 .. Farvardin 1406 = fiscal 1405).
 */
final class FiscalCalendar
{
    public const MONTHS = ['اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند', 'فروردین'];

    public static function monthName(int $fiscalMonth): string
    {
        if ($fiscalMonth < 1 || $fiscalMonth > 12) {
            throw new \InvalidArgumentException('fiscal month 1..12');
        }
        return self::MONTHS[$fiscalMonth - 1];
    }

    /** Jalali month number (1=فروردین … 12=اسفند) -> fiscal month. */
    public static function fromJalaliMonth(int $jMonth): int
    {
        return $jMonth >= 2 ? $jMonth - 1 : 12;
    }

    /** @return array{0:int,1:int} [fiscalYear, fiscalMonth] for a Jalali date. */
    public static function fromJalali(int $jYear, int $jMonth): array
    {
        return [$jMonth >= 2 ? $jYear : $jYear - 1, self::fromJalaliMonth($jMonth)];
    }

    /** Validates a month selection: unique, 1..12. @return list<int> sorted */
    public static function normalizeMonths(array $months): array
    {
        $out = [];
        foreach ($months as $m) {
            $m = (int) $m;
            if ($m < 1 || $m > 12) {
                throw new \InvalidArgumentException('bad month');
            }
            $out[$m] = $m;
        }
        ksort($out);
        return array_values($out);
    }
}
