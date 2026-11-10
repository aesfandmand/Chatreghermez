<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Rest;

use Chatreghermez\Commerce\Account\OtpService;

/**
 *   POST /auth/otp/request {mobile}        POST /auth/otp/verify {mobile, code} -> session cookie + REST nonce
 *   GET  /me   profile                      POST /me  {display_name, company, trade, city, persona}
 */
final class AccountController
{
    private const PROFILE = ['company', 'trade', 'city', 'persona'];

    public static function register(): void
    {
        $ns = RolesController::NS;
        register_rest_route($ns, '/auth/otp/request', [['methods' => 'POST', 'callback' => [self::class, 'otpRequest'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/auth/otp/verify', [['methods' => 'POST', 'callback' => [self::class, 'otpVerify'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/me', [
            ['methods' => 'GET', 'callback' => [self::class, 'me'], 'permission_callback' => static fn (): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [self::class, 'update'], 'permission_callback' => static fn (): bool => is_user_logged_in()],
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function otpRequest(\WP_REST_Request $r)
    {
        $b  = (array) $r->get_json_params();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'x';
        $res = OtpService::request((string) ($b['mobile'] ?? ''), $ip);
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response(['sent' => true, 'resend_after' => 60]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function otpVerify(\WP_REST_Request $r)
    {
        $b = (array) $r->get_json_params();
        $res = OtpService::verify((string) ($b['mobile'] ?? ''), (string) ($b['code'] ?? ''));
        return $res instanceof \WP_Error ? $res : new \WP_REST_Response($res);
    }

    public static function me(): \WP_REST_Response
    {
        $u = wp_get_current_user();
        $out = ['id' => $u->ID, 'display_name' => $u->display_name, 'mobile' => (string) get_user_meta($u->ID, 'crc_mobile', true)];
        foreach (self::PROFILE as $k) {
            $out[$k] = (string) get_user_meta($u->ID, 'crc_' . $k, true);
        }
        return new \WP_REST_Response($out);
    }

    public static function update(\WP_REST_Request $r): \WP_REST_Response
    {
        $b = (array) $r->get_json_params();
        $uid = get_current_user_id();
        if (isset($b['display_name'])) {
            wp_update_user(['ID' => $uid, 'display_name' => sanitize_text_field((string) $b['display_name'])]);
        }
        foreach (self::PROFILE as $k) {
            if (isset($b[$k])) {
                update_user_meta($uid, 'crc_' . $k, sanitize_text_field((string) $b[$k]));
            }
        }
        return self::me();
    }
}
