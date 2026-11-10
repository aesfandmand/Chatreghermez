<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Catalog;

/** Persistence for products and their options/tiers. Always uses prepared statements. */
final class ProductRepository
{
    private const INT_FIELDS = [
        'base_price_rials', 'area_rate_rials_per_m2', 'min_charge_rials',
        'max_qty', 'min_dim_cm', 'max_dim_cm', 'image_id',
    ];

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug, bool $publishedOnly = true): ?array
    {
        global $wpdb;
        $t   = $wpdb->prefix . 'crc_products';
        $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE slug = %s" . ($publishedOnly ? " AND status = 'published'" : ''), $slug);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id, bool $publishedOnly = true): ?array
    {
        global $wpdb;
        $t   = $wpdb->prefix . 'crc_products';
        $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE id = %d" . ($publishedOnly ? " AND status = 'published'" : ''), $id);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function listPublished(string $category = '', int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $t     = $wpdb->prefix . 'crc_products';
        $limit = max(1, min(100, $limit));
        if ($category !== '') {
            $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE status = 'published' AND category = %s ORDER BY id DESC LIMIT %d OFFSET %d", $category, $limit, max(0, $offset));
        } else {
            $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE status = 'published' ORDER BY id DESC LIMIT %d OFFSET %d", $limit, max(0, $offset));
        }
        return array_map([self::class, 'hydrate'], (array) $wpdb->get_results($sql, ARRAY_A));
    }

    /**
     * Option definitions in the shape PriceCalculator expects.
     * @return array<int,array<string,mixed>>
     */
    public static function optionDefs(int $productId): array
    {
        $defs = [];
        foreach (self::options($productId) as $opt) {
            $values = [];
            foreach ($opt['values'] as $v) {
                $values[(int) $v['id']] = [
                    'price_delta_rials' => (int) $v['price_delta_rials'],
                    'price_mult_bp'     => (int) $v['price_mult_bp'],
                ];
            }
            $defs[(int) $opt['id']] = ['required' => (bool) $opt['required'], 'values' => $values];
        }
        return $defs;
    }

    /**
     * Create or update a product with its options and tiers in one transaction.
     * @param array<string,mixed> $d validated payload (ProductValidator)
     * @return int|\WP_Error product id
     */
    public static function save(array $d, ?int $id = null)
    {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = gmdate('Y-m-d H:i:s');

        $row = [
            'slug' => (string) $d['slug'], 'name' => (string) $d['name'], 'type' => (string) $d['type'],
            'price_model' => (string) $d['price_model'], 'status' => (string) $d['status'],
            'category' => (string) ($d['category'] ?? ''), 'summary' => (string) ($d['summary'] ?? ''),
            'description' => (string) ($d['description'] ?? ''),
            'seo_title' => (string) ($d['seo_title'] ?? ''), 'seo_description' => (string) ($d['seo_description'] ?? ''),
            'updated_at' => $now,
        ];
        foreach (self::INT_FIELDS as $f) {
            $row[$f] = (int) ($d[$f] ?? ($f === 'max_qty' ? 100000 : ($f === 'min_dim_cm' ? 1 : ($f === 'max_dim_cm' ? 100000 : 0))));
        }

        $wpdb->query('START TRANSACTION');
        try {
            if ($id === null) {
                $row['created_at'] = $now;
                if ($wpdb->insert("{$p}crc_products", $row) === false) {
                    throw new \RuntimeException('insert_failed');
                }
                $id = (int) $wpdb->insert_id;
            } else {
                if ($wpdb->update("{$p}crc_products", $row, ['id' => $id]) === false) {
                    throw new \RuntimeException('update_failed');
                }
                self::clearChildren($id);
            }
            foreach ((array) ($d['tiers'] ?? []) as $t) {
                $wpdb->insert("{$p}crc_price_tiers", [
                    'product_id' => $id, 'min_qty' => (int) $t['min_qty'], 'unit_price_rials' => (int) $t['unit_price_rials'],
                ]);
            }
            foreach (array_values((array) ($d['options'] ?? [])) as $i => $opt) {
                $wpdb->insert("{$p}crc_product_options", [
                    'product_id' => $id, 'name' => (string) $opt['name'], 'required' => empty($opt['required']) ? 0 : 1, 'sort' => $i,
                ]);
                $optionId = (int) $wpdb->insert_id;
                foreach (array_values((array) $opt['values']) as $j => $v) {
                    $wpdb->insert("{$p}crc_option_values", [
                        'option_id' => $optionId, 'label' => (string) $v['label'],
                        'price_delta_rials' => (int) ($v['price_delta_rials'] ?? 0),
                        'price_mult_bp' => (int) ($v['price_mult_bp'] ?? 10000), 'sort' => $j,
                    ]);
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('crc_save_failed', 'ذخیرهٔ محصول انجام نشد.', ['status' => 500]);
        }
        return $id;
    }

    private static function clearChildren(int $id): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query($wpdb->prepare("DELETE v FROM {$p}crc_option_values v INNER JOIN {$p}crc_product_options o ON o.id = v.option_id WHERE o.product_id = %d", $id));
        $wpdb->delete("{$p}crc_product_options", ['product_id' => $id]);
        $wpdb->delete("{$p}crc_price_tiers", ['product_id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    private static function options(int $productId): array
    {
        global $wpdb;
        $p    = $wpdb->prefix;
        $opts = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}crc_product_options WHERE product_id = %d ORDER BY sort, id", $productId), ARRAY_A);
        foreach ($opts as &$o) {
            $o['values'] = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}crc_option_values WHERE option_id = %d ORDER BY sort, id", (int) $o['id']), ARRAY_A);
        }
        return $opts;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach (array_merge(self::INT_FIELDS, ['id']) as $f) {
            $row[$f] = (int) ($row[$f] ?? 0);
        }
        $row['tiers'] = array_map(
            static fn (array $t): array => ['min_qty' => (int) $t['min_qty'], 'unit_price_rials' => (int) $t['unit_price_rials']],
            (array) $wpdb->get_results($wpdb->prepare("SELECT min_qty, unit_price_rials FROM {$p}crc_price_tiers WHERE product_id = %d ORDER BY min_qty", $row['id']), ARRAY_A)
        );
        $row['options'] = self::options($row['id']);
        return $row;
    }
}
