<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Database;

final class Migrator
{
    public const DB_VERSION = 1;
    private const OPTION = 'crc_media_db_version';

    public static function run(): void
    {
        if ((int) get_option(self::OPTION, 0) >= self::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}crc_media (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code char(6) NOT NULL,
  name varchar(200) NOT NULL,
  media_type varchar(48) NOT NULL DEFAULT '',
  width_cm int(10) unsigned NOT NULL DEFAULT 0,
  height_cm int(10) unsigned NOT NULL DEFAULT 0,
  orientation varchar(12) NOT NULL DEFAULT '',
  lit tinyint(1) NOT NULL DEFAULT 0,
  city varchar(64) NOT NULL DEFAULT '',
  street varchar(160) NOT NULL DEFAULT '',
  neighborhood varchar(120) NOT NULL DEFAULT '',
  lat decimal(9,6) NULL,
  lng decimal(9,6) NULL,
  monthly_price_rials bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'draft',
  notes text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY city_status (city, status)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_media_slots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  media_id bigint(20) unsigned NOT NULL,
  fiscal_year smallint(5) unsigned NOT NULL,
  month tinyint(3) unsigned NOT NULL,
  state varchar(10) NOT NULL DEFAULT 'free',
  holder bigint(20) unsigned NOT NULL DEFAULT 0,
  locked_until int(10) unsigned NOT NULL DEFAULT 0,
  reserved_until int(10) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  brand varchar(160) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY slot (media_id, fiscal_year, month),
  KEY invoice_id (invoice_id),
  KEY state (state)
) {$c};");

        dbDelta("CREATE TABLE {$p}crc_media_waitlist (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  media_id bigint(20) unsigned NOT NULL,
  fiscal_year smallint(5) unsigned NOT NULL,
  month tinyint(3) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created_at int(10) unsigned NOT NULL,
  notified_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY entry (media_id, fiscal_year, month, user_id)
) {$c};");

        update_option(self::OPTION, self::DB_VERSION, false);
    }
}
