<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Catalog;

/** Validates a product payload before saving. Returns field => message (empty array = valid). */
final class ProductValidator
{
    public const TYPES  = ['simple', 'options', 'design', 'inquiry'];
    public const MODELS = ['fixed', 'tiered', 'area', 'quote'];
    public const STATUS = ['draft', 'published'];

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    public static function validate(array $d): array
    {
        $e = [];

        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            $e['name'] = 'نام محصول لازم است (حداکثر ۲۰۰ نویسه).';
        }
        $slug = (string) ($d['slug'] ?? '');
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 190) {
            $e['slug'] = 'نشانی کوتاه فقط حروف انگلیسی کوچک، عدد و خط تیره.';
        }
        if (!in_array($d['type'] ?? '', self::TYPES, true)) {
            $e['type'] = 'نوع محصول نامعتبر است.';
        }
        if (!in_array($d['status'] ?? '', self::STATUS, true)) {
            $e['status'] = 'وضعیت نامعتبر است.';
        }

        $model = $d['price_model'] ?? '';
        if (!in_array($model, self::MODELS, true)) {
            $e['price_model'] = 'مدل قیمت نامعتبر است.';
        } else {
            $e += self::validateModel($model, $d);
        }

        foreach ((array) ($d['options'] ?? []) as $i => $opt) {
            $e += self::validateOption($i, (array) $opt);
        }
        return $e;
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private static function validateModel(string $model, array $d): array
    {
        $e = [];
        if ($model === 'fixed' && (int) ($d['base_price_rials'] ?? -1) < 0) {
            $e['base_price_rials'] = 'قیمت پایه لازم است.';
        }
        if ($model === 'area') {
            if ((int) ($d['area_rate_rials_per_m2'] ?? 0) <= 0) {
                $e['area_rate_rials_per_m2'] = 'نرخ هر متر مربع لازم است.';
            }
            $min = (int) ($d['min_dim_cm'] ?? 1);
            $max = (int) ($d['max_dim_cm'] ?? 100000);
            if ($min < 1 || $max < $min) {
                $e['dimensions'] = 'بازهٔ ابعاد نامعتبر است.';
            }
        }
        if ($model === 'tiered') {
            $tiers = (array) ($d['tiers'] ?? []);
            if ($tiers === []) {
                $e['tiers'] = 'حداقل یک پلهٔ قیمت لازم است.';
            }
            $seen = [];
            foreach ($tiers as $t) {
                $q = (int) ($t['min_qty'] ?? 0);
                if ($q < 1 || isset($seen[$q]) || (int) ($t['unit_price_rials'] ?? -1) < 0) {
                    $e['tiers'] = 'پله‌ها باید تعداد یکتای ≥ ۱ و قیمت معتبر داشته باشند.';
                    break;
                }
                $seen[$q] = true;
            }
        }
        return $e;
    }

    /**
     * @param int|string $i
     * @param array<string,mixed> $opt
     * @return array<string,string>
     */
    private static function validateOption($i, array $opt): array
    {
        $e = [];
        if (trim((string) ($opt['name'] ?? '')) === '') {
            $e["options.$i.name"] = 'نام گزینه لازم است.';
        }
        $values = (array) ($opt['values'] ?? []);
        if ($values === []) {
            $e["options.$i.values"] = 'هر گزینه حداقل یک مقدار دارد.';
        }
        foreach ($values as $j => $v) {
            if (trim((string) ($v['label'] ?? '')) === '') {
                $e["options.$i.values.$j.label"] = 'برچسب مقدار لازم است.';
            }
            if ((int) ($v['price_delta_rials'] ?? 0) < 0) {
                $e["options.$i.values.$j.delta"] = 'افزوده‌ی قیمت نمی‌تواند منفی باشد (تخفیف با کد تخفیف انجام می‌شود).';
            }
            $m = (int) ($v['price_mult_bp'] ?? 10000);
            if ($m < 1 || $m > 100000) {
                $e["options.$i.values.$j.mult"] = 'ضریب قیمت نامعتبر است.';
            }
        }
        return $e;
    }
}
