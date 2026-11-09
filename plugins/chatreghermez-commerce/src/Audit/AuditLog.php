<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Audit;

/** Append-only log of sensitive changes (who, what, before, after). Never store secrets here. */
final class AuditLog
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crc_audit_log';
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function record(
        int $actorId,
        string $action,
        string $objectType = '',
        string $objectId = '',
        ?array $before = null,
        ?array $after = null
    ): void {
        global $wpdb;
        $wpdb->insert(
            self::table(),
            [
                'created_at'  => gmdate('Y-m-d H:i:s'),
                'actor_id'    => $actorId,
                'action'      => substr($action, 0, 64),
                'object_type' => substr($objectType, 0, 64),
                'object_id'   => substr($objectId, 0, 64),
                'before_json' => $before === null ? null : wp_json_encode($before),
                'after_json'  => $after === null ? null : wp_json_encode($after),
                'ip'          => self::ip(),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private static function ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 45);
    }
}
