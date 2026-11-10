<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Media;

use Chatreghermez\MediaRental\Domain\FiscalCalendar;
use Chatreghermez\MediaRental\Domain\SlotRules;
use Chatreghermez\MediaRental\Domain\WaitQueue;

/** Atomic slot transitions (single UPDATE ... WHERE guards), so two customers can never hold the same month. */
final class SlotService
{
    private static function t(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crc_media_slots';
    }

    public static function ensure(int $mediaId, int $year): void
    {
        global $wpdb;
        for ($m = 1; $m <= 12; $m++) {
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t() . ' (media_id, fiscal_year, month) VALUES (%d, %d, %d)', $mediaId, $year, $m));
        }
    }

    /** @return list<array<string,mixed>> 12 slots with the colour a customer sees now */
    public static function calendar(int $mediaId, int $year, int $now): array
    {
        global $wpdb;
        self::ensure($mediaId, $year);
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t() . ' WHERE media_id = %d AND fiscal_year = %d ORDER BY month', $mediaId, $year), ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $eff = SlotRules::effectiveState($r, $now);
            $out[] = ['month' => (int) $r['month'], 'name' => FiscalCalendar::monthName((int) $r['month']), 'state' => $eff,
                'colour' => SlotRules::colour($eff), 'days_left' => $eff === SlotRules::RESERVED ? SlotRules::daysLeft($r, $now) : 0];
        }
        return $out;
    }

    /** Lock months for a holder (all or nothing). @return true|\WP_Error */
    public static function lock(int $mediaId, int $year, array $months, int $holder, int $now)
    {
        global $wpdb;
        self::ensure($mediaId, $year);
        $wpdb->query('START TRANSACTION');
        foreach ($months as $m) {
            $ok = $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::t() . " SET state = 'locked', holder = %d, locked_until = %d
                 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND (
                   state = 'free'
                   OR (state = 'locked' AND (holder = %d OR locked_until <= %d))
                   OR (state = 'reserved' AND reserved_until <= %d))",
                $holder, $now + SlotRules::LOCK_SECONDS, $mediaId, $year, $m, $holder, $now, $now
            ));
            if (!$ok) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('crc_slot_taken', 'یکی از ماه‌های انتخابی دیگر آزاد نیست.', ['status' => 409, 'month' => $m]);
            }
        }
        $wpdb->query('COMMIT');
        return true;
    }

    /** True when every month is currently held by this holder (valid lock or own reservation). */
    public static function heldBy(int $mediaId, int $year, array $months, int $holder, int $now): bool
    {
        global $wpdb;
        foreach ($months as $m) {
            $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . ' WHERE media_id = %d AND fiscal_year = %d AND month = %d', $mediaId, $year, $m), ARRAY_A);
            if (!$r || !SlotRules::canFinalize($r, $holder, $now)) {
                return false;
            }
        }
        return true;
    }

    /** Tie the held slots to an order/invoice once the order exists. */
    public static function attach(int $mediaId, int $year, array $months, int $holder, int $orderId, int $invoiceId = 0): void
    {
        global $wpdb;
        foreach ($months as $m) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . ' SET order_id = %d, invoice_id = IF(%d > 0, %d, invoice_id) WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d',
                $orderId, $invoiceId, $invoiceId, $mediaId, $year, $m, $holder));
        }
    }

    /** payment.confirmed: pre-payment reserves for 5 days; full payment makes the months final. */
    public static function onPayment(array $payload): void
    {
        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_invoices WHERE id = %d AND kind = 'rental'", (int) ($payload['invoice_id'] ?? 0)), ARRAY_A);
        if (!$inv) {
            return;
        }
        // Slots are linked to the order through the rental line meta; resolve them for this invoice's lines.
        $lines = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ol.meta_json FROM {$wpdb->prefix}crc_invoice_lines il JOIN {$wpdb->prefix}crc_order_lines ol ON ol.id = il.order_line_id WHERE il.invoice_id = %d",
            (int) $inv['id']
        ), ARRAY_A);
        $paid = (int) $inv['paid_rials'];
        $final = $paid >= (int) $inv['total_rials'];
        $reserved = $paid >= (int) $inv['prepayment_rials'] && (int) $inv['prepayment_rials'] > 0;
        $until = $inv['reservation_expires_at'] ? (int) strtotime($inv['reservation_expires_at'] . ' UTC') : 0;
        foreach ($lines as $l) {
            $meta = json_decode((string) $l['meta_json'], true) ?: [];
            foreach ((array) ($meta['months'] ?? []) as $m) {
                if ($final) {
                    $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'final', invoice_id = %d, locked_until = 0, reserved_until = 0 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d",
                        (int) $inv['id'], (int) $meta['media_id'], (int) $meta['fiscal_year'], (int) $m, (int) $inv['customer_user_id']));
                } elseif ($reserved) {
                    $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'reserved', invoice_id = %d, reserved_until = %d, locked_until = 0 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d AND state <> 'final'",
                        (int) $inv['id'], $until ?: time() + 5 * 86400, (int) $meta['media_id'], (int) $meta['fiscal_year'], (int) $m, (int) $inv['customer_user_id']));
                }
            }
        }
    }

    /** reservation.extended: copy the invoice's new expiry onto its reserved slots. */
    public static function onExtended(array $payload): void
    {
        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT reservation_expires_at FROM {$wpdb->prefix}crc_invoices WHERE id = %d", (int) ($payload['invoice_id'] ?? 0)), ARRAY_A);
        if ($inv && $inv['reservation_expires_at']) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET reserved_until = %d WHERE invoice_id = %d AND state = 'reserved'", (int) strtotime($inv['reservation_expires_at'] . ' UTC'), (int) $payload['invoice_id']));
        }
    }

    /** Hourly: lapsed locks/reservations return to the pool (pre-payment is forfeited); then offer the slot to the next waiter. */
    public static function releaseExpired(): void
    {
        global $wpdb;
        $now = time();
        $freed = (array) $wpdb->get_results($wpdb->prepare('SELECT media_id, fiscal_year, month FROM ' . self::t() . " WHERE (state = 'locked' AND locked_until <= %d) OR (state = 'reserved' AND reserved_until <= %d)", $now, $now), ARRAY_A);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'free', holder = 0, locked_until = 0, reserved_until = 0, order_id = 0, invoice_id = 0 WHERE (state = 'locked' AND locked_until <= %d) OR (state = 'reserved' AND reserved_until <= %d)", $now, $now));
        foreach ($freed as $s) {
            self::offerToWaiter((int) $s['media_id'], (int) $s['fiscal_year'], (int) $s['month'], $now);
        }
    }

    /** Notify the next person in line (one per hour per slot). */
    public static function offerToWaiter(int $mediaId, int $year, int $month, int $now): void
    {
        global $wpdb;
        $w = $wpdb->prefix . 'crc_media_waitlist';
        $entries = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'created_at' => (int) $r['created_at'], 'notified_at' => (int) $r['notified_at']],
            (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$w} WHERE media_id = %d AND fiscal_year = %d AND month = %d", $mediaId, $year, $month), ARRAY_A));
        $last = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(notified_at) FROM {$w} WHERE media_id = %d AND fiscal_year = %d AND month = %d", $mediaId, $year, $month));
        $next = WaitQueue::next($entries, $last, $now);
        if ($next === null) {
            return;
        }
        $wpdb->update($w, ['notified_at' => $now], ['id' => $next['id']]);
        \Chatreghermez\Commerce\Support\Events::dispatch('media.slot_available', ['waitlist_id' => $next['id'], 'media_id' => $mediaId, 'fiscal_year' => $year, 'month' => $month,
            'customer_id' => (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$w} WHERE id = %d", $next['id']))]);
    }
}
