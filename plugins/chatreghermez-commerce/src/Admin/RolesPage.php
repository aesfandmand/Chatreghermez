<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Admin;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Access\RoleManager;

/** Role × capability matrix. Viewable with crc_roles_view, editable with crc_roles_manage. */
final class RolesPage
{
    private const SLUG = 'crc-roles';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_crc_save_matrix', [self::class, 'save']);
    }

    public static function menu(): void
    {
        add_menu_page('چتر قرمز', 'چتر قرمز', Capabilities::ROLES_VIEW, 'crc', [self::class, 'render'], 'dashicons-store', 58);
        add_submenu_page('crc', 'دسترسی نقش‌ها', 'دسترسی نقش‌ها', Capabilities::ROLES_VIEW, self::SLUG, [self::class, 'render']);
        remove_submenu_page('crc', 'crc');
    }

    public static function render(): void
    {
        if (!current_user_can(Capabilities::ROLES_VIEW)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        $canEdit  = current_user_can(Capabilities::ROLES_MANAGE);
        $roles    = Capabilities::managedRoles();
        $registry = Capabilities::registry();
        $matrix   = RoleManager::matrix();

        echo '<div class="wrap" dir="rtl"><h1>دسترسی نقش‌ها</h1>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>تغییرات ذخیره شد.</p></div>';
        }
        echo '<p>ادمین سایت همیشه همهٔ مجوزها را دارد. هر تغییر در گزارش تغییرات ثبت می‌شود.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crc_save_matrix');
        echo '<input type="hidden" name="action" value="crc_save_matrix">';
        echo '<table class="widefat striped"><thead><tr><th>گروه</th><th>مجوز</th>';
        foreach ($roles as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($registry as $cap => $meta) {
            echo '<tr><td>' . esc_html($meta['group']) . '</td><td>' . esc_html($meta['label']) . '</td>';
            foreach (array_keys($roles) as $slug) {
                $name = 'm[' . esc_attr($slug) . '][' . esc_attr($cap) . ']';
                echo '<td><input type="checkbox" name="' . $name . '" value="1" '
                    . checked(!empty($matrix[$slug][$cap]), true, false)
                    . ($canEdit ? '' : ' disabled') . '></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        if ($canEdit) {
            submit_button('ذخیرهٔ دسترسی‌ها');
        }
        echo '</form></div>';
    }

    public static function save(): void
    {
        if (!current_user_can(Capabilities::ROLES_MANAGE)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        check_admin_referer('crc_save_matrix');
        $posted = isset($_POST['m']) && is_array($_POST['m']) ? wp_unslash($_POST['m']) : [];
        $actor  = get_current_user_id();
        foreach (array_keys(Capabilities::managedRoles()) as $slug) {
            foreach (Capabilities::all() as $cap) {
                $grant = isset($posted[$slug][$cap]);
                RoleManager::setCapability($slug, $cap, $grant, $actor);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('admin.php')));
        exit;
    }
}
