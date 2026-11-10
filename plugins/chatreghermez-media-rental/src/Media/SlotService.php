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
                'UPDATE ' . self::t() . " SET locked_at = IF(state = 'locked' AND holder = %d, locked_at, %d), state = 'locked', holder = %d, locked_until = %d
                 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND (
                   state = 'free'
                   OR (state = 'locked' AND (holder = %d OR locked_until <= %d))
                   OR (state = 'reserved' AND reserved_until <= %d))",
                $holder, $now, $holder, $now + SlotRules::LOCK_SECONDS, $mediaId, $year, $m, $holder, $now, $now
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

    /** payment.confirmed: the paid deposit reserves for 5 days; full settlement (incl. accountant-approved cheques) makes it final. */
    public static function onPayment(array $payload): void
    {
        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_invoices WHERE id = %d AND kind = 'rental'", (int) ($payload['invoice_id'] ?? 0)), ARRAY_A);
        if (!$inv) {
            return;
        }
        $id = (int) $inv['id'];
        $slots = self::slotsOfInvoice($id);
        $paid = (int) $inv['paid_rials'];
        $final = $paid >= (int) $inv['total_rials'];
        $deposit = (int) $inv['prepayment_rials'] > 0 && $paid >= (int) $inv['prepayment_rials'];
        $holder = (int) $inv['customer_user_id'];
        if ($final) {
            foreach ($slots as $s) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'final', invoice_id = %d, locked_until = 0, reserved_until = 0 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d",
                    $id, $s['media_id'], $s['fiscal_year'], $s['month'], $holder));
            }
            return;
        }
        if (!$deposit) {
            return;
        }
        // The 5-day clock starts when the deposit is confirmed (once; later partial payments don't restart it).
        $already = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . " WHERE invoice_id = %d AND state = 'reserved'", $id));
        $until = $already > 0 && $inv['reservation_expires_at'] ? (int) strtotime($inv['reservation_expires_at'] . ' UTC') : time() + 5 * 86400;
        if ($already === 0) {
            $wpdb->update($wpdb->prefix . 'crc_invoices', ['reservation_expires_at' => gmdate('Y-m-d H:i:s', $until)], ['id' => $id]);
        }
        foreach ($slots as $s) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'reserved', invoice_id = %d, reserved_until = %d, locked_until = 0 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d AND state IN ('locked','reserved')",
                $id, $until, $s['media_id'], $s['fiscal_year'], $s['month'], $holder));
        }
    }

    /** @return list<array{media_id:int,fiscal_year:int,month:int}> the rental slots an invoice covers */
    private static function slotsOfInvoice(int $invoiceId): array
    {
        global $wpdb;
        $out = [];
        $lines = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ol.meta_json FROM {$wpdb->prefix}crc_invoice_lines il JOIN {$wpdb->prefix}crc_order_lines ol ON ol.id = il.order_line_id WHERE il.invoice_id = %d",
            $invoiceId
        ), ARRAY_A);
        foreach ($lines as $l) {
            $meta = json_decode((string) $l['meta_json'], true) ?: [];
            foreach ((array) ($meta['months'] ?? []) as $m) {
                $out[] = ['media_id' => (int) ($meta['media_id'] ?? 0), 'fiscal_year' => (int) ($meta['fiscal_year'] ?? 0), 'month' => (int) $m];
            }
        }
        return $out;
    }

    /** payment.started (online gateway): the 15-minute lock counts from the moment the customer is sent to the bank. */
    public static function onPaymentStarted(array $payload): void
    {
        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT customer_user_id FROM {$wpdb->prefix}crc_invoices WHERE id = %d AND kind = 'rental'", (int) ($payload['invoice_id'] ?? 0)), ARRAY_A);
        if (!$inv) {
            return;
        }
        foreach (self::slotsOfInvoice((int) $payload['invoice_id']) as $s) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET locked_until = %d WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d AND state = 'locked'",
                time() + SlotRules::LOCK_SECONDS, $s['media_id'], $s['fiscal_year'], $s['month'], (int) $inv['customer_user_id']));
        }
    }

    /** reservation.extended: copy the invoice's new expiry onto its reserved slots. */
    public static function onExtended(array $payload): void
    {
        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT reservation_expires_at FROM {$wpdb->prefix}crc_invoices WHERE id = %d", (int) ($payload['invoice_id'] ?? 0)), ARRAY_A);
        $wpdb->delete($wpdb->prefix . 'crc_media_reminders', ['invoice_id' => (int) ($payload['invoice_id'] ?? 0)]); // remind again before the new end
        if ($inv && $inv['reservation_expires_at']) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET reserved_until = %d WHERE invoice_id = %d AND state = 'reserved'", (int) strtotime($inv['reservation_expires_at'] . ' UTC'), (int) $payload['invoice_id']));
        }
    }

    /** Page ping while the customer is on the selection/payment pages. @return int number of slots kept */
    public static function heartbeat(int $mediaId, int $year, array $months, int $holder, int $now): int
    {
        global $wpdb;
        $kept = 0;
        foreach ($months as $m) {
            $r = $wpdb->get_row($wpdb->prepare('SELECT locked_at, locked_until FROM ' . self::t() . " WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d AND state = 'locked'", $mediaId, $year, $m, $holder), ARRAY_A);
            if (!$r) {
                continue;
            }
            $new = SlotRules::heartbeatUntil((int) $r['locked_at'], (int) $r['locked_until'], $now);
            if ($new !== null) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET locked_until = %d WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d AND state = 'locked'", $new, $mediaId, $year, $m, $holder));
                $kept++;
            }
        }
        return $kept;
    }

    /** The customer left the selection/payment pages: free the slots for others right away. */
    public static function release(int $mediaId, int $year, array $months, int $holder): void
    {
        global $wpdb;
        foreach ($months as $m) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'free', holder = 0, locked_until = 0, locked_at = 0 WHERE media_id = %d AND fiscal_year = %d AND month = %d AND holder = %d AND state = 'locked'", $mediaId, $year, $m, $holder));
            self::offerToWaiter($mediaId, $year, (int) $m, time());
        }
    }

    /** Hourly: reservations ending within 2 days -> reminder SMS to the customer, the media supervisor and the accountant (once per reservation). */
    public static function sendReminders(): void
    {
        global $wpdb;
        $inv = $wpdb->prefix . 'crc_invoices';
        $rem = $wpdb->prefix . 'crc_media_reminders';
        $now = time();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT i.id, i.invoice_no, i.customer_user_id, i.reservation_expires_at FROM {$inv} i LEFT JOIN {$rem} r ON r.invoice_id = i.id
             WHERE i.kind = 'rental' AND i.status <> 'paid' AND i.reservation_expires_at IS NOT NULL AND r.invoice_id IS NULL
               AND i.reservation_expires_at > %s AND i.reservation_expires_at <= %s
               AND EXISTS (SELECT 1 FROM " . self::t() . " s WHERE s.invoice_id = i.id AND s.state = 'reserved')",
            gmdate('Y-m-d H:i:s', $now),
            gmdate('Y-m-d H:i:s', $now + 2 * 86400)
        ), ARRAY_A);
        foreach ($rows as $row) {
            $days = max(1, (int) ceil(((int) strtotime($row['reservation_expires_at'] . ' UTC') - $now) / 86400));
            $targets = [(int) $row['customer_user_id']];
            foreach (get_users(['role__in' => ['crc_media_supervisor', 'crc_accountant'], 'fields' => 'ID']) as $uid) {
                $targets[] = (int) $uid;
            }
            foreach (array_unique($targets) as $uid) {
                $mobile = (string) get_user_meta($uid, 'crc_mobile', true);
                if ($mobile !== '') {
                    \Chatreghermez\Commerce\Sms\SmsService::queue($mobile, 'reservation_reminder', ['invoice_no' => (string) $row['invoice_no'], 'days' => (string) $days]);
                }
            }
            $wpdb->insert($rem, ['invoice_id' => (int) $row['id'], 'sent_at' => $now]);
        }
    }

    /** Hourly: lapsed locks/reservations return to the pool (pre-payment is forfeited); then offer the slot to the next waiter. */
    public static function releaseExpired(): void
    {
        global $wpdb;
        $now = time();
        $freed = (array) $wpdb->get_results($wpdb->prepare('SELECT media_id, fiscal_year, month FROM ' . self::t() . " WHERE (state = 'locked' AND locked_until <= %d) OR (state = 'reserved' AND reserved_until <= %d)", $now, $now), ARRAY_A);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state = 'free', holder = 0, locked_until = 0, locked_at = 0, reserved_until = 0, order_id = 0, invoice_id = 0 WHERE (state = 'locked' AND locked_until <= %d) OR (state = 'reserved' AND reserved_until <= %d)", $now, $now));
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
