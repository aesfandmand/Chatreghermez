<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Support;

/** Gap-free-ish atomic counters (order and invoice numbers) via LAST_INSERT_ID trick. */
final class Sequence
{
    public static function next(string $name): int
    {
        global $wpdb;
        $t = $wpdb->prefix . 'crc_counters';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (name, value) VALUES (%s, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)",
            $name
        ));
        return (int) $wpdb->insert_id;
    }
}
