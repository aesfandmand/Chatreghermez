<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Rest;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Billing\InvoiceService;
use Chatreghermez\Commerce\Orders\OrderService;
use Chatreghermez\Commerce\Payments\PaymentService;
use Chatreghermez\Commerce\Support\RateLimiter;

/**
 *   GET  /orders/{id}/invoices          owner or staff (invoices are issued automatically when an order is created)
 *   POST /invoices/{id}/pay             owner: start online payment {amount_rials?}
 *   GET  /payments/callback?trackId=    gateway return; verified server-side, then redirects to the account page
 *   POST /invoices/{id}/payments        accountant: manual cash/card {method, amount_rials, idempotency_key, note}
 *   POST /invoices/{id}/cheques         accountant: register cheque; POST /cheques/{id}/approve  (crc_cheques_approve)
 *   POST /invoices/{id}/extend          OFFICE ONLY (crc_orders_edit): extend rental hold (max 2 × 5 days)
 */
final class BillingController
{
    public static function register(): void
    {
        $ns = RolesController::NS;
        $in = static fn (): bool => is_user_logged_in();
        register_rest_route($ns, '/orders/(?P<id>\d+)/invoices', [['methods' => 'GET', 'callback' => [self::class, 'invoices'], 'permission_callback' => $in]]);
        register_rest_route($ns, '/invoices/(?P<id>\d+)/pay', [['methods' => 'POST', 'callback' => [self::class, 'pay'], 'permission_callback' => $in]]);
        register_rest_route($ns, '/payments/callback', [['methods' => 'GET', 'callback' => [self::class, 'callback'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/invoices/(?P<id>\d+)/payments', [['methods' => 'POST', 'callback' => [self::class, 'manual'], 'permission_callback' => static fn (): bool => current_user_can(Capabilities::PAYMENTS_RECORD)]]);
        register_rest_route($ns, '/invoices/(?P<id>\d+)/cheques', [['methods' => 'POST', 'callback' => [self::class, 'cheque'], 'permission_callback' => static fn (): bool => current_user_can(Capabilities::PAYMENTS_RECORD)]]);
        register_rest_route($ns, '/cheques/(?P<id>\d+)/approve', [['methods' => 'POST', 'callback' => [self::class, 'approveCheque'], 'permission_callback' => static fn (): bool => current_user_can(Capabilities::CHEQUES_APPROVE)]]);
        register_rest_route($ns, '/invoices/(?P<id>\d+)/extend', [['methods' => 'POST', 'callback' => [self::class, 'extend'], 'permission_callback' => static fn (): bool => current_user_can(Capabilities::ORDERS_EDIT)]]); // office only
    }

    /** Auto-issue invoices when an order is created (listener on the domain event, runs after commit). */
    public static function onOrderCreated(array $payload): void
    {
        InvoiceService::issueForOrder((int) $payload['order_id'], 0);
    }

    private static function mayAccess(?array $invoice): bool
    {
        return $invoice !== null && ((int) $invoice['customer_user_id'] === get_current_user_id() || current_user_can(Capabilities::INVOICES_VIEW));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function invoices(\WP_REST_Request $r)
    {
        $o = OrderService::find((int) $r['id']);
        if ($o === null || ((int) $o['customer_user_id'] !== get_current_user_id() && !current_user_can(Capabilities::INVOICES_VIEW))) {
            return new \WP_Error('crc_not_found', 'پیدا نشد.', ['status' => 404]);
        }
        return new \WP_REST_Response(InvoiceService::forOrder((int) $r['id']));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pay(\WP_REST_Request $r)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'x';
        if (!RateLimiter::wordpress()->allow('pay:' . $ip, 10, 60)) {
            return new \WP_Error('crc_rate_limited', 'درخواست‌های زیاد.', ['status' => 429]);
        }
        $inv = InvoiceService::find((int) $r['id']);
        if ($inv === null || (int) $inv['customer_user_id'] !== get_current_user_id()) {
            return new \WP_Error('crc_not_found', 'پیدا نشد.', ['status' => 404]);
        }
        $b = (array) $r->get_json_params();
        $amount = (int) ($b['amount_rials'] ?? ((int) $inv['total_rials'] - (int) $inv['paid_rials']));
        $res = PaymentService::startOnline((int) $inv['id'], $amount, rest_url(RolesController::NS . '/payments/callback'));
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response($res);
    }

    public static function callback(\WP_REST_Request $r): \WP_REST_Response
    {
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->get_param('trackId'));
        $res = $ref === '' ? ['status' => 'failed'] : PaymentService::confirmOnline($ref);
        $status = $res instanceof \WP_Error ? 'error' : (string) $res['status'];
        $to = add_query_arg('pay', $status, apply_filters('crc_account_url', home_url('/account/')));
        $resp = new \WP_REST_Response(null, 302);
        $resp->header('Location', $to);
        return $resp;
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function manual(\WP_REST_Request $r)
    {
        $b = (array) $r->get_json_params();
        $res = PaymentService::recordManual(
            (int) $r['id'],
            sanitize_key((string) ($b['method'] ?? '')),
            (int) ($b['amount_rials'] ?? 0),
            get_current_user_id(),
            preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($b['idempotency_key'] ?? '')) ?: wp_generate_uuid4(),
            sanitize_textarea_field((string) ($b['note'] ?? ''))
        );
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response(['payment_id' => $res], 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function cheque(\WP_REST_Request $r)
    {
        $b = (array) $r->get_json_params();
        $due = strtotime((string) ($b['due_date'] ?? '') . ' 00:00:00 UTC'); // Gregorian ISO date from the UI
        $res = PaymentService::registerCheque((int) $r['id'], [
            'sayad_id' => preg_replace('/\D/', '', (string) ($b['sayad_id'] ?? '')),
            'payee_national_code' => preg_replace('/\D/', '', (string) ($b['payee_national_code'] ?? '')),
            'amount_rials' => (int) ($b['amount_rials'] ?? 0),
            'due_at' => $due ?: 0,
        ], get_current_user_id());
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response(['cheque_id' => $res], 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function approveCheque(\WP_REST_Request $r)
    {
        $res = PaymentService::approveCheque((int) $r['id'], get_current_user_id());
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response(['ok' => true]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function extend(\WP_REST_Request $r)
    {
        $res = InvoiceService::extendReservation((int) $r['id'], get_current_user_id());
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response(InvoiceService::find((int) $r['id']));
    }
}
