<?php
/**
 * Plugin Name:       Chatreghermez Media Rental
 * Description:       اجارهٔ رسانه‌های محیطی چتر قرمز: رسانه‌ها، تقویم سال مالی، قفل و رزرو. وابسته به هستهٔ Chatreghermez Commerce.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Red Umbrella
 * Text Domain:       chatreghermez-media-rental
 * License:           Proprietary
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('CRC_MEDIA_VERSION', '0.1.0');
define('CRC_MEDIA_DIR', plugin_dir_path(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Chatreghermez\\MediaRental\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = CRC_MEDIA_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [\Chatreghermez\MediaRental\Plugin::class, 'activate']);
add_action('plugins_loaded', [\Chatreghermez\MediaRental\Plugin::class, 'boot'], 20); // after the commerce core (priority 10)
