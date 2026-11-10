<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Payments;

use Chatreghermez\Commerce\Audit\AuditLog;
use Chatreghermez\Commerce\Billing\ChequePolicy;
use Chatreghermez\Commerce\Orders\OrderService;
use Chatreghermez\Commerce\Orders\OrderStatus;
use Chatreghermez\Commerce\Support\Events;

final class PaymentService
{
    private const MANUAL = ['manual_cash', 'manual_card'];

    /** @return array{payment_id:int,redirect_url:string}|\WP_Error */
    public static function startOnline(int $invoiceId, int $amountRials, string $callbackUrl, ?GatewayAdapter $gw = null)
    {
        global $wpdb;
        $inv = self::invoice($invoiceId);
        $gw ??= GatewayFactory::make();
        if (!$inv || $gw === null) {
            return new \WP_Error('crc_gateway_off', 'پرداخت آنلاین فعال نیست.', ['status' => 503]);
        }
        $due = (int) $inv['total_rials'] - (int) $inv['paid_rials'];
        if ($amountRials <= 0 || $amountRials > $due) {
            return new \WP_Error('crc_bad_amount', 'مبلغ نامعتبر است.', ['status' => 422]);
        }
        try {
            $init = $gw->initiate($amountRials, (string) $inv['invoice_no'], $callbackUrl);
        } catch (\Throwable $e) {
            return new \WP_Error('crc_gateway_error', 'اتصال به درگاه انجام نشد.', ['status' => 502]);
        }
        $wpdb->insert($wpdb->prefix . 'crc_payments', [
            'invoice_id' => $invoiceId, 'method' => 'gateway', 'amount_rials' => $amountRials, 'status' => 'pending',
            'gateway' => $gw->id(), 'gateway_ref' => $init['ref'], 'idempotency_key' => 'gw-' . $gw->id() . '-' . $init['ref'],
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $paymentId = (int) $wpdb->insert_id;
        Events::dispatch('payment.started', ['invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'customer_id' => (int) $inv['customer_user_id']]);
        return ['payment_id' => $paymentId, 'redirect_url' => $init['redirect_url']];
    }

    /** Called from the browser callback; trusts only the gateway's own verify. Safe to call repeatedly. @return array<string,mixed>|\WP_Error */
    public static function confirmOnline(string $ref, ?GatewayAdapter $gw = null)
    {
        global $wpdb;
        $pay = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_payments WHERE gateway_ref = %s AND method = 'gateway'", $ref), ARRAY_A);
        $gw ??= GatewayFactory::make();
        if (!$pay || $gw === null) {
            return new \WP_Error('crc_not_found', 'پرداخت پیدا نشد.', ['status' => 404]);
        }
        if ($pay['status'] === 'confirmed') {
            return ['status' => 'confirmed', 'invoice_id' => (int) $pay['invoice_id']];
        }
        try {
            $v = $gw->verify($ref);
        } catch (\Throwable $e) {
            return new \WP_Error('crc_gateway_error', 'تأیید پرداخت ممکن نشد؛ بعداً دوباره بررسی می‌شود.', ['status' => 502]);
        }
        if (!$v['paid'] || (int) $v['amount_rials'] !== (int) $pay['amount_rials']) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}crc_payments SET status = 'failed' WHERE id = %d AND status = 'pending'", $pay['id']));
            return ['status' => 'failed', 'invoice_id' => (int) $pay['invoice_id']];
        }
        self::confirm((int) $pay['id'], $v['receipt']);
        return ['status' => 'confirmed', 'invoice_id' => (int) $pay['invoice_id']];
    }

    /** Accountant records cash / card-to-card. @return int|\WP_Error payment id */
    public static function recordManual(int $invoiceId, string $method, int $amountRials, int $actorId, string $idempotencyKey, string $note = '')
    {
        global $wpdb;
        $inv = self::invoice($invoiceId);
        if (!$inv || !in_array($method, self::MANUAL, true)) {
            return new \WP_Error('crc_bad_request', 'درخواست نامعتبر است.', ['status' => 422]);
        }
        $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}crc_payments WHERE idempotency_key = %s", 'm-' . $idempotencyKey));
        if ($dup) {
            return (int) $dup; // same submission twice = same payment
        }
        if ($amountRials <= 0 || $amountRials > (int) $inv['total_rials'] - (int) $inv['paid_rials']) {
            return new \WP_Error('crc_bad_amount', 'مبلغ نامعتبر است.', ['status' => 422]);
        }
        $wpdb->insert($wpdb->prefix . 'crc_payments', [
            'invoice_id' => $invoiceId, 'method' => $method, 'amount_rials' => $amountRials, 'status' => 'pending',
            'idempotency_key' => 'm-' . $idempotencyKey, 'recorded_by' => $actorId, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $wpdb->insert_id;
        self::confirm($id, '');
        AuditLog::record($actorId, 'payments.manual_recorded', 'invoice', (string) $invoiceId, null, ['amount_rials' => $amountRials, 'method' => $method]);
        return $id;
    }

    /** Register a cheque (pending until the accountant approves). @return int|\WP_Error cheque id */
    public static function registerCheque(int $invoiceId, array $c, int $actorId)
    {
        global $wpdb;
        $inv = self::invoice($invoiceId);
        if (!$inv) {
            return new \WP_Error('crc_not_found', 'فاکتور پیدا نشد.', ['status' => 404]);
        }
        $errors = ChequePolicy::validate($c, (string) $inv['kind'], time());
        if ($errors !== []) {
            return new \WP_Error('crc_cheque_invalid', 'اطلاعات چک نامعتبر است.', ['status' => 422, 'errors' => $errors]);
        }
        if ((int) $c['amount_rials'] > (int) $inv['total_rials'] - (int) $inv['paid_rials']) {
            return new \WP_Error('crc_bad_amount', 'مبلغ چک از مانده بیشتر است.', ['status' => 422]);
        }
        $p = $wpdb->prefix;
        $wpdb->insert("{$p}crc_payments", [
            'invoice_id' => $invoiceId, 'method' => 'cheque', 'amount_rials' => (int) $c['amount_rials'], 'status' => 'pending',
            'idempotency_key' => 'c-' . $c['sayad_id'], 'recorded_by' => $actorId, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $payId = (int) $wpdb->insert_id;
        if ($payId === 0) {
            return new \WP_Error('crc_cheque_duplicate', 'این چک قبلاً ثبت شده است.', ['status' => 409]);
        }
        $wpdb->insert("{$p}crc_cheques", [
            'invoice_id' => $invoiceId, 'payment_id' => $payId, 'sayad_id' => $c['sayad_id'],
            'payee_national_code' => $c['payee_national_code'], 'amount_rials' => (int) $c['amount_rials'],
            'due_at' => gmdate('Y-m-d H:i:s', (int) $c['due_at']),
        ]);
        $chequeId = (int) $wpdb->insert_id;
        AuditLog::record($actorId, 'cheques.registered', 'invoice', (string) $invoiceId, null, ['sayad_id' => $c['sayad_id']]);
        Events::dispatch('cheque.registered', ['invoice_id' => $invoiceId, 'cheque_id' => $chequeId, 'customer_id' => (int) $inv['customer_user_id']]);
        return $chequeId;
    }

    /** @return true|\WP_Error */
    public static function approveCheque(int $chequeId, int $actorId)
    {
        global $wpdb;
        $p  = $wpdb->prefix;
        $ch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}crc_cheques WHERE id = %d", $chequeId), ARRAY_A);
        if (!$ch || $ch['status'] !== 'pending') {
            return new \WP_Error('crc_conflict', 'چک قابل تأیید نیست.', ['status' => 409]);
        }
        $wpdb->update("{$p}crc_cheques", ['status' => 'approved', 'approved_by' => $actorId], ['id' => $chequeId]);
        self::confirm((int) $ch['payment_id'], '');
        AuditLog::record($actorId, 'cheques.approved', 'cheque', (string) $chequeId);
        return true;
    }

    /** Pending -> confirmed exactly once; books invoice, ledger and order status in one transaction. */
    private static function confirm(int $paymentId, string $receipt): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query('START TRANSACTION');
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$p}crc_payments SET status = 'confirmed', receipt = %s, confirmed_at = %s WHERE id = %d AND status = 'pending'",
            $receipt,
            gmdate('Y-m-d H:i:s'),
            $paymentId
        ));
        if (!$changed) {
            $wpdb->query('ROLLBACK');
            return; // already confirmed by a parallel callback: nothing to book twice
        }
        $pay = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}crc_payments WHERE id = %d", $paymentId), ARRAY_A);
        $wpdb->query($wpdb->prepare("UPDATE {$p}crc_invoices SET paid_rials = paid_rials + %d WHERE id = %d", $pay['amount_rials'], $pay['invoice_id']));
        $wpdb->query($wpdb->prepare("UPDATE {$p}crc_invoices SET status = IF(paid_rials >= total_rials, 'paid', 'partially_paid') WHERE id = %d", $pay['invoice_id']));
        $wpdb->insert("{$p}crc_ledger", [
            'invoice_id' => (int) $pay['invoice_id'], 'payment_id' => $paymentId, 'entry_type' => 'payment',
            'amount_rials' => (int) $pay['amount_rials'], 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $wpdb->query('COMMIT');

        $inv = self::invoice((int) $pay['invoice_id']);
        self::syncOrder((int) $inv['order_id']);
        Events::dispatch('payment.confirmed', ['payment_id' => $paymentId, 'invoice_id' => (int) $pay['invoice_id'], 'order_id' => (int) $inv['order_id'],
            'customer_id' => (int) $inv['customer_user_id'], 'amount_rials' => (int) $pay['amount_rials']]);
    }

    private static function syncOrder(int $orderId): void
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT status, paid_rials FROM {$wpdb->prefix}crc_invoices WHERE order_id = %d", $orderId), ARRAY_A);
        $allPaid = $rows !== [] && count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'paid')) === count($rows);
        $anyPaid = array_sum(array_map(static fn (array $r): int => (int) $r['paid_rials'], $rows)) > 0;
        $order = OrderService::find($orderId);
        if (!$order) {
            return;
        }
        $target = $allPaid ? OrderStatus::PAID : ($anyPaid ? OrderStatus::PARTIALLY_PAID : null);
        if ($target !== null && $target !== $order['status']) {
            OrderService::transition($orderId, $target, 0);
        }
    }

    /** @return array<string,mixed>|null */
    private static function invoice(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_invoices WHERE id = %d", $id), ARRAY_A);
        return $r ?: null;
    }
}
