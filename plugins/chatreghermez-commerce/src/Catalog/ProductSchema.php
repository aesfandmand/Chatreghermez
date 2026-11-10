<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Catalog;

/**
 * schema.org Product JSON-LD. Price/availability are emitted only for published, publicly priced products,
 * so price-comparison crawlers (e.g. Torob) and search engines read the same HTML-visible facts.
 * Currency is IRR and amounts are Rials (the unit we store).
 */
final class ProductSchema
{
    /** Lowest advertised unit price in Rials, or null when there is no public price. */
    public static function startingPriceRials(array $product): ?int
    {
        switch ($product['price_model'] ?? '') {
            case 'fixed':
                return (int) ($product['base_price_rials'] ?? 0);
            case 'tiered':
                $prices = array_map(static fn (array $t): int => (int) $t['unit_price_rials'], (array) ($product['tiers'] ?? []));
                return $prices === [] ? null : min($prices);
            case 'area':
                $min = (int) ($product['min_charge_rials'] ?? 0);
                return $min > 0 ? $min : null;
            default:
                return null;
        }
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    public static function jsonLd(array $product, string $url, ?string $imageUrl = null): array
    {
        $d = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => (string) ($product['name'] ?? ''),
            'url'      => $url,
        ];
        if (!empty($product['summary'])) {
            $d['description'] = (string) $product['summary'];
        }
        if ($imageUrl) {
            $d['image'] = $imageUrl;
        }

        $price = self::startingPriceRials($product);
        if ($price !== null && ($product['status'] ?? '') === 'published') {
            $availability = 'https://schema.org/InStock';
            if (($product['price_model'] ?? '') === 'fixed') {
                $d['offers'] = [
                    '@type' => 'Offer', 'priceCurrency' => 'IRR', 'price' => (string) $price,
                    'availability' => $availability, 'url' => $url,
                ];
            } else {
                $d['offers'] = [
                    '@type' => 'AggregateOffer', 'priceCurrency' => 'IRR', 'lowPrice' => (string) $price,
                    'availability' => $availability, 'url' => $url,
                ];
            }
        }
        return $d;
    }
}
