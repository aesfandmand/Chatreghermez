<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

use Chatreghermez\Commerce\Audit\AuditLog;
use Chatreghermez\Commerce\Catalog\PricingException;
use Chatreghermez\Commerce\Support\Events;
use Chatreghermez\Commerce\Support\Money;
use Chatreghermez\Commerce\Support\Sequence;

/** Creates orders from lines (always re-priced on the server) and moves them through the state machine. */
final class OrderService
{
    /**
     * @param list<array<string,mixed>> $lines line_type, product_id, selection, quantity
     * @return array<string,mixed>|\WP_Error order summary
     */
    public static function create(array $lines, int $customerId, int $actorId, string $discountCode = '', string $note = '')
    {
        global $wpdb;
        if ($lines === []) {
            return new \WP_Error('crc_empty_cart', 'سبد خالی است.', ['status' => 422]);
        }

        $priced = [];
        $sub    = 0;
        try {
            foreach ($lines as $line) {
                $p = LinePricer::price($line);
                $p['line_type']  = (string) ($line['line_type'] ?? LineTypes::PRODUCT);
                $p['product_id'] = (int) ($line['product_id'] ?? 0);
                $priced[] = $p;
                $sub += $p['total_rials'];
            }
        } catch (PricingException $e) {
            return new \WP_Error('crc_' . $e->errorCode, 'یکی از اقلام قابل قیمت‌گذاری نیست.', ['status' => 422, 'reason' => $e->errorCode]);
        }

        $discount = Money::zero();
        $codeRow  = null;
        if ($discountCode !== '') {
            $codeRow = DiscountRepository::findForCustomer($discountCode, $customerId);
            if ($codeRow === null) {
                return new \WP_Error('crc_code_unknown', 'کد تخفیف معتبر نیست.', ['status' => 422]);
            }
            try {
                $discount = DiscountCalculator::apply(
                    $codeRow,
                    array_map(static fn (array $l): array => ['product_id' => $l['product_id'], 'group' => $l['group'], 'total_rials' => $l['total_rials']], $priced),
                    time()
                );
            } catch (DiscountException $e) {
                return new \WP_Error('crc_' . $e->errorCode, 'کد تخفیف قابل استفاده نیست.', ['status' => 422, 'reason' => $e->errorCode]);
            }
        }

        $total = Money::fromRials($sub)->sub($discount);
        $now   = gmdate('Y-m-d H:i:s');
        $p     = $wpdb->prefix;

        $wpdb->query('START TRANSACTION');
        try {
            $orderNo = 'CRC-' . str_pad((string) Sequence::next('order'), 6, '0', STR_PAD_LEFT);
            $wpdb->insert("{$p}crc_orders", [
                'order_no' => $orderNo, 'customer_user_id' => $customerId, 'created_by' => $actorId,
                'status' => OrderStatus::PENDING_PAYMENT, 'subtotal_rials' => $sub,
                'discount_rials' => $discount->rials, 'total_rials' => $total->rials,
                'discount_code' => $codeRow ? (string) $codeRow['code'] : '', 'note' => $note,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $orderId = (int) $wpdb->insert_id;
            if ($orderId === 0) {
                throw new \RuntimeException('order_insert');
            }
            foreach ($priced as $l) {
                $wpdb->insert("{$p}crc_order_lines", [
                    'order_id' => $orderId, 'line_type' => $l['line_type'], 'product_id' => $l['product_id'],
                    'name' => mb_substr($l['name'], 0, 200), 'group_key' => mb_substr((string) $l['group'], 0, 64),
                    'selection_json' => wp_json_encode($l['selection']), 'meta_json' => wp_json_encode($l['meta']),
                    'quantity' => $l['quantity'], 'unit_rials' => $l['unit_rials'], 'total_rials' => $l['total_rials'],
                ]);
            }
            if ($codeRow && !DiscountRepository::consume((int) $codeRow['id'], $orderId, $customerId, $discount->rials)) {
                throw new \RuntimeException('code_exhausted');
            }
            $wpdb->insert("{$p}crc_order_events", [
                'order_id' => $orderId, 'from_status' => OrderStatus::DRAFT, 'to_status' => OrderStatus::PENDING_PAYMENT,
                'actor_id' => $actorId, 'created_at' => $now,
            ]);
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $reason = $e->getMessage() === 'code_exhausted' ? 'code_exhausted' : 'order_failed';
            return new \WP_Error('crc_' . $reason, 'ثبت سفارش انجام نشد.', ['status' => $reason === 'code_exhausted' ? 422 : 500]);
        }

        AuditLog::record($actorId, 'orders.created', 'order', (string) $orderId, null, ['order_no' => $orderNo, 'total_rials' => $total->rials]);
        Events::dispatch('order.created', ['order_id' => $orderId, 'order_no' => $orderNo, 'customer_id' => $customerId, 'total_rials' => $total->rials]);
        return ['id' => $orderId, 'order_no' => $orderNo, 'status' => OrderStatus::PENDING_PAYMENT,
            'subtotal_rials' => $sub, 'discount_rials' => $discount->rials, 'total_rials' => $total->rials];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}crc_orders WHERE id = %d", $id), ARRAY_A);
        if (!$row) {
            return null;
        }
        foreach (['id', 'customer_user_id', 'subtotal_rials', 'discount_rials', 'total_rials'] as $f) {
            $row[$f] = (int) $row[$f];
        }
        $row['lines'] = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, line_type, product_id, name, group_key, quantity, unit_rials, total_rials, meta_json FROM {$p}crc_order_lines WHERE order_id = %d ORDER BY id",
            $id
        ), ARRAY_A);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public static function listForCustomer(int $customerId, int $limit = 50): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_no, status, total_rials, created_at FROM {$wpdb->prefix}crc_orders WHERE customer_user_id = %d ORDER BY id DESC LIMIT %d",
            $customerId,
            max(1, min(100, $limit))
        ), ARRAY_A);
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(string $status = '', int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $t     = $wpdb->prefix . 'crc_orders';
        $limit = max(1, min(100, $limit));
        $sql   = $status === ''
            ? $wpdb->prepare("SELECT id, order_no, customer_user_id, status, total_rials, created_at FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, max(0, $offset))
            : $wpdb->prepare("SELECT id, order_no, customer_user_id, status, total_rials, created_at FROM {$t} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, max(0, $offset));
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Optimistic, logged transition: only succeeds if the order is still in `$from` (no lost updates).
     * @return true|\WP_Error
     */
    public static function transition(int $orderId, string $to, int $actorId)
    {
        global $wpdb;
        $t     = $wpdb->prefix . 'crc_orders';
        $order = self::find($orderId);
        if ($order === null) {
            return new \WP_Error('crc_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
        }
        $from = (string) $order['status'];
        if (!OrderStatus::canTransition($from, $to)) {
            return new \WP_Error('crc_bad_transition', 'این تغییر وضعیت مجاز نیست.', ['status' => 409]);
        }
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
            $to,
            gmdate('Y-m-d H:i:s'),
            $orderId,
            $from
        ));
        if (!$changed) {
            return new \WP_Error('crc_conflict', 'وضعیت سفارش هم‌زمان تغییر کرد؛ دوباره تلاش کنید.', ['status' => 409]);
        }
        $wpdb->insert($wpdb->prefix . 'crc_order_events', [
            'order_id' => $orderId, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actorId, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        AuditLog::record($actorId, 'orders.status_changed', 'order', (string) $orderId, ['status' => $from], ['status' => $to]);
        Events::dispatch('order.status_changed', ['order_id' => $orderId, 'from' => $from, 'to' => $to, 'customer_id' => $order['customer_user_id']]);
        return true;
    }
}
