<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Media;

use Chatreghermez\Commerce\Catalog\PricingException;
use Chatreghermez\Commerce\Orders\LineTypes;
use Chatreghermez\Commerce\Support\Money;
use Chatreghermez\MediaRental\Domain\FiscalCalendar;

/** Registers the `media_rental` line type (rental invoice) and prices it server-side from the media's monthly rate. */
final class RentalLine
{
    public static function register(): void
    {
        LineTypes::register(LineTypes::RENTAL, 'rental');
        add_filter('crc_price_line', [self::class, 'price'], 10, 2);
    }

    /** @param mixed $priced @param array<string,mixed> $line @return mixed */
    public static function price($priced, array $line)
    {
        if (($line['line_type'] ?? '') !== LineTypes::RENTAL) {
            return $priced;
        }
        global $wpdb;
        $sel = (array) ($line['selection'] ?? []);
        $mediaId = (int) ($sel['media_id'] ?? 0);
        $year = (int) ($sel['fiscal_year'] ?? 0);
        try {
            $months = FiscalCalendar::normalizeMonths((array) ($sel['months'] ?? []));
        } catch (\InvalidArgumentException $e) {
            throw new PricingException('months_invalid');
        }
        $media = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}crc_media WHERE id = %d AND status = 'published'", $mediaId), ARRAY_A);
        if (!$media || $months === [] || $year < 1400) {
            throw new PricingException('media_unavailable');
        }
        $holder = (int) ($line['holder'] ?? get_current_user_id());
        if (!SlotService::heldBy($mediaId, $year, $months, $holder, time())) {
            throw new PricingException('slot_not_held');
        }
        $unit = Money::fromRials((int) $media['monthly_price_rials']);
        return [
            'name' => 'اجارهٔ ' . $media['name'] . ' — ' . count($months) . ' ماه', 'group' => 'rental:' . $media['city'],
            'quantity' => count($months), 'unit_rials' => $unit->rials, 'total_rials' => $unit->mul(count($months))->rials,
            'selection' => $sel,
            'meta' => ['city' => (string) $media['city'], 'media_id' => $mediaId, 'media_code' => (string) $media['code'], 'fiscal_year' => $year, 'months' => $months],
            'is_quote' => false,
        ];
    }
}
