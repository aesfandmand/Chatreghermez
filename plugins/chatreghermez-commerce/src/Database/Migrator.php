<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Database;

/**
 * Versioned schema. Each structural change = a new method + DB_VERSION bump. Never edit shipped migrations.
 */
final class Migrator
{
    public const DB_VERSION = 2;
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
        if ($current < 2) {
            self::migration002();
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

    /** Catalog: products, option groups, option values, quantity price tiers. */
    private static function migration002(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}crc_products (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(190) NOT NULL,
  name varchar(200) NOT NULL,
  type varchar(16) NOT NULL DEFAULT 'simple',
  price_model varchar(16) NOT NULL DEFAULT 'fixed',
  base_price_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  area_rate_rials_per_m2 bigint(20) unsigned NOT NULL DEFAULT 0,
  min_charge_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  max_qty int(10) unsigned NOT NULL DEFAULT 100000,
  min_dim_cm int(10) unsigned NOT NULL DEFAULT 1,
  max_dim_cm int(10) unsigned NOT NULL DEFAULT 100000,
  status varchar(16) NOT NULL DEFAULT 'draft',
  category varchar(64) NOT NULL DEFAULT '',
  summary text NULL,
  description longtext NULL,
  image_id bigint(20) unsigned NOT NULL DEFAULT 0,
  seo_title varchar(200) NOT NULL DEFAULT '',
  seo_description varchar(320) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status_category (status, category)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_product_options (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL,
  required tinyint(1) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY product (product_id)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_option_values (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  option_id bigint(20) unsigned NOT NULL,
  label varchar(160) NOT NULL,
  price_delta_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  price_mult_bp int(10) unsigned NOT NULL DEFAULT 10000,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY opt (option_id)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_price_tiers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  min_qty int(10) unsigned NOT NULL,
  unit_price_rials bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY product_qty (product_id, min_qty)
) {$c};");
    }
}
