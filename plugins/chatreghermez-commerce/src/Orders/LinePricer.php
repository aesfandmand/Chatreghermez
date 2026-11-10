<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

use Chatreghermez\Commerce\Catalog\PriceCalculator;
use Chatreghermez\Commerce\Catalog\PricingException;
use Chatreghermez\Commerce\Catalog\ProductRepository;

/**
 * Prices one cart/order line on the server. Standard lines use the catalog; extension line types
 * (e.g. media_rental) are priced through the `crc_price_line` filter, which must return the array below or null.
 */
final class LinePricer
{
    /**
     * @param array<string,mixed> $line line_type, product_id, selection, quantity
     * @return array{name:string,group:string,quantity:int,unit_rials:int,total_rials:int,selection:array<string,mixed>,meta:array<string,mixed>,is_quote:bool}
     * @throws PricingException
     */
    public static function price(array $line): array
    {
        $type = (string) ($line['line_type'] ?? LineTypes::PRODUCT);
        if (!LineTypes::isKnown($type)) {
            throw new PricingException('line_type_unknown');
        }

        if ($type === LineTypes::PRODUCT || $type === LineTypes::SERVICE) {
            $product = ProductRepository::findById((int) $line['product_id']);
            if ($product === null) {
                throw new PricingException('product_unavailable');
            }
            $selection = (array) ($line['selection'] ?? []);
            $selection['quantity'] = (int) ($line['quantity'] ?? 1);
            $quote = PriceCalculator::quote($product, $selection, ProductRepository::optionDefs((int) $product['id']));
            if ($quote->isQuote) {
                throw new PricingException('quote_only_product');
            }
            return [
                'name' => (string) $product['name'], 'group' => (string) $product['category'],
                'quantity' => $quote->quantity, 'unit_rials' => $quote->unit->rials,
                'total_rials' => $quote->total->rials, 'selection' => $selection, 'meta' => [], 'is_quote' => false,
            ];
        }

        $priced = apply_filters('crc_price_line', null, $line);
        if (!is_array($priced)) {
            throw new PricingException('line_not_priced');
        }
        return $priced + ['group' => '', 'meta' => [], 'selection' => (array) ($line['selection'] ?? []), 'is_quote' => false];
    }
}
