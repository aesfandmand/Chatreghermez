<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Support;

/**
 * Immutable money value. Stored and computed ONLY as integer Rials (no floats, no decimals).
 * Toman (1 Toman = 10 Rials) is a display concern.
 */
final class Money
{
    private function __construct(public readonly int $rials)
    {
        if ($rials < 0) {
            throw new \InvalidArgumentException('Money cannot be negative.');
        }
    }

    public static function fromRials(int $rials): self
    {
        return new self($rials);
    }

    public static function fromToman(int $toman): self
    {
        return new self($toman * 10);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function toToman(): int
    {
        return intdiv($this->rials, 10);
    }

    public function add(self $other): self
    {
        return new self($this->rials + $other->rials);
    }

    public function sub(self $other): self
    {
        return new self($this->rials - $other->rials);
    }

    public function mul(int $factor): self
    {
        return new self($this->rials * $factor);
    }

    /** Percent in basis points (500 = 5%), rounded half up to whole Rials. */
    public function percentBp(int $basisPoints): self
    {
        return new self(intdiv($this->rials * $basisPoints + 5000, 10000));
    }

    public function equals(self $other): bool
    {
        return $this->rials === $other->rials;
    }

    public function isZero(): bool
    {
        return $this->rials === 0;
    }

    /** Display in Toman with thousands separators, e.g. "1,234,567". */
    public function formatToman(): string
    {
        return number_format($this->toToman());
    }
}
