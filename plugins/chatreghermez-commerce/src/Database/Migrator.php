<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Database;

/**
 * Versioned schema. Each structural change = a new method + DB_VERSION bump. Never edit shipped migrations.
 */
final class Migrator
{
    public const DB_VERSION = 3;
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
        if ($current < 3) {
            self::migration003();
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

    /** Orders: counters, carts, orders, lines, status log, discount codes. */
    private static function migration003(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}crc_counters (
  name varchar(32) NOT NULL,
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_carts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cart_key varchar(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  discount_code varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY cart_key (cart_key)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_cart_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cart_id bigint(20) unsigned NOT NULL,
  line_type varchar(32) NOT NULL DEFAULT 'product_purchase',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  selection_json longtext NULL,
  quantity int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY cart (cart_id)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_orders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_no varchar(32) NOT NULL,
  customer_user_id bigint(20) unsigned NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(24) NOT NULL DEFAULT 'pending_payment',
  subtotal_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  discount_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  total_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  discount_code varchar(64) NOT NULL DEFAULT '',
  note text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_no (order_no),
  KEY customer (customer_user_id),
  KEY status (status)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_order_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  line_type varchar(32) NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(200) NOT NULL,
  group_key varchar(64) NOT NULL DEFAULT '',
  selection_json longtext NULL,
  meta_json longtext NULL,
  quantity int(10) unsigned NOT NULL,
  unit_rials bigint(20) unsigned NOT NULL,
  total_rials bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_order_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  from_status varchar(24) NOT NULL,
  to_status varchar(24) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_discount_codes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(64) NOT NULL,
  type varchar(8) NOT NULL DEFAULT 'percent',
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  scope_type varchar(8) NOT NULL DEFAULT 'all',
  scope_json longtext NULL,
  min_order_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  max_uses int(10) unsigned NOT NULL DEFAULT 0,
  used_count int(10) unsigned NOT NULL DEFAULT 0,
  per_customer int(10) unsigned NOT NULL DEFAULT 0,
  starts_at datetime NULL,
  ends_at datetime NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_discount_redemptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  amount_rials bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY code_user (code_id, user_id)
) {$c};");
    }
}
