<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Sms;

/**
 * Outbox-based sending: enqueue -> send (immediately, retried by cron up to 3 times). Provider is chosen in settings
 * (option crc_sms_provider) and resolved through the `crc_sms_provider_instance` filter, so vendors are plug-ins.
 * Default template texts are PLACEHOLDERS; final wording belongs to the content assistant under the project's writing law.
 */
final class SmsService
{
    public const DEFAULT_TEMPLATES = [
        'otp'              => 'کد ورود شما: {code}',
        'order_created'    => 'سفارش {order_no} ثبت شد.',
        'payment_received' => 'پرداخت سفارش {order_no} دریافت شد.',
        'status_changed'   => 'وضعیت سفارش {order_no}: {status}',
    ];

    public static function provider(): ?SmsProvider
    {
        $p = apply_filters('crc_sms_provider_instance', null, (string) get_option('crc_sms_provider', ''));
        return $p instanceof SmsProvider ? $p : null;
    }

    /** @param array<string,scalar> $vars */
    public static function queue(string $mobile, string $template, array $vars, bool $transactional = true): void
    {
        global $wpdb;
        $mobile = PhoneNormalizer::normalize($mobile);
        if ($mobile === null) {
            return;
        }
        if (!$transactional && get_option('crc_sms_optout_' . md5($mobile))) {
            return; // marketing messages respect opt-out; transactional ones (OTP, order status) always go
        }
        $templates = (array) get_option('crc_sms_templates', []) + (array) apply_filters('crc_sms_default_templates', self::DEFAULT_TEMPLATES);
        $body = TemplateRenderer::render((string) ($templates[$template] ?? ''), $vars);
        if ($body === '') {
            return;
        }
        $wpdb->insert($wpdb->prefix . 'crc_sms_outbox', [
            'mobile' => $mobile, 'template' => $template, 'body' => $body, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $wpdb->insert_id;
        if (!self::deliver($id)) {
            wp_schedule_single_event(time() + 120, 'crc_sms_retry', [$id]);
        }
    }

    public static function deliver(int $id): bool
    {
        global $wpdb;
        $t = $wpdb->prefix . 'crc_sms_outbox';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d AND status <> 'sent'", $id), ARRAY_A);
        $provider = self::provider();
        if (!$row || $provider === null) {
            return false;
        }
        $ok = false;
        try {
            $ok = $provider->send((string) $row['mobile'], (string) $row['body']);
        } catch (\Throwable $e) {
            $ok = false;
        }
        $attempts = (int) $row['attempts'] + 1;
        $wpdb->update($t, [
            'attempts' => $attempts, 'provider' => $provider->id(),
            'status' => $ok ? 'sent' : ($attempts >= 3 ? 'failed' : 'queued'),
            'sent_at' => $ok ? gmdate('Y-m-d H:i:s') : null,
        ], ['id' => $id]);
        if (!$ok && $attempts < 3) {
            wp_schedule_single_event(time() + 300 * $attempts, 'crc_sms_retry', [$id]);
        }
        return $ok;
    }

    /** Domain-event listener: order/payment/status messages to the customer. */
    public static function onEvent(string $name, array $payload): void
    {
        $map = ['order.created' => 'order_created', 'payment.confirmed' => 'payment_received', 'order.status_changed' => 'status_changed'];
        if (!isset($map[$name]) || empty($payload['customer_id'])) {
            return;
        }
        $user = get_userdata((int) $payload['customer_id']);
        $mobile = $user ? (string) get_user_meta($user->ID, 'crc_mobile', true) : '';
        if ($mobile === '') {
            return;
        }
        $orderNo = '';
        if (!empty($payload['order_id'])) {
            global $wpdb;
            $orderNo = (string) $wpdb->get_var($wpdb->prepare("SELECT order_no FROM {$wpdb->prefix}crc_orders WHERE id = %d", (int) $payload['order_id']));
        }
        $labels = ['paid' => 'پرداخت‌شده', 'in_progress' => 'در حال انجام', 'delivered' => 'تحویل‌شده', 'cancelled' => 'لغو‌شده', 'partially_paid' => 'پرداخت بخشی', 'expired' => 'منقضی'];
        self::queue($mobile, $map[$name], ['order_no' => $orderNo, 'status' => $labels[$payload['to'] ?? ''] ?? ''], true);
    }
}
