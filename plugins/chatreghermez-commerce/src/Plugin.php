<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Access\RoleManager;
use Chatreghermez\Commerce\Admin\DiscountsPage;
use Chatreghermez\Commerce\Admin\ProductsPage;
use Chatreghermez\Commerce\Admin\RolesPage;
use Chatreghermez\Commerce\Database\Migrator;
use Chatreghermez\Commerce\Rest\AccountController;
use Chatreghermez\Commerce\Rest\BillingController;
use Chatreghermez\Commerce\Rest\CatalogController;
use Chatreghermez\Commerce\Rest\OrdersController;
use Chatreghermez\Commerce\Rest\RolesController;
use Chatreghermez\Commerce\Sms\SmsService;

final class Plugin
{
    public static function activate(): void
    {
        Migrator::run();
        RoleManager::install();
    }

    public static function boot(): void
    {
        // Self-heal after updates without reactivation.
        Migrator::run();

        // The WP administrator can never be locked out of any plugin capability.
        add_filter('user_has_cap', static function (array $allcaps, array $caps, array $args, $user): array {
            if ($user instanceof \WP_User && in_array('administrator', (array) $user->roles, true)) {
                foreach (Capabilities::all() as $cap) {
                    $allcaps[$cap] = true;
                }
            }
            return $allcaps;
        }, 10, 4);

        add_action('rest_api_init', [RolesController::class, 'register']);
        add_action('rest_api_init', [CatalogController::class, 'register']);
        add_action('rest_api_init', [OrdersController::class, 'register']);
        add_action('rest_api_init', [BillingController::class, 'register']);
        add_action('rest_api_init', [AccountController::class, 'register']);
        add_action('crc_event_order.created', [BillingController::class, 'onOrderCreated']);
        add_action('crc_event', [SmsService::class, 'onEvent'], 10, 2);
        add_action('crc_sms_retry', [SmsService::class, 'deliver']);
        if (is_admin()) {
            RolesPage::register();
            ProductsPage::register();
            DiscountsPage::register();
        }
    }
}
