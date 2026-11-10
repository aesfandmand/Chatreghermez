<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Account;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Sms\PhoneNormalizer;
use Chatreghermez\Commerce\Sms\SmsService;
use Chatreghermez\Commerce\Support\Events;
use Chatreghermez\Commerce\Support\RateLimiter;

final class OtpService
{
    /** @return true|\WP_Error */
    public static function request(string $rawMobile, string $ip)
    {
        global $wpdb;
        $mobile = PhoneNormalizer::normalize($rawMobile);
        if ($mobile === null) {
            return new \WP_Error('crc_bad_mobile', 'شمارهٔ موبایل نامعتبر است.', ['status' => 422]);
        }
        $rl = RateLimiter::wordpress();
        if (!$rl->allow('otp_ip:' . $ip, 10, 600) || !$rl->allow('otp_mobile:' . $mobile, 5, 3600)) {
            return new \WP_Error('crc_rate_limited', 'درخواست‌های زیاد؛ کمی بعد دوباره تلاش کنید.', ['status' => 429]);
        }
        $t = $wpdb->prefix . 'crc_otp';
        $row = $wpdb->get_row($wpdb->prepare("SELECT created_at FROM {$t} WHERE mobile = %s", $mobile), ARRAY_A);
        if ($row && !OtpPolicy::canResend((int) $row['created_at'], time())) {
            return new \WP_Error('crc_too_soon', 'تا ارسال دوبارهٔ کد کمی صبر کنید.', ['status' => 429]);
        }
        $code = OtpPolicy::generate();
        $wpdb->replace($t, ['mobile' => $mobile, 'code_hash' => OtpPolicy::hash($mobile, $code, wp_salt('auth')), 'created_at' => time(), 'attempts' => 0]);
        SmsService::queue($mobile, 'otp', ['code' => $code]);
        return true;
    }

    /** Verifies the code, creates the customer on first login, starts the session. @return array<string,mixed>|\WP_Error */
    public static function verify(string $rawMobile, string $code)
    {
        global $wpdb;
        $mobile = PhoneNormalizer::normalize($rawMobile);
        $t = $wpdb->prefix . 'crc_otp';
        $row = $mobile ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE mobile = %s", $mobile), ARRAY_A) : null;
        $bad = new \WP_Error('crc_otp_invalid', 'کد نامعتبر یا منقضی است.', ['status' => 401]);
        if (!$row || OtpPolicy::expired((int) $row['created_at'], time()) || (int) $row['attempts'] >= OtpPolicy::MAX_ATTEMPTS) {
            return $bad;
        }
        if (!OtpPolicy::verify($mobile, preg_replace('/\D/', '', $code) ?? '', (string) $row['code_hash'], wp_salt('auth'))) {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET attempts = attempts + 1 WHERE mobile = %s", $mobile));
            return $bad;
        }
        $wpdb->delete($t, ['mobile' => $mobile]); // single use

        $users = get_users(['meta_key' => 'crc_mobile', 'meta_value' => $mobile, 'number' => 1, 'fields' => 'ID']);
        $isNew = $users === [];
        if ($isNew) {
            $uid = wp_insert_user(['user_login' => 'm' . $mobile, 'user_pass' => wp_generate_password(32), 'role' => Capabilities::ROLE_CUSTOMER]);
            if (is_wp_error($uid)) {
                return new \WP_Error('crc_user_failed', 'ساخت حساب انجام نشد.', ['status' => 500]);
            }
            update_user_meta($uid, 'crc_mobile', $mobile);
            Events::dispatch('customer.registered', ['customer_id' => (int) $uid]);
        } else {
            $uid = (int) $users[0];
        }
        wp_set_current_user((int) $uid);
        wp_set_auth_cookie((int) $uid, true);
        return ['user_id' => (int) $uid, 'is_new' => $isNew, 'nonce' => wp_create_nonce('wp_rest')];
    }
}
