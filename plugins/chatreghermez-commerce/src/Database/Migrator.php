<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Database;

/**
 * Versioned schema. Each structural change = a new method + DB_VERSION bump. Never edit shipped migrations.
 */
final class Migrator
{
    public const DB_VERSION = 1;
    private const OPTION    = 'crc_commerce_db_version';

    public static function run(): void
    {
        $current = (int) get_option(self::OPTION, 0);
        if ($current >= self::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        if ($current < 1) {
            self::migration001();
        }
        update_option(self::OPTION, self::DB_VERSION, false);
    }

    private static function migration001(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'crc_audit_log';

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(64) NOT NULL DEFAULT '',
  object_id varchar(64) NOT NULL DEFAULT '',
  before_json longtext NULL,
  after_json longtext NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY object_ref (object_type, object_id),
  KEY actor (actor_id),
  KEY created (created_at)
) {$charset};");
    }
}
