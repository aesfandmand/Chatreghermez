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

    /** Gregorian date -> Jalali [year, month, day] (standard 33-year-cycle algorithm). */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $cum  = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2  = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $cum[$gm - 1];
        $jy   = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy  += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy  += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + $days % 31];
        }
        return [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    /** [fiscalYear, fiscalMonth] "now" in Iran time (UTC+3:30, no DST). */
    public static function current(int $timestamp): array
    {
        $t = $timestamp + 12600;
        [$jy, $jm] = self::gregorianToJalali((int) gmdate('Y', $t), (int) gmdate('n', $t), (int) gmdate('j', $t));
        return self::fromJalali($jy, $jm);
    }

    /** A month earlier than the current fiscal month is "past": never shown to customers and never rentable. */
    public static function isPast(int $fiscalYear, int $fiscalMonth, int $timestamp): bool
    {
        [$cy, $cm] = self::current($timestamp);
        return [$fiscalYear, $fiscalMonth] < [$cy, $cm];
    }
}
