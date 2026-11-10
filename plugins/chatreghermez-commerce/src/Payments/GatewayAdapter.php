<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Payments;

/** Provider-agnostic online gateway. Verification is always server-to-server; the browser callback is never trusted. */
interface GatewayAdapter
{
    public function id(): string;

    /** @return array{ref:string,redirect_url:string} gateway reference + where to send the customer */
    public function initiate(int $amountRials, string $orderNo, string $callbackUrl): array;

    /** @return array{paid:bool,amount_rials:int,ref:string,receipt:string} confirmed by the gateway itself */
    public function verify(string $ref): array;
}
