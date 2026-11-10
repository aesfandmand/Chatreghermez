<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Admin;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\MediaRental\Import\MediaImporter;
use Chatreghermez\MediaRental\Import\SheetImporter;
use Chatreghermez\MediaRental\Import\XlsxReader;

/** Upload an availability sheet -> preview (issues, counts) -> confirm. Nothing is written until the admin confirms. */
final class ImportPage
{
    private const SLUG = 'crc-media-import';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_crc_media_preview', [self::class, 'preview']);
        add_action('admin_post_crc_media_commit', [self::class, 'commit']);
    }

    public static function menu(): void
    {
        add_submenu_page('crc', 'ورود رسانه‌ها از اکسل', 'ورود رسانه‌ها از اکسل', Capabilities::MEDIA_EDIT, self::SLUG, [self::class, 'render']);
    }

    private static function url(array $a = []): string
    {
        return add_query_arg(['page' => self::SLUG] + $a, admin_url('admin.php'));
    }

    public static function render(): void
    {
        if (!current_user_can(Capabilities::MEDIA_EDIT)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-media-rental'), 403);
        }
        echo '<div class="wrap" dir="rtl"><h1>ورود رسانه‌ها از اکسل</h1>';
        if (isset($_GET['done'])) {
            echo '<div class="notice notice-success"><p>ایجاد شد: ' . (int) $_GET['done'] . ' — ردشده (از قبل موجود): ' . (int) ($_GET['skipped'] ?? 0) . '. رسانه‌های تازه پیش‌نویس‌اند و قیمت ماهانه ندارند.</p></div>';
        }
        if (isset($_GET['err'])) {
            echo '<div class="notice notice-error"><p>فایل خوانده نشد یا ساختار آن شناخته نشد.</p></div>';
        }
        $token = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['t']) : '';
        $a = $token ? get_transient('crc_media_import_' . $token) : null;
        if (is_array($a)) {
            self::previewTable($a, $token);
        } else {
            echo '<p>فایل .xlsx با ستون‌های «کد سیستم»، «مکان» و ۱۲ ماه (اردیبهشت تا فروردین با سال) را بارگذاری کنید. ابتدا فقط پیش‌نمایش نشان داده می‌شود.</p>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('crc_media_preview');
            echo '<input type="hidden" name="action" value="crc_media_preview"><input type="file" name="sheet" accept=".xlsx" required> ';
            submit_button('پیش‌نمایش', 'primary', 'submit', false);
            echo '</form>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $a */
    private static function previewTable(array $a, string $token): void
    {
        $n = count($a['items']);
        echo '<p>سال مالی <b>' . (int) $a['fiscal_year'] . '</b> — رسانهٔ قابل ورود: <b>' . (int) $n . '</b></p>';
        if ($a['issues']) {
            echo '<div class="notice notice-warning"><p>موارد نیازمند توجه:</p><ul>';
            foreach ($a['issues'] as $i) {
                echo '<li>' . ($i['row'] ? 'ردیف ' . (int) $i['row'] . ': ' : '') . esc_html($i['message']) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>ردیف</th><th>کد</th><th>مکان</th><th>نوع</th><th>اندازه (م)</th><th>ماه‌های پر</th><th>بسته</th></tr></thead><tbody>';
        foreach ($a['items'] as $it) {
            $res = count(array_filter($it['months'], static fn (array $c): bool => $c['status'] === 'reserved'));
            $blk = count(array_filter($it['months'], static fn (array $c): bool => $c['status'] === 'blocked'));
            $size = $it['width_m'] !== null ? $it['width_m'] . '×' . $it['height_m'] : '—';
            echo '<tr><td>' . (int) $it['row'] . '</td><td>' . esc_html($it['code']) . '</td><td>' . esc_html($it['location']) . '</td><td>' . esc_html($it['type'] ?: '—')
                . '</td><td>' . esc_html($size) . '</td><td>' . $res . '</td><td>' . $blk . '</td></tr>';
        }
        echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">';
        wp_nonce_field('crc_media_commit');
        echo '<input type="hidden" name="action" value="crc_media_commit"><input type="hidden" name="t" value="' . esc_attr($token) . '">';
        echo '<label>شهر/پروژهٔ این فایل: <input name="city" required class="regular-text"></label> ';
        submit_button('تأیید و ورود', 'primary', 'submit', false);
        echo ' <a href="' . esc_url(self::url()) . '">انصراف</a></form>';
    }

    public static function preview(): void
    {
        if (!current_user_can(Capabilities::MEDIA_EDIT)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-media-rental'), 403);
        }
        check_admin_referer('crc_media_preview');
        $file = $_FILES['sheet']['tmp_name'] ?? '';
        $ok = is_string($file) && $file !== '' && is_uploaded_file($file) && strtolower(pathinfo((string) ($_FILES['sheet']['name'] ?? ''), PATHINFO_EXTENSION)) === 'xlsx';
        try {
            $a = $ok ? SheetImporter::analyze(XlsxReader::read($file)) : null;
        } catch (\Throwable $e) {
            $a = null;
        }
        if (!$a || $a['items'] === []) {
            wp_safe_redirect(self::url(['err' => 1]));
            exit;
        }
        $token = bin2hex(random_bytes(8));
        set_transient('crc_media_import_' . $token, $a, 1800);
        wp_safe_redirect(self::url(['t' => $token]));
        exit;
    }

    public static function commit(): void
    {
        if (!current_user_can(Capabilities::MEDIA_EDIT)) {
            wp_die(esc_html__('دسترسی ندارید.', 'chatreghermez-media-rental'), 403);
        }
        check_admin_referer('crc_media_commit');
        $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['t'] ?? ''));
        $a = get_transient('crc_media_import_' . $token);
        $city = sanitize_text_field(wp_unslash((string) ($_POST['city'] ?? '')));
        if (!is_array($a) || $city === '') {
            wp_safe_redirect(self::url(['err' => 1]));
            exit;
        }
        $r = MediaImporter::commit($a, $city, get_current_user_id());
        delete_transient('crc_media_import_' . $token);
        wp_safe_redirect(self::url(['done' => $r['created'], 'skipped' => $r['skipped']]));
        exit;
    }
}
