<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Orders;

final class DiscountException extends \DomainException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
