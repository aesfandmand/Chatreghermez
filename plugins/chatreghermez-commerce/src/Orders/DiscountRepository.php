<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

final class DiscountRepository
{
    /** @return array<string,mixed>|null shaped for DiscountCalculator */
    public static function findForCustomer(string $code, int $customerId): ?array
    {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}crc_discount_codes WHERE code = %s", strtoupper(trim($code))), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['id']        = (int) $row['id'];
        $row['scope_ids'] = json_decode((string) $row['scope_json'], true) ?: [];
        $row['starts_at'] = $row['starts_at'] ? strtotime((string) $row['starts_at'] . ' UTC') : 0;
        $row['ends_at']   = $row['ends_at'] ? strtotime((string) $row['ends_at'] . ' UTC') : 0;
        $row['customer_uses'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}crc_discount_redemptions WHERE code_id = %d AND user_id = %d",
            $row['id'],
            $customerId
        ));
        return $row;
    }

    /** Atomically take one use; false when the code just ran out. */
    public static function consume(int $codeId, int $orderId, int $userId, int $amountRials): bool
    {
        global $wpdb;
        $p  = $wpdb->prefix;
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$p}crc_discount_codes SET used_count = used_count + 1 WHERE id = %d AND (max_uses = 0 OR used_count < max_uses)",
            $codeId
        ));
        if (!$ok) {
            return false;
        }
        $wpdb->insert("{$p}crc_discount_redemptions", [
            'code_id' => $codeId, 'order_id' => $orderId, 'user_id' => $userId,
            'amount_rials' => $amountRials, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return true;
    }
}
