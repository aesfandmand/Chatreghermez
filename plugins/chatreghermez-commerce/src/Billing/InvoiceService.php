<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

use Chatreghermez\Commerce\Audit\AuditLog;
use Chatreghermez\Commerce\Support\Events;
use Chatreghermez\Commerce\Support\Sequence;

final class InvoiceService
{
    /** Splits an order into invoices once (idempotent). @return list<array<string,mixed>>|\WP_Error */
    public static function issueForOrder(int $orderId, int $actorId = 0)
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $existing = self::forOrder($orderId);
        if ($existing !== []) {
            return $existing;
        }
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}crc_orders WHERE id = %d", $orderId), ARRAY_A);
        if (!$order) {
            return new \WP_Error('crc_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
        }
        $lines = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}crc_order_lines WHERE order_id = %d ORDER BY id", $orderId), ARRAY_A) as $l) {
            $l['meta'] = json_decode((string) $l['meta_json'], true) ?: [];
            $lines[] = $l;
        }
        $groups = InvoiceSplitter::split($lines);
        $discount = (int) $order['discount_rials'];
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($groups as $i => $g) {
                // The order discount is booked on the first invoice so invoice totals add up exactly to the order total.
                $total = max(0, $g['total_rials'] - ($i === 0 ? $discount : 0));
                $pre   = $g['kind'] === 'rental' ? PrepaymentPolicy::compute($total) : 0;
                $wpdb->insert("{$p}crc_invoices", [
                    'invoice_no' => 'INV-' . str_pad((string) Sequence::next('invoice'), 6, '0', STR_PAD_LEFT),
                    'order_id' => $orderId, 'kind' => $g['kind'], 'city' => $g['city'],
                    'customer_user_id' => (int) $order['customer_user_id'], 'total_rials' => $total,
                    'prepayment_rials' => $pre,
                    'reservation_expires_at' => $g['kind'] === 'rental' ? gmdate('Y-m-d H:i:s', ReservationPolicy::initialExpiry(time())) : null,
                    'created_at' => $now,
                ]);
                $invId = (int) $wpdb->insert_id;
                if ($invId === 0) {
                    throw new \RuntimeException('invoice_insert');
                }
                foreach ($g['lines'] as $l) {
                    $wpdb->insert("{$p}crc_invoice_lines", [
                        'invoice_id' => $invId, 'order_line_id' => (int) $l['id'], 'name' => $l['name'],
                        'quantity' => (int) $l['quantity'], 'unit_rials' => (int) $l['unit_rials'], 'total_rials' => (int) $l['total_rials'],
                    ]);
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('crc_invoice_failed', 'صدور فاکتور انجام نشد.', ['status' => 500]);
        }
        AuditLog::record($actorId, 'invoices.issued', 'order', (string) $orderId, null, ['count' => count($groups)]);
        Events::dispatch('invoice.issued', ['order_id' => $orderId, 'customer_id' => (int) $order['customer_user_id']]);
        return self::forOrder($orderId);
    }

    /** @return list<array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_invoices WHERE order_id = %d ORDER BY id", $orderId), ARRAY_A);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_invoices WHERE id = %d", $id), ARRAY_A);
        return $r ?: null;
    }

    /** Extend a rental hold (max 2 × 5 days). @return true|\WP_Error */
    public static function extendReservation(int $invoiceId, int $actorId)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'crc_invoices';
        $inv = self::find($invoiceId);
        if (!$inv || $inv['kind'] !== 'rental' || !$inv['reservation_expires_at']) {
            return new \WP_Error('crc_not_found', 'فاکتور اجاره پیدا نشد.', ['status' => 404]);
        }
        $exp = (int) strtotime($inv['reservation_expires_at'] . ' UTC');
        if (!ReservationPolicy::canExtend((int) $inv['extensions_used'], $exp, time())) {
            return new \WP_Error('crc_cannot_extend', 'تمدید بیشتر ممکن نیست.', ['status' => 409]);
        }
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET reservation_expires_at = %s, extensions_used = extensions_used + 1 WHERE id = %d AND extensions_used = %d",
            gmdate('Y-m-d H:i:s', ReservationPolicy::extendedExpiry($exp)),
            $invoiceId,
            (int) $inv['extensions_used']
        ));
        if (!$ok) {
            return new \WP_Error('crc_conflict', 'هم‌زمان تغییر کرد؛ دوباره تلاش کنید.', ['status' => 409]);
        }
        AuditLog::record($actorId, 'invoices.reservation_extended', 'invoice', (string) $invoiceId);
        Events::dispatch('reservation.extended', ['invoice_id' => $invoiceId]);
        return true;
    }
}
