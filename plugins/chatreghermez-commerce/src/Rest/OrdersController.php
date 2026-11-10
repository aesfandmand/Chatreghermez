<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Rest;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Catalog\PricingException;
use Chatreghermez\Commerce\Orders\CartRepository;
use Chatreghermez\Commerce\Orders\LinePricer;
use Chatreghermez\Commerce\Orders\LineTypes;
use Chatreghermez\Commerce\Orders\OrderService;
use Chatreghermez\Commerce\Orders\OrderStatus;
use Chatreghermez\Commerce\Support\RateLimiter;

/**
 * Cart (public; guests identify with header X-CRC-Cart), checkout (login required) and orders.
 *   GET    /cart                  priced view          POST /cart/lines   add line
 *   DELETE /cart/lines/{id}                            POST /cart/discount set code
 *   POST   /checkout              cart -> order (logged in)
 *   GET    /orders                own orders (staff with crc_orders_view: all, ?status=)
 *   GET    /orders/{id}           owner or staff       POST /orders       staff creates for a customer
 *   POST   /orders/{id}/status    staff, state machine enforced
 */
final class OrdersController
{
    public static function register(): void
    {
        $ns = RolesController::NS;
        register_rest_route($ns, '/cart', [['methods' => 'GET', 'callback' => [self::class, 'cart'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/cart/lines', [['methods' => 'POST', 'callback' => [self::class, 'addLine'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/cart/lines/(?P<id>\d+)', [['methods' => 'DELETE', 'callback' => [self::class, 'removeLine'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/cart/discount', [['methods' => 'POST', 'callback' => [self::class, 'setDiscount'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/checkout', [['methods' => 'POST', 'callback' => [self::class, 'checkout'], 'permission_callback' => static fn (): bool => is_user_logged_in()]]);
        register_rest_route($ns, '/orders', [
            ['methods' => 'GET', 'callback' => [self::class, 'index'], 'permission_callback' => static fn (): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [self::class, 'createFor'], 'permission_callback' => static fn (): bool => current_user_can(Capabilities::ORDERS_CREATE_FOR)],
        ]);
        register_rest_route($ns, '/orders/(?P<id>\d+)', [['methods' => 'GET', 'callback' => [self::class, 'show'], 'permission_callback' => static fn (): bool => is_user_logged_in()]]);
        register_rest_route($ns, '/orders/(?P<id>\d+)/status', [['methods' => 'POST', 'callback' => [self::class, 'setStatus'], 'permission_callback' => static fn (): bool => current_user_can(Capabilities::ORDERS_EDIT)]]);
    }

    /** @return array{0:int,1:string} [cartId, guestToken] */
    private static function cartFor(\WP_REST_Request $r): array
    {
        $uid   = get_current_user_id();
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $r->get_header('X-CRC-Cart')));
        if ($uid === 0 && strlen((string) $token) < 32) {
            $token = bin2hex(random_bytes(16));
        }
        $token = substr((string) $token, 0, 32);
        if ($uid > 0 && $token !== '') {
            CartRepository::adoptGuestCart($token, $uid);
        }
        return [CartRepository::getOrCreate(CartRepository::keyFor($uid, $token), $uid), $token];
    }

    private static function limited(string $bucket, int $max = 40): ?\WP_Error
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'x';
        if (!RateLimiter::wordpress()->allow($bucket . ':' . $ip, $max, 60)) {
            return new \WP_Error('crc_rate_limited', 'درخواست‌های زیاد؛ کمی بعد دوباره تلاش کنید.', ['status' => 429]);
        }
        return null;
    }

    public static function cart(\WP_REST_Request $r): \WP_REST_Response
    {
        [$cartId, $token] = self::cartFor($r);
        return new \WP_REST_Response(self::view($cartId, $token));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function addLine(\WP_REST_Request $r)
    {
        if ($e = self::limited('cart_add')) {
            return $e;
        }
        $b    = (array) $r->get_json_params();
        $line = [
            'line_type' => LineTypes::PRODUCT, 'product_id' => (int) ($b['product_id'] ?? 0),
            'quantity' => max(1, (int) ($b['quantity'] ?? 1)),
            'selection' => [
                'width_cm' => (int) ($b['width_cm'] ?? 0), 'height_cm' => (int) ($b['height_cm'] ?? 0),
                'options' => array_map('intval', (array) ($b['options'] ?? [])),
            ],
        ];
        try {
            LinePricer::price($line); // refuse unpriceable lines up front
        } catch (PricingException $ex) {
            return new \WP_Error('crc_' . $ex->errorCode, 'این انتخاب قابل افزودن به سبد نیست.', ['status' => 422, 'reason' => $ex->errorCode]);
        }
        [$cartId, $token] = self::cartFor($r);
        CartRepository::addLine($cartId, $line['line_type'], $line['product_id'], $line['selection'], $line['quantity']);
        return new \WP_REST_Response(self::view($cartId, $token), 201);
    }

    public static function removeLine(\WP_REST_Request $r): \WP_REST_Response
    {
        [$cartId, $token] = self::cartFor($r);
        CartRepository::removeLine($cartId, (int) $r['id']);
        return new \WP_REST_Response(self::view($cartId, $token));
    }

    public static function setDiscount(\WP_REST_Request $r): \WP_REST_Response
    {
        [$cartId, $token] = self::cartFor($r);
        $b    = (array) $r->get_json_params();
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($b['code'] ?? '')));
        CartRepository::setDiscountCode($cartId, $code);
        return new \WP_REST_Response(self::view($cartId, $token));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function checkout(\WP_REST_Request $r)
    {
        if ($e = self::limited('checkout', 10)) {
            return $e;
        }
        [$cartId] = self::cartFor($r);
        $uid   = get_current_user_id();
        $order = OrderService::create(self::cartLines($cartId), $uid, $uid, CartRepository::discountCode($cartId));
        if ($order instanceof \WP_Error) {
            return $order;
        }
        CartRepository::clear($cartId);
        return new \WP_REST_Response($order, 201);
    }

    public static function index(\WP_REST_Request $r): \WP_REST_Response
    {
        if (current_user_can(Capabilities::ORDERS_VIEW)) {
            return new \WP_REST_Response(OrderService::listAll(sanitize_key((string) $r->get_param('status')), (int) ($r->get_param('per_page') ?: 50), (int) ($r->get_param('offset') ?: 0)));
        }
        return new \WP_REST_Response(OrderService::listForCustomer(get_current_user_id()));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function show(\WP_REST_Request $r)
    {
        $o = OrderService::find((int) $r['id']);
        $own = $o !== null && (int) $o['customer_user_id'] === get_current_user_id();
        if ($o === null || (!$own && !current_user_can(Capabilities::ORDERS_VIEW))) {
            return new \WP_Error('crc_not_found', 'سفارش پیدا نشد.', ['status' => 404]); // same answer for "not yours": no enumeration
        }
        return new \WP_REST_Response($o);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function createFor(\WP_REST_Request $r)
    {
        $b = (array) $r->get_json_params();
        $customer = (int) ($b['customer_id'] ?? 0);
        if ($customer <= 0 || !get_userdata($customer)) {
            return new \WP_Error('crc_bad_customer', 'مشتری نامعتبر است.', ['status' => 422]);
        }
        $lines = [];
        foreach ((array) ($b['lines'] ?? []) as $l) {
            $lines[] = [
                'line_type' => LineTypes::PRODUCT, 'product_id' => (int) ($l['product_id'] ?? 0),
                'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
                'selection' => ['width_cm' => (int) ($l['width_cm'] ?? 0), 'height_cm' => (int) ($l['height_cm'] ?? 0), 'options' => array_map('intval', (array) ($l['options'] ?? []))],
            ];
        }
        $res = OrderService::create($lines, $customer, get_current_user_id(), strtoupper(sanitize_text_field((string) ($b['discount_code'] ?? ''))), sanitize_textarea_field((string) ($b['note'] ?? '')));
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response($res, 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function setStatus(\WP_REST_Request $r)
    {
        $b  = (array) $r->get_json_params();
        $to = sanitize_key((string) ($b['status'] ?? ''));
        if (!in_array($to, OrderStatus::all(), true)) {
            return new \WP_Error('crc_bad_status', 'وضعیت نامعتبر است.', ['status' => 422]);
        }
        if ($to === OrderStatus::CANCELLED && !current_user_can(Capabilities::ORDERS_CANCEL)) {
            return new \WP_Error('crc_forbidden', 'اجازهٔ لغو ندارید.', ['status' => 403]);
        }
        $res = OrderService::transition((int) $r['id'], $to, get_current_user_id());
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response(OrderService::find((int) $r['id']));
    }

    /** @return list<array<string,mixed>> */
    private static function cartLines(int $cartId): array
    {
        return array_map(static fn (array $l): array => [
            'line_type' => $l['line_type'], 'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'selection' => $l['selection'],
        ], CartRepository::lines($cartId));
    }

    /** @return array<string,mixed> */
    private static function view(int $cartId, string $token): array
    {
        $lines = [];
        $sub   = 0;
        foreach (CartRepository::lines($cartId) as $l) {
            try {
                $p = LinePricer::price($l);
                $lines[] = ['id' => $l['id'], 'name' => $p['name'], 'quantity' => $p['quantity'], 'unit_rials' => $p['unit_rials'], 'total_rials' => $p['total_rials'], 'ok' => true];
                $sub += $p['total_rials'];
            } catch (PricingException $e) {
                $lines[] = ['id' => $l['id'], 'ok' => false, 'reason' => $e->errorCode]; // e.g. product unpublished since adding
            }
        }
        return ['cart_token' => $token, 'lines' => $lines, 'subtotal_rials' => $sub, 'discount_code' => CartRepository::discountCode($cartId)];
    }
}
