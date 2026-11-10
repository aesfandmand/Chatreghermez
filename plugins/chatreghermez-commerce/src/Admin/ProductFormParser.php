<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Admin;

/**
 * Turns the admin form (prices typed in Toman, tiers/options as simple text blocks) into the repository payload (Rials).
 * Pure: no WordPress calls, fully unit-tested.
 *
 * tiers text, one per line:      تعداد | قیمت واحد (تومان)
 * options text:                  گزینه (برای اجباری بودن * آخر خط)
 *                                - برچسب مقدار | افزوده (تومان) | ضریب قیمت (درصد، پیش‌فرض ۱۰۰)
 */
final class ProductFormParser
{
    /** @param array<string,mixed> $in */
    public static function parse(array $in): array
    {
        $toRials = static fn ($v): int => (int) round(((float) self::digits((string) $v)) * 10);
        return [
            'name' => trim((string) ($in['name'] ?? '')),
            'slug' => strtolower(trim((string) ($in['slug'] ?? ''))),
            'type' => (string) ($in['type'] ?? 'simple'),
            'status' => (string) ($in['status'] ?? 'draft'),
            'price_model' => (string) ($in['price_model'] ?? 'fixed'),
            'category' => trim((string) ($in['category'] ?? '')),
            'summary' => trim((string) ($in['summary'] ?? '')),
            'description' => (string) ($in['description'] ?? ''),
            'seo_title' => trim((string) ($in['seo_title'] ?? '')),
            'seo_description' => trim((string) ($in['seo_description'] ?? '')),
            'base_price_rials' => $toRials($in['base_price'] ?? 0),
            'area_rate_rials_per_m2' => $toRials($in['area_rate'] ?? 0),
            'min_charge_rials' => $toRials($in['min_charge'] ?? 0),
            'max_qty' => (int) self::digits((string) ($in['max_qty'] ?? '100000')) ?: 100000,
            'min_dim_cm' => (int) self::digits((string) ($in['min_dim_cm'] ?? '1')) ?: 1,
            'max_dim_cm' => (int) self::digits((string) ($in['max_dim_cm'] ?? '100000')) ?: 100000,
            'tiers' => self::tiers((string) ($in['tiers'] ?? ''), $toRials),
            'options' => self::options((string) ($in['options'] ?? ''), $toRials),
        ];
    }

    /** Reverse of parse(): product (Rials) -> the form's text/Toman fields. @return array<string,string> */
    public static function format(array $p): array
    {
        $t = static fn ($r): string => (string) intdiv((int) $r, 10);
        $tiers = [];
        foreach ((array) ($p['tiers'] ?? []) as $x) {
            $tiers[] = (int) $x['min_qty'] . ' | ' . $t($x['unit_price_rials']);
        }
        $opts = [];
        foreach ((array) ($p['options'] ?? []) as $o) {
            $opts[] = $o['name'] . (empty($o['required']) ? '' : ' *');
            foreach ((array) $o['values'] as $v) {
                $opts[] = '- ' . $v['label'] . ' | ' . $t($v['price_delta_rials']) . ' | ' . rtrim(rtrim(number_format(((int) $v['price_mult_bp']) / 100, 2, '.', ''), '0'), '.');
            }
        }
        return [
            'base_price' => $t($p['base_price_rials'] ?? 0), 'area_rate' => $t($p['area_rate_rials_per_m2'] ?? 0),
            'min_charge' => $t($p['min_charge_rials'] ?? 0), 'tiers' => implode("\n", $tiers), 'options' => implode("\n", $opts),
        ];
    }

    public static function digits(string $s): string
    {
        $s = strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>'',',' => '', '،' => '']);
        return preg_replace('/[^0-9.\-]/', '', $s) ?? '';
    }

    /** @return list<array{min_qty:int,unit_price_rials:int}> */
    private static function tiers(string $text, callable $toRials): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }
            $out[] = ['min_qty' => (int) self::digits($parts[0]), 'unit_price_rials' => $toRials($parts[1])];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function options(string $text, callable $toRials): array
    {
        $out = [];
        $cur = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '-' || $line[0] === '–') {
                if ($cur === null) {
                    continue;
                }
                $p = array_map('trim', explode('|', ltrim($line, "-– \t")));
                $mult = isset($p[2]) && $p[2] !== '' ? (int) round((float) self::digits($p[2]) * 100) : 10000;
                $cur['values'][] = ['label' => $p[0], 'price_delta_rials' => $toRials($p[1] ?? 0), 'price_mult_bp' => $mult];
                continue;
            }
            if ($cur !== null) {
                $out[] = $cur;
            }
            $required = substr($line, -1) === '*';
            $cur = ['name' => trim($required ? substr($line, 0, -1) : $line), 'required' => $required, 'values' => []];
        }
        if ($cur !== null) {
            $out[] = $cur;
        }
        return $out;
    }
}
