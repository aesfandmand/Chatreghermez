<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

use Chatreghermez\Commerce\Orders\LineTypes;

/** Splits order lines into invoices: one per invoice kind; rental additionally one per city (meta.city). */
final class InvoiceSplitter
{
    /**
     * @param list<array<string,mixed>> $lines line_type, total_rials, meta(array, optional city)
     * @return list<array{kind:string,city:string,lines:list<array<string,mixed>>,total_rials:int}>
     */
    public static function split(array $lines): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $kind = LineTypes::invoiceKind((string) $l['line_type']);
            $city = $kind === 'rental' ? (string) (($l['meta']['city'] ?? '') ?: '') : '';
            $key  = $kind . '|' . $city;
            $groups[$key] ??= ['kind' => $kind, 'city' => $city, 'lines' => [], 'total_rials' => 0];
            $groups[$key]['lines'][] = $l;
            $groups[$key]['total_rials'] += (int) $l['total_rials'];
        }
        ksort($groups);
        return array_values($groups);
    }
}
