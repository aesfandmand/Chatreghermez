<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

/** Cart storage. Key = "user:<id>" for logged-in users, "guest:<token>" otherwise. */
final class CartRepository
{
    public static function keyFor(int $userId, string $guestToken): string
    {
        return $userId > 0 ? 'user:' . $userId : 'guest:' . $guestToken;
    }

    public static function getOrCreate(string $key, int $userId = 0): int
    {
        global $wpdb;
        $t  = $wpdb->prefix . 'crc_carts';
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE cart_key = %s", $key));
        if ($id > 0) {
            return $id;
        }
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($t, ['cart_key' => $key, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        return (int) $wpdb->insert_id;
    }

    /** Move a guest cart into the user's cart after login (lines are appended). */
    public static function adoptGuestCart(string $guestToken, int $userId): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $guest = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}crc_carts WHERE cart_key = %s", 'guest:' . $guestToken));
        if ($guest === 0) {
            return;
        }
        $mine = self::getOrCreate('user:' . $userId, $userId);
        $wpdb->update("{$p}crc_cart_lines", ['cart_id' => $mine], ['cart_id' => $guest]);
        $wpdb->delete("{$p}crc_carts", ['id' => $guest]);
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $cartId): array
    {
        global $wpdb;
        $t   = $wpdb->prefix . 'crc_cart_lines';
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE cart_id = %d ORDER BY id", $cartId), ARRAY_A) as $r) {
            $r['id'] = (int) $r['id'];
            $r['product_id'] = (int) $r['product_id'];
            $r['quantity'] = (int) $r['quantity'];
            $r['selection'] = json_decode((string) $r['selection_json'], true) ?: [];
            $out[] = $r;
        }
        return $out;
    }

    /** @param array<string,mixed> $selection */
    public static function addLine(int $cartId, string $lineType, int $productId, array $selection, int $quantity): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'crc_cart_lines', [
            'cart_id' => $cartId, 'line_type' => $lineType, 'product_id' => $productId,
            'selection_json' => wp_json_encode($selection), 'quantity' => max(1, $quantity),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function removeLine(int $cartId, int $lineId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'crc_cart_lines', ['id' => $lineId, 'cart_id' => $cartId]);
    }

    public static function clear(int $cartId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'crc_cart_lines', ['cart_id' => $cartId]);
        $wpdb->update($wpdb->prefix . 'crc_carts', ['discount_code' => ''], ['id' => $cartId]);
    }

    public static function setDiscountCode(int $cartId, string $code): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'crc_carts', ['discount_code' => $code, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $cartId]);
    }

    public static function discountCode(int $cartId): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT discount_code FROM {$wpdb->prefix}crc_carts WHERE id = %d", $cartId));
    }
}
