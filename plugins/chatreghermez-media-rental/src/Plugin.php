<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental;

use Chatreghermez\MediaRental\Admin\ImportPage;
use Chatreghermez\MediaRental\Database\Migrator;
use Chatreghermez\MediaRental\Media\RentalLine;
use Chatreghermez\MediaRental\Media\SlotService;
use Chatreghermez\MediaRental\Rest\MediaController;

final class Plugin
{
    public static function activate(): void
    {
        if (class_exists(\Chatreghermez\Commerce\Plugin::class)) {
            Migrator::run();
        }
    }

    public static function boot(): void
    {
        if (!class_exists(\Chatreghermez\Commerce\Plugin::class)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>افزونهٔ اجارهٔ رسانه به «Chatreghermez Commerce» نیاز دارد؛ ابتدا آن را فعال کنید.</p></div>';
            });
            return;
        }
        Migrator::run();
        RentalLine::register();
        if (is_admin()) {
            ImportPage::register();
        }
        add_action('rest_api_init', [MediaController::class, 'register']);
        add_action('crc_event_payment.confirmed', [SlotService::class, 'onPayment']);
        add_action('crc_event_payment.started', [SlotService::class, 'onPaymentStarted']);
        add_filter('crc_sms_default_templates', static function (array $t): array {
            // PLACEHOLDER wording; final text is written by the content assistant under the writing law.
            return $t + ['reservation_reminder' => 'یادآوری: رزرو فاکتور {invoice_no} حدود {days} روز دیگر پایان می‌یابد. لغو یا تمدید را مشخص کنید.'];
        });
        add_action('crc_media_send_reminders', [SlotService::class, 'sendReminders']);
        if (!wp_next_scheduled('crc_media_send_reminders')) {
            wp_schedule_event(time() + 120, 'hourly', 'crc_media_send_reminders');
        }
        add_action('crc_event_reservation.extended', [SlotService::class, 'onExtended']);
        add_action('crc_media_release_expired', [SlotService::class, 'releaseExpired']);
        if (!wp_next_scheduled('crc_media_release_expired')) {
            wp_schedule_event(time() + 60, 'hourly', 'crc_media_release_expired');
        }
    }
}
