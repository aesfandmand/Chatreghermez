<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Rest;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Orders\CartRepository;
use Chatreghermez\Commerce\Orders\LineTypes;
use Chatreghermez\Commerce\Rest\RolesController;
use Chatreghermez\MediaRental\Domain\FiscalCalendar;
use Chatreghermez\MediaRental\Media\SlotService;

/**
 *   GET  /media                      public list, filters: city, street, neighborhood, type, lit, orientation, q
 *   GET  /media/{id}/calendar?year=  12 months with colours / days left
 *   POST /media/{id}/hold            login: lock months and put them in the cart {fiscal_year, months[]}
 *   POST /media/{id}/heartbeat       login: page ping while selecting/paying {fiscal_year, months[]} -> keeps the lock
 *   POST /media/{id}/release         login: customer left the pages (sendBeacon) -> frees the lock now
 *   POST /media/{id}/notify          login: join the "tell me when free" queue {fiscal_year, month}
 */
final class MediaController
{
    public static function register(): void
    {
        $ns = RolesController::NS;
        register_rest_route($ns, '/media', [['methods' => 'GET', 'callback' => [self::class, 'index'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/media/(?P<id>\d+)/calendar', [['methods' => 'GET', 'callback' => [self::class, 'calendar'], 'permission_callback' => '__return_true']]);
        register_rest_route($ns, '/media/(?P<id>\d+)/hold', [['methods' => 'POST', 'callback' => [self::class, 'hold'], 'permission_callback' => static fn (): bool => is_user_logged_in()]]);
        register_rest_route($ns, '/media/(?P<id>\d+)/heartbeat', [['methods' => 'POST', 'callback' => [self::class, 'heartbeat'], 'permission_callback' => static fn (): bool => is_user_logged_in()]]);
        register_rest_route($ns, '/media/(?P<id>\d+)/release', [['methods' => 'POST', 'callback' => [self::class, 'release'], 'permission_callback' => static fn (): bool => is_user_logged_in()]]);
        register_rest_route($ns, '/media/(?P<id>\d+)/notify', [['methods' => 'POST', 'callback' => [self::class, 'notify'], 'permission_callback' => static fn (): bool => is_user_logged_in()]]);
    }

    public static function index(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;
        $where = ["status = 'published'"];
        $args = [];
        foreach (['city', 'street', 'neighborhood', 'orientation'] as $f) {
            if ($r->get_param($f) !== null && $r->get_param($f) !== '') {
                $where[] = "{$f} = %s";
                $args[] = sanitize_text_field((string) $r->get_param($f));
            }
        }
        if ($r->get_param('type')) {
            $where[] = 'media_type = %s';
            $args[] = sanitize_text_field((string) $r->get_param('type'));
        }
        if ($r->get_param('lit') !== null && $r->get_param('lit') !== '') {
            $where[] = 'lit = %d';
            $args[] = (int) (bool) $r->get_param('lit');
        }
        if ($r->get_param('q')) {
            $where[] = 'name LIKE %s';
            $args[] = '%' . $wpdb->esc_like(sanitize_text_field((string) $r->get_param('q'))) . '%';
        }
        $sql = "SELECT id, code, name, media_type, width_cm, height_cm, orientation, lit, city, street, neighborhood, lat, lng, monthly_price_rials FROM {$wpdb->prefix}crc_media WHERE "
            . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100';
        return new \WP_REST_Response((array) $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A));
    }

    public static function calendar(\WP_REST_Request $r): \WP_REST_Response
    {
        $year = (int) ($r->get_param('year') ?: 0);
        if ($year < 1400) {
            return new \WP_REST_Response(['error' => 'year_required'], 422);
        }
        return new \WP_REST_Response(['fiscal_year' => $year, 'months' => SlotService::calendar((int) $r['id'], $year, time())]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function hold(\WP_REST_Request $r)
    {
        $b = (array) $r->get_json_params();
        $year = (int) ($b['fiscal_year'] ?? 0);
        try {
            $months = FiscalCalendar::normalizeMonths((array) ($b['months'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('crc_bad_months', 'ماه‌ها نامعتبر است.', ['status' => 422]);
        }
        if ($months === [] || $year < 1400) {
            return new \WP_Error('crc_bad_request', 'سال و ماه لازم است.', ['status' => 422]);
        }
        $uid = get_current_user_id();
        $res = SlotService::lock((int) $r['id'], $year, $months, $uid, time());
        if ($res instanceof \WP_Error) {
            return $res;
        }
        $cartId = CartRepository::getOrCreate(CartRepository::keyFor($uid, ''), $uid);
        CartRepository::addLine($cartId, LineTypes::RENTAL, 0, ['media_id' => (int) $r['id'], 'fiscal_year' => $year, 'months' => $months], count($months));
        return new \WP_REST_Response(['held' => true, 'minutes' => 15, 'months' => $months], 201);
    }

    public static function notify(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;
        $b = (array) $r->get_json_params();
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}crc_media_waitlist (media_id, fiscal_year, month, user_id, created_at) VALUES (%d, %d, %d, %d, %d)",
            (int) $r['id'], (int) ($b['fiscal_year'] ?? 0), (int) ($b['month'] ?? 0), get_current_user_id(), time()));
        return new \WP_REST_Response(['queued' => true], 201);
    }

    public static function heartbeat(\WP_REST_Request $r): \WP_REST_Response
    {
        $b = (array) $r->get_json_params();
        $months = array_filter(array_map('intval', (array) ($b['months'] ?? [])), static fn (int $m): bool => $m >= 1 && $m <= 12);
        $kept = SlotService::heartbeat((int) $r['id'], (int) ($b['fiscal_year'] ?? 0), $months, get_current_user_id(), time());
        return new \WP_REST_Response(['kept' => $kept, 'ok' => $kept === count($months)]);
    }

    public static function release(\WP_REST_Request $r): \WP_REST_Response
    {
        $b = (array) $r->get_json_params();
        $months = array_filter(array_map('intval', (array) ($b['months'] ?? [])), static fn (int $m): bool => $m >= 1 && $m <= 12);
        SlotService::release((int) $r['id'], (int) ($b['fiscal_year'] ?? 0), $months, get_current_user_id());
        return new \WP_REST_Response(['released' => true]);
    }
}
