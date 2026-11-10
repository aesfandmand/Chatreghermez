<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Catalog;

/** Thrown when a selection cannot be priced. `$errorCode` is a stable machine code. */
final class PricingException extends \DomainException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
