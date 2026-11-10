<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Admin;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Audit\AuditLog;
use Chatreghermez\Commerce\Catalog\ProductRepository;
use Chatreghermez\Commerce\Catalog\ProductValidator;

/** Product list + edit form in the WP dashboard. Prices are typed in Toman and stored in Rials. */
final class ProductsPage
{
    private const SLUG = 'crc-products';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_post_crc_save_product', [self::class, 'save']);
    }

    public static function menu(): void
    {
        add_submenu_page('crc', 'محصولات', 'محصولات', Capabilities::CATALOG_VIEW, self::SLUG, [self::class, 'render']);
    }

    private static function url(array $args = []): string
    {
        return add_query_arg(['page' => self::SLUG] + $args, admin_url('admin.php'));
    }

    public static function render(): void
    {
        if (!current_user_can(Capabilities::CATALOG_VIEW)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        echo '<div class="wrap" dir="rtl">';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>محصول ذخیره شد.</p></div>';
        }
        if (isset($_GET['edit'])) {
            self::form((int) $_GET['edit']);
        } else {
            self::listing();
        }
        echo '</div>';
    }

    private static function listing(): void
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results("SELECT id, name, slug, status, price_model, base_price_rials FROM {$wpdb->prefix}crc_products ORDER BY id DESC LIMIT 200", ARRAY_A);
        echo '<h1>محصولات';
        if (current_user_can(Capabilities::CATALOG_EDIT)) {
            echo ' <a class="page-title-action" href="' . esc_url(self::url(['edit' => 0])) . '">افزودن محصول</a>';
        }
        echo '</h1><table class="widefat striped"><thead><tr><th>نام</th><th>نشانی کوتاه</th><th>مدل قیمت</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r['name']) . '</td><td>' . esc_html($r['slug']) . '</td><td>' . esc_html($r['price_model'])
                . '</td><td>' . ($r['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس') . '</td><td>'
                . '<a href="' . esc_url(self::url(['edit' => (int) $r['id']])) . '">ویرایش</a></td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="5">هنوز محصولی ثبت نشده است.</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function form(int $id): void
    {
        $canEdit = current_user_can(Capabilities::CATALOG_EDIT);
        $p = $id > 0 ? ProductRepository::findById($id, false) : null;
        $p ??= ['name' => '', 'slug' => '', 'type' => 'simple', 'status' => 'draft', 'price_model' => 'fixed', 'category' => '', 'summary' => '', 'description' => '',
            'seo_title' => '', 'seo_description' => '', 'max_qty' => 100000, 'min_dim_cm' => 1, 'max_dim_cm' => 100000];
        $f = ProductFormParser::format($p) + [];
        $err = get_transient('crc_product_errors_' . get_current_user_id());
        delete_transient('crc_product_errors_' . get_current_user_id());

        echo '<h1>' . ($id > 0 ? 'ویرایش محصول' : 'محصول جدید') . '</h1>';
        if (is_array($err) && $err) {
            echo '<div class="notice notice-error"><ul>';
            foreach ($err as $m) {
                echo '<li>' . esc_html($m) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crc_save_product');
        echo '<input type="hidden" name="action" value="crc_save_product"><input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<table class="form-table">';
        self::text('name', 'نام محصول', $p['name']);
        self::text('slug', 'نشانی کوتاه (انگلیسی کوچک و خط تیره)', $p['slug']);
        self::select('type', 'نوع محصول', $p['type'], ['simple' => 'ساده', 'options' => 'با گزینه', 'design' => 'خدمت طراحی', 'inquiry' => 'استعلامی']);
        self::select('price_model', 'مدل قیمت', $p['price_model'], ['fixed' => 'ثابت', 'tiered' => 'پله‌ای (بر اساس تعداد)', 'area' => 'متراژی', 'quote' => 'استعلام']);
        self::select('status', 'وضعیت', $p['status'], ['draft' => 'پیش‌نویس', 'published' => 'منتشرشده']);
        self::text('category', 'دسته', $p['category']);
        self::text('base_price', 'قیمت پایه (تومان) — مدل ثابت', $f['base_price']);
        self::text('area_rate', 'نرخ هر متر مربع (تومان) — مدل متراژی', $f['area_rate']);
        self::text('min_charge', 'حداقل مبلغ (تومان) — مدل متراژی', $f['min_charge']);
        self::text('min_dim_cm', 'حداقل بُعد (سانتی‌متر)', (string) $p['min_dim_cm']);
        self::text('max_dim_cm', 'حداکثر بُعد (سانتی‌متر)', (string) $p['max_dim_cm']);
        self::text('max_qty', 'حداکثر تعداد در هر سفارش', (string) $p['max_qty']);
        self::area('tiers', "پله‌های قیمت — هر خط: تعداد | قیمت واحد (تومان)", $f['tiers'], 4);
        self::area('options', "گزینه‌ها — خط عنوان (آخرش * اگر اجباری) و زیرش خط‌های «- برچسب | افزوده (تومان) | ضریب درصد»", $f['options'], 8);
        self::area('summary', 'خلاصه (برای نتایج جست‌وجو و ترب)', $p['summary'], 3);
        self::area('description', 'توضیحات', $p['description'], 6);
        self::text('seo_title', 'عنوان سئو', $p['seo_title']);
        self::text('seo_description', 'توضیح سئو', $p['seo_description']);
        echo '</table>';
        if ($canEdit) {
            submit_button('ذخیرهٔ محصول');
        }
        echo '</form>';
    }

    private static function text(string $name, string $label, string $value): void
    {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($name)
            . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    private static function area(string $name, string $label, string $value, int $rows): void
    {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><textarea class="large-text" rows="' . $rows . '" id="' . esc_attr($name)
            . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></td></tr>';
    }

    /** @param array<string,string> $opts */
    private static function select(string $name, string $label, string $value, array $opts): void
    {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        foreach ($opts as $k => $l) {
            echo '<option value="' . esc_attr($k) . '"' . selected($value, $k, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select></td></tr>';
    }

    public static function save(): void
    {
        if (!current_user_can(Capabilities::CATALOG_EDIT)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-commerce'), 403);
        }
        check_admin_referer('crc_save_product');
        $id   = (int) ($_POST['id'] ?? 0);
        $data = ProductFormParser::parse(wp_unslash($_POST));
        $errors = ProductValidator::validate($data);
        if ($errors !== []) {
            set_transient('crc_product_errors_' . get_current_user_id(), array_values($errors), 60);
            wp_safe_redirect(self::url(['edit' => $id]));
            exit;
        }
        $res = ProductRepository::save($data, $id > 0 ? $id : null);
        if ($res instanceof \WP_Error) {
            set_transient('crc_product_errors_' . get_current_user_id(), [$res->get_error_message() . ' (نشانی کوتاه تکراری نباشد.)'], 60);
            wp_safe_redirect(self::url(['edit' => $id]));
            exit;
        }
        AuditLog::record(get_current_user_id(), $id > 0 ? 'catalog.product_updated' : 'catalog.product_created', 'product', (string) $res);
        wp_safe_redirect(self::url(['saved' => 1]));
        exit;
    }
}
