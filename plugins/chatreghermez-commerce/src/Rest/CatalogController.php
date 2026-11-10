<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Rest;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Audit\AuditLog;
use Chatreghermez\Commerce\Catalog\PriceCalculator;
use Chatreghermez\Commerce\Catalog\PricingException;
use Chatreghermez\Commerce\Catalog\ProductRepository;
use Chatreghermez\Commerce\Catalog\ProductSchema;
use Chatreghermez\Commerce\Catalog\ProductValidator;

/**
 * Public read + server-side quote; writes need crc_catalog_edit.
 *   GET  /products            published list (?category=)
 *   GET  /products/{slug}     one published product (+ schema.org JSON-LD)
 *   POST /products/{id}/quote server-calculated price for a selection
 *   POST /products            create     (crc_catalog_edit)
 *   PUT  /products/{id}       update     (crc_catalog_edit)
 */
final class CatalogController
{
    public static function register(): void
    {
        $ns = RolesController::NS;
        register_rest_route($ns, '/products', [
            ['methods' => 'GET', 'callback' => [self::class, 'index'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [self::class, 'create'], 'permission_callback' => [self::class, 'canEdit']],
        ]);
        register_rest_route($ns, '/products/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)', [
            ['methods' => 'GET', 'callback' => [self::class, 'show'], 'permission_callback' => '__return_true'],
        ]);
        register_rest_route($ns, '/products/(?P<id>\d+)', [
            ['methods' => 'PUT', 'callback' => [self::class, 'update'], 'permission_callback' => [self::class, 'canEdit']],
        ]);
        register_rest_route($ns, '/products/(?P<id>\d+)/quote', [
            ['methods' => 'POST', 'callback' => [self::class, 'quote'], 'permission_callback' => '__return_true'],
        ]);
    }

    public static function canEdit(): bool
    {
        return current_user_can(Capabilities::CATALOG_EDIT);
    }

    public static function index(\WP_REST_Request $r): \WP_REST_Response
    {
        $items = ProductRepository::listPublished(
            sanitize_key((string) $r->get_param('category')),
            (int) ($r->get_param('per_page') ?: 24),
            (int) ($r->get_param('offset') ?: 0)
        );
        return new \WP_REST_Response(array_map([self::class, 'present'], $items));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function show(\WP_REST_Request $r)
    {
        $p = ProductRepository::findBySlug((string) $r['slug']);
        if ($p === null) {
            return new \WP_Error('crc_not_found', 'محصول پیدا نشد.', ['status' => 404]);
        }
        $out = self::present($p);
        $out['schema'] = ProductSchema::jsonLd($p, home_url('/product/' . $p['slug'] . '/'));
        return new \WP_REST_Response($out);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function quote(\WP_REST_Request $r)
    {
        $p = ProductRepository::findById((int) $r['id']);
        if ($p === null) {
            return new \WP_Error('crc_not_found', 'محصول پیدا نشد.', ['status' => 404]);
        }
        $body = (array) $r->get_json_params();
        $selection = [
            'quantity'  => (int) ($body['quantity'] ?? 1),
            'width_cm'  => (int) ($body['width_cm'] ?? 0),
            'height_cm' => (int) ($body['height_cm'] ?? 0),
            'options'   => array_map('intval', (array) ($body['options'] ?? [])),
        ];
        try {
            $q = PriceCalculator::quote($p, $selection, ProductRepository::optionDefs((int) $p['id']));
        } catch (PricingException $e) {
            return new \WP_Error('crc_' . $e->errorCode, 'انتخاب‌های شما قابل قیمت‌گذاری نیست.', ['status' => 422, 'reason' => $e->errorCode]);
        }
        return new \WP_REST_Response($q->toArray());
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function create(\WP_REST_Request $r)
    {
        return self::persist((array) $r->get_json_params(), null);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function update(\WP_REST_Request $r)
    {
        return self::persist((array) $r->get_json_params(), (int) $r['id']);
    }

    /** @return \WP_REST_Response|\WP_Error */
    private static function persist(array $data, ?int $id)
    {
        $errors = ProductValidator::validate($data);
        if ($errors !== []) {
            return new \WP_Error('crc_invalid_product', 'اطلاعات محصول معتبر نیست.', ['status' => 422, 'errors' => $errors]);
        }
        $before = $id === null ? null : ProductRepository::findById($id, false);
        $saved  = ProductRepository::save($data, $id);
        if ($saved instanceof \WP_Error) {
            return $saved;
        }
        AuditLog::record(
            get_current_user_id(),
            $id === null ? 'catalog.product_created' : 'catalog.product_updated',
            'product',
            (string) $saved,
            $before === null ? null : ['name' => $before['name'], 'status' => $before['status'], 'price_model' => $before['price_model']],
            ['name' => $data['name'], 'status' => $data['status'], 'price_model' => $data['price_model']]
        );
        return new \WP_REST_Response(['id' => $saved], $id === null ? 201 : 200);
    }

    /**
     * Public shape of a product (never exposes internal fields).
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function present(array $p): array
    {
        return [
            'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'type' => $p['type'],
            'summary' => $p['summary'], 'category' => $p['category'], 'price_model' => $p['price_model'],
            'starting_price_rials' => ProductSchema::startingPriceRials($p),
            'tiers' => $p['tiers'],
            'options' => array_map(static fn (array $o): array => [
                'id' => (int) $o['id'], 'name' => $o['name'], 'required' => (bool) $o['required'],
                'values' => array_map(static fn (array $v): array => ['id' => (int) $v['id'], 'label' => $v['label']], $o['values']),
            ], $p['options']),
            'min_dim_cm' => $p['min_dim_cm'], 'max_dim_cm' => $p['max_dim_cm'], 'max_qty' => $p['max_qty'],
        ];
    }
}
