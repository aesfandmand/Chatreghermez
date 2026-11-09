<?php
/**
 * Plugin Name:       Chatreghermez Commerce
 * Description:       هستهٔ تجارت یکپارچهٔ چتر قرمز: کاتالوگ، سبد، سفارش، فاکتور، پرداخت و نقش‌ها (بدون ووکامرس).
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Red Umbrella
 * Text Domain:       chatreghermez-commerce
 * License:           Proprietary
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('CRC_COMMERCE_VERSION', '0.1.0');
define('CRC_COMMERCE_FILE', __FILE__);
define('CRC_COMMERCE_DIR', plugin_dir_path(__FILE__));

// Self-contained PSR-4 autoloader so the plugin runs without Composer on the host.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Chatreghermez\\Commerce\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = CRC_COMMERCE_DIR . 'src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [\Chatreghermez\Commerce\Plugin::class, 'activate']);
add_action('plugins_loaded', [\Chatreghermez\Commerce\Plugin::class, 'boot']);
