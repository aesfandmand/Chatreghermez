<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Admin;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Audit\AuditLog;

/** Discount codes: list, create, enable/disable. Amounts typed in Toman (fixed) or percent. */
final class DiscountsPage
{
    private const SLUG = 'crc-discounts';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_post_crc_save_discount', [self::class, 'save']);
        add_action('admin_post_crc_toggle_discount', [self::class, 'toggle']);
    }

    public static function menu(): void
    {
        add_submenu_page('crc', 'کدهای تخفیف', 'کدهای تخفیف', Capabilities::DISCOUNTS_MANAGE, self::SLUG, [self::class, 'render']);
    }

    public static function render(): void
    {
        global $wpdb;
        if (!current_user_can(Capabilities::DISCOUNTS_MANAGE)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        $rows = (array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}crc_discount_codes ORDER BY id DESC LIMIT 200", ARRAY_A);
        echo '<div class="wrap" dir="rtl"><h1>کدهای تخفیف</h1>';
        if (isset($_GET['err'])) {
            echo '<div class="notice notice-error"><p>اطلاعات کد معتبر نیست یا کد تکراری است.</p></div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>کد</th><th>نوع</th><th>مقدار</th><th>مصرف</th><th>حداقل سفارش (تومان)</th><th>اعتبار</th><th>فعال</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $val = $r['type'] === 'percent' ? ((int) $r['value'] / 100) . '٪' : number_format(intdiv((int) $r['value'], 10)) . ' تومان';
            $uses = (int) $r['used_count'] . ((int) $r['max_uses'] ? ' / ' . (int) $r['max_uses'] : '');
            $url = wp_nonce_url(admin_url('admin-post.php?action=crc_toggle_discount&id=' . (int) $r['id']), 'crc_toggle_discount');
            echo '<tr><td><code>' . esc_html($r['code']) . '</code></td><td>' . ($r['type'] === 'percent' ? 'درصدی' : 'مبلغ ثابت') . '</td><td>' . esc_html($val)
                . '</td><td>' . esc_html($uses) . '</td><td>' . esc_html(number_format(intdiv((int) $r['min_order_rials'], 10))) . '</td><td>'
                . esc_html(($r['starts_at'] ?: '—') . ' تا ' . ($r['ends_at'] ?: '—')) . '</td><td>' . ($r['active'] ? 'بله' : 'خیر')
                . '</td><td><a href="' . esc_url($url) . '">' . ($r['active'] ? 'غیرفعال' : 'فعال') . '</a></td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="8">کدی ثبت نشده است.</td></tr>';
        }
        echo '</tbody></table><h2>کد جدید</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crc_save_discount');
        echo '<input type="hidden" name="action" value="crc_save_discount"><table class="form-table">';
        echo '<tr><th>کد (حروف انگلیسی/عدد)</th><td><input name="code" class="regular-text" required></td></tr>';
        echo '<tr><th>نوع</th><td><select name="type"><option value="percent">درصدی</option><option value="fixed">مبلغ ثابت</option></select></td></tr>';
        echo '<tr><th>مقدار (درصد، یا تومان برای مبلغ ثابت)</th><td><input name="value" class="regular-text" required></td></tr>';
        echo '<tr><th>حداقل مبلغ سفارش (تومان)</th><td><input name="min_order" class="regular-text" value="0"></td></tr>';
        echo '<tr><th>حداکثر دفعات مصرف (۰ = نامحدود)</th><td><input name="max_uses" class="regular-text" value="0"></td></tr>';
        echo '<tr><th>حداکثر مصرف هر مشتری (۰ = نامحدود)</th><td><input name="per_customer" class="regular-text" value="0"></td></tr>';
        echo '<tr><th>شروع (YYYY-MM-DD میلادی، اختیاری)</th><td><input name="starts" class="regular-text"></td></tr>';
        echo '<tr><th>پایان (YYYY-MM-DD میلادی، اختیاری)</th><td><input name="ends" class="regular-text"></td></tr>';
        echo '</table>';
        submit_button('ساخت کد');
        echo '</form></div>';
    }

    /** @return array<string,mixed>|null */
    public static function parse(array $in): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['code'] ?? '')) ?? '');
        $type = ($in['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $num  = static fn ($v): float => (float) ProductFormParser::digits((string) $v);
        $value = $type === 'percent' ? (int) round($num($in['value'] ?? 0) * 100) : (int) round($num($in['value'] ?? 0) * 10);
        if ($code === '' || $value <= 0 || ($type === 'percent' && $value > 10000)) {
            return null;
        }
        $date = static fn ($v): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v . ' 00:00:00' : null;
        return [
            'code' => $code, 'type' => $type, 'value' => $value, 'scope_type' => 'all', 'scope_json' => '[]',
            'min_order_rials' => (int) round($num($in['min_order'] ?? 0) * 10),
            'max_uses' => max(0, (int) $num($in['max_uses'] ?? 0)), 'per_customer' => max(0, (int) $num($in['per_customer'] ?? 0)),
            'starts_at' => $date($in['starts'] ?? ''), 'ends_at' => $date($in['ends'] ?? ''), 'active' => 1,
        ];
    }

    public static function save(): void
    {
        global $wpdb;
        if (!current_user_can(Capabilities::DISCOUNTS_MANAGE)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        check_admin_referer('crc_save_discount');
        $row = self::parse(wp_unslash($_POST));
        $ok = $row !== null && $wpdb->insert($wpdb->prefix . 'crc_discount_codes', $row) !== false;
        if ($ok) {
            AuditLog::record(get_current_user_id(), 'discounts.created', 'discount', (string) $wpdb->insert_id, null, ['code' => $row['code']]);
        }
        wp_safe_redirect(add_query_arg($ok ? ['page' => self::SLUG] : ['page' => self::SLUG, 'err' => 1], admin_url('admin.php')));
        exit;
    }

    public static function toggle(): void
    {
        global $wpdb;
        if (!current_user_can(Capabilities::DISCOUNTS_MANAGE)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        check_admin_referer('crc_toggle_discount');
        $id = (int) ($_GET['id'] ?? 0);
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}crc_discount_codes SET active = 1 - active WHERE id = %d", $id));
        AuditLog::record(get_current_user_id(), 'discounts.toggled', 'discount', (string) $id);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')));
        exit;
    }
}
