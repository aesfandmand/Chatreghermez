<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Catalog;

use Chatreghermez\Commerce\Support\Money;

/** Result of pricing one product selection. `isQuote` = no online price (inquiry-only). */
final class PriceQuote
{
    /** @param array<string,int> $breakdown rials by step, for display/audit */
    public function __construct(
        public readonly bool $isQuote,
        public readonly Money $unit,
        public readonly int $quantity,
        public readonly Money $total,
        public readonly array $breakdown = []
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'is_quote'     => $this->isQuote,
            'unit_rials'   => $this->unit->rials,
            'quantity'     => $this->quantity,
            'total_rials'  => $this->total->rials,
            'total_toman'  => $this->total->toToman(),
            'breakdown'    => $this->breakdown,
        ];
    }
}
